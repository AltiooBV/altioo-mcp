<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;
use Altioo\iTop\Extension\MCP\Service\Rights\SecretPolicy;

use AttributeCaseLog;
use AttributeDate;
use AttributeDateTime;
use AttributeDefinition;
use MetaModel;
use UserRights;

/**
 * The attributes of a class, described for a caller that has to read or write them.
 *
 * Each attribute's type, whether it is mandatory or writable, the values it
 * accepts, what it depends on, what the caller is told about writing it, and
 * what the caller's rights on it are - the `attributes` block of
 * {@see DatamodelReader::Describe()} - plus the hint a refused value is answered
 * with ({@see self::ValueHint()}). Attributes the exclusions of ADR 0012 name are
 * absent, and a secret is described without its value.
 *
 * @internal
 * @since 2.0.0
 */
final class AttributeDescription
{
	/**
	 * Most values reported for one attribute. A datamodel enumeration never
	 * comes close; anything that does is a list the model should be searching,
	 * not reading.
	 */
	public const MAX_ALLOWED_VALUES = 100;

	/**
	 * The attributes of $sClass this caller may read.
	 *
	 * Filtered by the same right the object tools apply, for two reasons. The
	 * schema is what a model builds its next call from, so listing an attribute
	 * it may not read sends it to ask for something that comes back missing:
	 * ObjectSerializer skips unreadable attributes silently, and a model reads
	 * an absent field as an empty one rather than as a refusal. And a label, a
	 * description and an enumeration of allowed values say a good deal about a
	 * field even when none of its values are ever returned.
	 *
	 * UR_ALLOWED_DEPENDS keeps the attribute: it means the answer varies by
	 * object, and the object tools decide it per object. Only an outright
	 * refusal for the whole class removes it here.
	 *
	 * readOnly and modify are two keys on purpose. readOnly is the datamodel's
	 * answer - the attribute is computed or structural, nobody writes it, and
	 * no administrator can grant it. modify is this caller's answer, and an
	 * administrator can change it. Collapsed into one key a model can no longer
	 * tell "nobody may write this" from "you may not", and reports the wrong
	 * one of the two to the user - the second is worth raising with whoever
	 * grants the rights, the first never is.
	 *
	 * Unlike the class-level gate, this one is close to final under a stock
	 * install: iTop's shipped addon documents that it ignores the instance set
	 * for attributes, so there is no per-object answer waiting behind it. A
	 * datamodel that does grade per object says so with 'depends'.
	 *
	 * @param string $sClass The class for which to retrieve attribute details
	 * @return array An array of attribute details
	 */
	public static function attributes(string $sClass): array
	{
		$aAttributes = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO
				|| ExclusionPolicy::ExcludesAttribute($sClass, (string)$sAttCode)) {
				continue;
			}

			// The write right, which is the one a model gets wrong: it reads a
			// schema, picks an attribute off it and is refused at write time.
			// UR_ACTION_MODIFY is the right for both paths - ObjectCreate gates
			// a field it is about to set on the same action ObjectUpdate does -
			// so one answer covers creating and updating alike.
			$iModify = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY);

			$aAttributes[$sAttCode] = [
				'label'         => MetaModel::GetLabel($sClass, $sAttCode),
				'description'   => $oAttDef->GetDescription(),
				'type'          => get_class($oAttDef),
				'format'        => AttributeFormat::format($oAttDef),
				'pattern'       => AttributeFormat::pattern($oAttDef),
				'required'      => $oAttDef->IsNullAllowed() === false,
				'readOnly'      => $oAttDef->IsWritable() === false,
				'modify'        => GateGrades::grade($iModify),
				'nullable'      => $oAttDef->IsNullAllowed(),
				'isExternalKey' => $oAttDef->IsExternalKey(),
				'isScalar'      => $oAttDef->IsScalar(),
				'isSensible'    => ObjectSerializer::IsSensitive($oAttDef, $sClass),
			] + self::allowedValues($oAttDef) + self::dependsOn($oAttDef, $sClass) + self::writeHint($oAttDef);
		}

		return $aAttributes;
	}

	/**
	 * What a client may put in an attribute, when that is a list worth sending.
	 *
	 * An enumeration is a handful of codes declared in the datamodel, and a
	 * model that cannot see them invents them - so those are reported in full.
	 *
	 * An external key is not. GetAllowedValues() on one runs a query over the
	 * whole target table and returns every row: on ticket.caller_id that is the
	 * entire Person table, in a payload the caller pays for on every schema
	 * read, describing objects the object-level rights of the calling user were
	 * never consulted about. The target class is reported instead, which is
	 * what a model needs to go and search it.
	 *
	 * Anything else that declares values is capped, because nothing in the
	 * datamodel promises a short list.
	 *
	 * @return array<string, mixed>
	 */
	private static function allowedValues(AttributeDefinition $oAttDef): array
	{
		if ($oAttDef->IsExternalKey()) {
			return [
				'values'      => null,
				'targetClass' => $oAttDef->GetTargetClass(),
				'valuesHint'  => 'Search the target class for the object you want, then pass its id.',
			];
		}

		$aValues = $oAttDef->GetAllowedValues();
		if (!is_array($aValues) || count($aValues) <= self::MAX_ALLOWED_VALUES) {
			return ['values' => self::codeKeyed($aValues)];
		}

		return [
			'values'          => self::codeKeyed(array_slice($aValues, 0, self::MAX_ALLOWED_VALUES, true)),
			'valuesTruncated' => true,
			'valuesTotal'     => count($aValues),
		];
	}

	/**
	 * The allowed values as a map, whatever iTop keyed them with.
	 *
	 * iTop answers code => label, and for most attributes the codes are
	 * strings, so the payload carries an object and a caller reads the key it
	 * has to send. A stopwatch sub-item does not: AttributeStopWatch's
	 * GetSubItemAllowedValues() returns [0 => label, 1 => label], PHP calls
	 * that a list, and json_encode drops the keys - so sla_tto_passed arrived
	 * as ["no","yes"]. What survived was GetBooleanLabel(), which resolves
	 * through the dictionary: the *labels*, localised, with the 0 and 1 that
	 * are actually stored gone. On a French instance the same read answers
	 * ["non","oui"].
	 *
	 * Nothing writes those - a sub-item is computed, so it is reported under
	 * `derived` - but a search reads them, and "WHERE sla_tto_passed = 'no'"
	 * filters on a string the column never holds.
	 *
	 * Casting to an object is what keeps the keys: an array cast alone does
	 * not, because PHP folds a numeric-string key straight back to an int.
	 * An attribute whose values are already string-keyed encodes identically
	 * either way, so one shape covers both and a caller has one thing to
	 * parse.
	 *
	 * @param mixed $mValues As GetAllowedValues() answered: a map, or null.
	 */
	private static function codeKeyed(mixed $mValues): mixed
	{
		return is_array($mValues) ? (object) $mValues : $mValues;
	}

	/**
	 * The value iTop would have accepted, when the refusal is only a format.
	 *
	 * A date-time here is `Y-m-d H:i:s` - a space, no offset - and every other
	 * system a model has met uses RFC 3339, so the ISO form is what a first
	 * attempt sends. The schema already carries the pattern, and a refusal
	 * still costs a round trip to read it; this spends one strtotime() to put
	 * the corrected string in the refusal itself.
	 *
	 * Suggested, never applied. An offset-bearing value is an instant, iTop
	 * stores wall-clock time in the instance's own zone, and silently moving a
	 * timestamp by an hour is a worse failure than the refusal it replaced -
	 * so the converted value is shown, and the caller sends it or does not.
	 *
	 * Returns '' when there is nothing useful to say, which is most refusals:
	 * a value that is not a string, an attribute that is not a date, or a
	 * string no date parser recognises.
	 *
	 * @param mixed $value As the caller sent it.
	 *
	 * @since 1.0.0
	 */
	public static function ValueHint(string $sClass, string $sAttCode, mixed $value): string
	{
		if (!is_string($value) || $value === '') {
			return '';
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		} catch (\Throwable $e) {
			return '';
		}

		if (!$oAttDef instanceof AttributeDateTime) {
			// AttributeDate extends it, so this covers both.
			return '';
		}

		$sFormat = $oAttDef::GetInternalFormat();
		$iTimestamp = strtotime($value);
		if ($iTimestamp === false) {
			return '';
		}

		$sCorrected = date($sFormat, $iTimestamp);
		if ($sCorrected === $value) {
			// Already in the right shape; whatever iTop refused, it was not
			// the format.
			return '';
		}

		return sprintf(
			' iTop stores this attribute as %s, not RFC 3339: send "%s".',
			$sFormat,
			$sCorrected
		);
	}

	/**
	 * The attributes this one is computed from, where the datamodel says so.
	 *
	 * The gap this closes: a UserRequest's priority is writable, mandatory,
	 * and reported as both - and every value a caller sets is thrown away,
	 * because ComputeValues() derives it from urgency and impact on every
	 * write. A model reading the schema literally, which is what this tool
	 * tells it to do, sets priority and finds out afterwards from the
	 * `overridden` block on the write. The schema knew and did not say.
	 *
	 * Read rather than listed: the declaration is
	 * <dependencies><attribute id="impact"/>... in the class XML, compiled to
	 * depends_on and answered by GetPrerequisiteAttributes(). So a datamodel
	 * that derives something else, in a pack nobody here has seen, reports it
	 * the same way - and a class that stops deriving it stops saying so
	 * without anyone editing this file.
	 *
	 * A declared dependency is not proof that the value is recomputed - the
	 * recomputation is the class's own PHP, and the declaration is what the
	 * console uses to refresh the field - so the wording says what is
	 * declared, and the write still reports what actually survived.
	 *
	 * @return array<string, mixed> Empty for an attribute that depends on nothing.
	 */
	private static function dependsOn(AttributeDefinition $oAttDef, string $sClass): array
	{
		try {
			$aPrerequisites = $oAttDef->GetPrerequisiteAttributes($sClass);
		} catch (\Throwable $e) {
			return [];
		}

		if (!is_array($aPrerequisites) || $aPrerequisites === []) {
			return [];
		}

		// An excluded attribute is not named here either.
		return ['dependsOn' => array_values(array_filter(
			$aPrerequisites,
			static fn ($mCode): bool => is_string($mCode) && !ExclusionPolicy::ExcludesAttribute($sClass, $mCode)
		))];
	}

	/**
	 * How to write an attribute that is not written the way it reads.
	 *
	 * Most attributes take back what a read returned. A case log does not: it
	 * reads as the log and is written one entry at a time, so a type name is
	 * the whole of what a model is told and neither of the two accepted shapes
	 * is in it. Left to work that out, the reasonable guesses are sending the
	 * rendered log back as the new value - which is the log twice - or looking
	 * for an add-a-log-entry tool, which this module deliberately does not
	 * ship: a work note is task-shaped, and task-shaped tools belong to a pack.
	 *
	 * Reported per attribute rather than in the write tools' descriptions,
	 * because it is a property of the attribute in front of the caller and
	 * because the schema is where a model already goes for the attribute code.
	 * {@see RestValue} is what makes both shapes arrive intact.
	 *
	 * The append is what the source does, and what an instance was watched
	 * doing. `AttributeCaseLog::FromJSONToValue()` passes a string straight
	 * through and takes `add_item` as the entry, refusing it without a
	 * `message`; `MakeRealValue()` then clones the log already on the object
	 * and appends to that clone, which is where "keeps the entries already
	 * there" comes from. Two successive plain-string writes on a live instance
	 * left two entries with the first intact, and the dry run reported the same
	 * shape the real write then produced - worth confirming for this type in
	 * particular, since a case log is where a simulated diff could plausibly
	 * disagree with the real path. It did not.
	 *
	 * @return array<string, string> Empty for a type that needs no hint.
	 */
	private static function writeHint(AttributeDefinition $oAttDef): array
	{
		if ($oAttDef instanceof AttributeCaseLog) {
			return [
				'writeHint' => 'Write the new entry, not the whole log: pass its text as a plain string, or as {"add_item": {"message": "..."}}. iTop adds it to the log and keeps the entries already there.',
			];
		}

		// Said here rather than only at write time: modify answers for the
		// caller's profile, which this refusal does not consult.
		$sSecretRefusal = SecretPolicy::RefusalGiven($oAttDef, $oAttDef->GetCode(), Settings::AllowsPrivilegeEscalation());
		if ($sSecretRefusal !== null) {
			return ['writeHint' => $sSecretRefusal];
		}

		return [];
	}
}
