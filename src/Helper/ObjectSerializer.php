<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeBlob;
use AttributeDefinition;
use DBObject;
use Mcp\Exception\ToolCallException;
use MetaModel;
use Throwable;
use UserRights;
use iAttributeNoGroupBy;

/**
 * One object, rendered the way iTop's own REST API renders it.
 *
 * Every tool that returns objects goes through here, for two reasons.
 *
 * The first is correctness. DBObject::Get() returns whatever internal
 * representation the attribute uses - an ormCaseLog for a log, an ormLinkSet
 * for a link set, an ormDocument for an attachment - and none of those
 * implement JsonSerializable, nor expose a single public property. Handing one
 * to json_encode() yields "{}": the ticket log, the CI list and the attachment
 * all arrive empty, silently, with a 200. AttributeDefinition::GetForJSON() is
 * the conversion iTop wrote for exactly this, and is what
 * ObjectResult::MakeResultValue() calls on the REST side.
 *
 * The second is that a response feeds a context window. Two attribute kinds
 * are unbounded by nature and are handled here rather than left to GetForJSON:
 * a blob, whose JSON form embeds the whole file base64-encoded, is reported as
 * its metadata alone.
 */
final class ObjectSerializer
{
	/** What a sensitive attribute reads as, whatever its type. */
	private const MASK = '***';

	/** What a caller asks for to get every readable attribute. */
	public const ALL_FIELDS = '*';

	/**
	 * What a list of objects reports when the caller says nothing, the same
	 * default ObjectResult::FromDBObject() applies on the REST side.
	 */
	public const DEFAULT_LIST_FIELDS = 'id, friendlyname';

	/**
	 * The attributes of $oObject the caller may read.
	 *
	 * @param string             $sClass  The object's final class - the one whose attributes are enumerated.
	 * @param array<int, string>|null $aFields Attribute codes to report; null for all of them.
	 *
	 * @return array<string, mixed>
	 */
	public static function Serialize(DBObject $oObject, string $sClass, ?array $aFields = null): array
	{
		$aData = [MetaModel::DBGetKey($sClass) => $oObject->GetKey()];

		// A root class with no subclass declares no final class field, and
		// keying the response on '' is not a way to say so.
		$sClassField = MetaModel::DBGetClassField($sClass);
		if ($sClassField !== '') {
			$aData[$sClassField] = $sClass;
		}

		$aUnreadable = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if ($aFields !== null && !in_array($sAttCode, $aFields, true)) {
				continue;
			}
			if (!UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ)) {
				continue;
			}

			try {
				$aData[$sAttCode] = self::Value($oObject, $sClass, $sAttCode);
			} catch (Throwable $e) {
				// One attribute that cannot be rendered - a dangling external
				// field, a document whose file is gone - must not cost the
				// caller the other forty.
				$aData[$sAttCode] = null;
				$aUnreadable[] = $sAttCode;
			}
		}

		if (!empty($aUnreadable)) {
			$aData['_unreadable_attributes'] = $aUnreadable;
		}

		return $aData;
	}

	/**
	 * One attribute, in its JSON form.
	 *
	 * @return mixed A scalar, or a structure of scalars; never an ORM object.
	 */
	public static function Value(DBObject $oObject, string $sClass, string $sAttCode): mixed
	{
		if ($sAttCode === 'id') {
			return $oObject->GetKey();
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

		if ($oAttDef instanceof iAttributeNoGroupBy) {
			// iAttributeNoGroupBy is how the datamodel marks an attribute as
			// sensitive. Masked before any conversion, so no code path can
			// read the real value into the response.
			return self::MASK;
		}

		if ($oAttDef instanceof AttributeBlob) {
			// GetForJSON() would base64 the whole file into the response.
			return self::document($oObject->Get($sAttCode));
		}

		return $oAttDef->GetForJSON($oObject->Get($sAttCode));
	}

	/**
	 * A document reported by what it is, never by what it contains.
	 *
	 * @param mixed $value An ormDocument, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function document($value): ?array
	{
		if (!is_object($value) || !method_exists($value, 'IsEmpty') || $value->IsEmpty()) {
			return null;
		}

		return [
			'filename' => $value->GetFileName(),
			'mimetype' => $value->GetMimeType(),
			'size'     => strlen((string)$value->GetData()),
		];
	}

	/**
	 * Turns an output_fields argument into the list Serialize() takes.
	 *
	 * Returning every attribute of every object is what makes a search
	 * unusable: fifty tickets with forty attributes each, most of them
	 * irrelevant to the question asked, is a payload no context window
	 * survives - and the caller pays for all of it. iTop's REST API answers
	 * this with output_fields, defaulting to id and friendlyname, and the
	 * spelling is deliberately the same one here.
	 *
	 * An unknown attribute code is refused rather than ignored: a model that
	 * asked for "assignee" on a class that calls it agent_id has to be told,
	 * or it reads the absence as "this ticket has no assignee".
	 *
	 * @return array<int, string>|null The attribute codes to report, or null for all of them.
	 *
	 * @throws ToolCallException When a code is not an attribute of the class.
	 */
	public static function ParseFieldList(string $sClass, string $sOutputFields): ?array
	{
		$sOutputFields = trim($sOutputFields);
		if ($sOutputFields === '' || $sOutputFields === self::ALL_FIELDS) {
			return null;
		}

		$aFields = [];
		$aUnknown = [];

		foreach (explode(',', $sOutputFields) as $sAttCode) {
			$sAttCode = trim($sAttCode);
			if ($sAttCode === '') {
				continue;
			}
			if ($sAttCode === 'id') {
				// Always reported anyway, and not an attribute of the class.
				continue;
			}
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				$aUnknown[] = $sAttCode;
				continue;
			}

			$aFields[] = $sAttCode;
		}

		if (!empty($aUnknown)) {
			throw new ToolCallException(sprintf(
				"Unknown attribute%s %s on class '%s'. Call core_ClassSchema for the attribute codes of the class.",
				count($aUnknown) > 1 ? 's' : '',
				"'".implode("', '", $aUnknown)."'",
				$sClass
			));
		}

		return $aFields;
	}

	/**
	 * The input schema property both the search tools and ObjectGet declare,
	 * so that one argument does not end up with two spellings.
	 *
	 * @return array<string, mixed>
	 */
	public static function FieldsSchemaProperty(string $sDefault): array
	{
		return [
			'type'        => 'string',
			'description' => 'Comma-separated attribute codes to return, e.g. "title, status, agent_id". "'.self::ALL_FIELDS.'" returns every readable attribute, which is large - ask for what you need. Call core_ClassSchema for the codes. The id is always returned.',
			'default'     => $sDefault,
		];
	}

	/**
	 * Whether an attribute definition is one this serializer masks outright.
	 *
	 * Exposed so that the schema tools can describe an attribute the same way
	 * the object tools return it.
	 */
	public static function IsSensitive(AttributeDefinition $oAttDef): bool
	{
		return $oAttDef instanceof iAttributeNoGroupBy;
	}
}
