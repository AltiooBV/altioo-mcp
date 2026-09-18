<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeBlob;
use AttributeCaseLog;
use AttributeDefinition;
use AttributeExternalField;
use AttributeLinkedSet;
use DBObject;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use ormDocument;
use Throwable;
use UserRights;
use utils;
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
 * The second is that a response feeds a context window. Four attribute kinds
 * have no natural size and are handled here rather than left to GetForJSON: a
 * blob, whose JSON form embeds the whole file base64-encoded, is reported as
 * its metadata and the URI that serves it; a case log, a link set and a long
 * text are cut to a ceiling that the value itself declares.
 *
 * None of those ceilings applies when the caller named the attributes it wants
 * through output_fields. Asking for one attribute by name is the decision to
 * read it in full; the ceilings are there so that a broad read cannot spend a
 * context window nobody asked it to spend.
 *
 * @api
 * @since 1.0.0
 */
final class ObjectSerializer
{
	/**
	 * What a sensitive attribute reads as, whatever its type.
	 *
	 * The same five stars core/restservices.class.inc.php writes, so that a
	 * masked value coming out of MCP is indistinguishable from one coming out
	 * of REST - a difference in the mask is a way to tell the two apart, and
	 * nothing gains by it.
	 */
	public const MASK = '*****';

	/** What a caller asks for to get every readable attribute. */
	public const ALL_FIELDS = '*';

	/**
	 * Ceilings on the three attribute kinds that have no natural size.
	 *
	 * None of them is a limit on what the caller may read: each one says how it
	 * was cut, and naming the attribute in output_fields returns it whole.
	 */
	public const MAX_TEXT_CHARS = 4000;
	public const MAX_CASELOG_ENTRIES = 10;
	public const MAX_LINKS = 50;

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
	 * @since 1.0.0
	 */
	public static function Serialize(DBObject $oObject, string $sClass, ?array $aFields = null): array
	{
		// WritePlan::AsId(), because a write already reports the id through it and
		// a read that answers "4" where a create answered 4 is one surface with
		// two types in it - the caller that pastes the first into the second is
		// the caller this module has already had refused by its own schema.
		$aData = [MetaModel::DBGetKey($sClass) => WritePlan::AsId($oObject->GetKey())];

		// A root class with no subclass declares no final class field, and
		// keying the response on '' is not a way to say so.
		$sClassField = MetaModel::DBGetClassField($sClass);
		if ($sClassField !== '') {
			$aData[$sClassField] = $sClass;
		}

		$aUnreadable = [];

		// Built on first use and only when some attribute answers DEPENDS, so
		// an install whose addon never does - the shipped one never does - pays
		// nothing for the query.
		$oInstanceSet = null;

		// Whether this object is archived, on every read that returns it.
		//
		// Archived is soft-deleted: the object is out of circulation but still
		// there, and a search made under archive mode - which this endpoint
		// reaches, since iTop reads `with_archive` through utils::ReadParam -
		// returns archived objects beside live ones. The default field list is
		// id and friendlyname, which describe both identically.
		//
		// Not conditional on the mode. Whether the caller can currently *see*
		// archived objects and whether the object in hand *is* one are two
		// questions, and answering the second only while the answer is
		// interesting is how a payload teaches a reader to stop looking.
		$aFields = self::withArchiveFlag($sClass, $aFields);

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if ($aFields !== null && !in_array($sAttCode, $aFields, true)) {
				continue;
			}
			if (!self::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)) {
				continue;
			}

			try {
				// Naming the attributes is itself the decision to read them in
				// full: the ceilings exist to keep a broad read from spending
				// a context window that nobody asked it to spend.
				$aData[$sAttCode] = self::Value($oObject, $sClass, $sAttCode, $aFields === null);
			} catch (Throwable $e) {
				// One attribute that cannot be rendered - a dangling external
				// field, a document whose file is gone - must not cost the
				// caller the other forty.
				$aData[$sAttCode] = null;
				$aUnreadable[] = $sAttCode;
			}
		}

		// Three states, because there are three answers.
		//
		// true and false are iTop's own, on a class that declares the flag. A
		// class that declares none is not "not archived": archiving is a
		// property of a class hierarchy, and a Person or a Team has no such
		// notion at all - reporting false there would be answering a question
		// the datamodel never asked, and a caller filtering on it would drop
		// objects that were never candidates. null says the question does not
		// apply here, the same way the lifecycle block answers null for a class
		// with no states rather than an empty list of transitions.
		//
		// Never overwrites what the loop found, so an attribute the caller may
		// not read keeps whatever the rights layer decided about it.
		if (!array_key_exists(self::ARCHIVE_FLAG, $aData) && !self::HasArchiveFlag($sClass)) {
			$aData[self::ARCHIVE_FLAG] = null;
		}

		if (!empty($aUnreadable)) {
			$aData['_unreadable_attributes'] = $aUnreadable;
		}

		return $aData;
	}

	/**
	 * What this caller may do to this particular object, as the gates answer
	 * it with the object in hand.
	 *
	 * The class-level block core_class_schema reports says whether the gate
	 * opens at all; where it answers 'depends', it is the addon asking to be
	 * shown the object before it decides. A read already has the object, so
	 * this is where that question can actually be settled - and settling it is
	 * the difference between an agent planning a write it can make and one
	 * discovering the refusal by making it.
	 *
	 * Only the class-level 'depends' costs anything. 'yes' and 'no' are
	 * answers the addon gave without reference to any object, so they are
	 * carried straight through rather than asked again per row.
	 *
	 * Read as a gate and as a snapshot, which is what it is: it clears the
	 * rights layer and nothing beyond it. A lifecycle state, the datamodel's
	 * own DoCheckToWrite(), a read-only database, or another user getting
	 * there first can each still refuse the write this reports as available.
	 *
	 * @param array<string, string>|null $aClassGrades DatamodelReader::RightsOf() for the class, read once per class per page.
	 * @param DBObjectSet|null           $oInstanceSet Reused across gates of one object; created here on first need.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public static function RightsOn(DBObject $oObject, string $sClass, ?array $aClassGrades, ?DBObjectSet &$oInstanceSet): array
	{
		$aClassGrades ??= DatamodelReader::RightsOf($sClass);
		$aActions = [
			'modify'     => UR_ACTION_MODIFY,
			'bulkModify' => UR_ACTION_BULK_MODIFY,
			'delete'     => UR_ACTION_DELETE,
			'bulkDelete' => UR_ACTION_BULK_DELETE,
		];

		$aGrades = [];
		foreach (DatamodelReader::OBJECT_RIGHTS_KEYS as $sKey) {
			$sClassGrade = $aClassGrades[$sKey] ?? 'depends';
			if ($sClassGrade !== 'depends') {
				$aGrades[$sKey] = $sClassGrade;
				continue;
			}

			$iKey = (int)$oObject->GetKey();
			if ($iKey < 1) {
				// Not in the database to be asked about, so the question cannot
				// be resolved and the answer stays what the class said.
				$aGrades[$sKey] = 'depends';
				continue;
			}

			$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));
			$iAllowed = UserRights::IsActionAllowed($sClass, $aActions[$sKey], $oInstanceSet);
			$aGrades[$sKey] = match ((int)$iAllowed) {
				UR_ALLOWED_NO => 'no',
				UR_ALLOWED_YES => 'yes',
				default => 'depends',
			};
		}

		return $aGrades;
	}

	/**
	 * The stimuli this object will actually accept right now, and whether this
	 * caller may apply them.
	 *
	 * core_class_schema reports the whole lifecycle graph: every state and
	 * every transition out of it. What that cannot say is which of them apply
	 * to the object in front of you, because that depends on the state it is
	 * in - so a model reading the graph has to find the state attribute, match
	 * it against the states, and hope it picked the right attribute. A read
	 * has the object, so it can simply say.
	 *
	 * Two gates decide 'allowed', and the stricter wins, because
	 * core_object_apply_stimulus checks both: UR_ACTION_MODIFY on the object,
	 * and the stimulus itself through UserRights::IsStimulusAllowed(). A
	 * transition offered here with 'yes' is one whose rights are settled; it
	 * is still not a promise, since the datamodel's own DoCheckToWrite() and
	 * whatever the transition requires of mandatory attributes are checked
	 * when the write is attempted and not before.
	 *
	 * Null for a class with no lifecycle, which is most of the CMDB - an empty
	 * list would read as "this ticket is stuck", which is a different claim.
	 *
	 * @param array<string, string>|null $aObjectRights {@see RightsOn()} for this object, when it has already been read.
	 * @param DBObjectSet|null           $oInstanceSet  Reused across gates of one object; created here on first need.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	public static function StimuliOn(DBObject $oObject, string $sClass, ?array $aObjectRights, ?DBObjectSet &$oInstanceSet): ?array
	{
		if (!MetaModel::HasLifecycle($sClass)) {
			return null;
		}

		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($sStateAttCode === '') {
			return null;
		}

		$sState = (string)$oObject->Get($sStateAttCode);
		$sModify = $aObjectRights['modify'] ?? self::RightsOn($oObject, $sClass, null, $oInstanceSet)['modify'];

		$aStimuli = MetaModel::EnumStimuli($sClass);
		$aAvailable = [];
		foreach (MetaModel::EnumTransitions($sClass, $sState) as $sStimulusCode => $aTransitionDef) {
			// Both halves, not just the stricter one. Every entry here is a
			// transition the datamodel declares out of the current state, so a
			// 'no' is always a rights answer - but which of the two rights is
			// the difference between "nobody may drive this transition by hand"
			// and "this account may not modify this object at all", and a
			// caller told only 'no' reads the first as the second and reports
			// the object as stuck. ev_timeout is the case in hand: the
			// lifecycle offers it, no profile grants it, and the object is
			// otherwise perfectly writable.
			$sStimulus = self::stimulusGrade($oObject, $sClass, $sStimulusCode, $oInstanceSet);
			$aAvailable[] = [
				'stimulus'     => $sStimulusCode,
				'label'        => isset($aStimuli[$sStimulusCode]) ? $aStimuli[$sStimulusCode]->GetLabel() : $sStimulusCode,
				'target_state' => $aTransitionDef['target_state'] ?? null,
				'allowed'      => self::stricterGrade($sModify, $sStimulus),
				'gates'        => [
					'modify'   => $sModify,
					'stimulus' => $sStimulus,
				],
			];
		}

		return [
			'state_attribute' => $sStateAttCode,
			'state'           => $sState,
			'available'       => $aAvailable,
		];
	}

	/**
	 * One stimulus gate, graded like every other.
	 *
	 * IsStimulusAllowed() is declared without a return type and the shipped
	 * addon answers with a bool, so the cast reads both: true and false land
	 * on UR_ALLOWED_YES and UR_ALLOWED_NO, which are 1 and 0.
	 */
	private static function stimulusGrade(DBObject $oObject, string $sClass, string $sStimulusCode, ?DBObjectSet &$oInstanceSet): string
	{
		$iKey = (int)$oObject->GetKey();
		if ($iKey > 0) {
			$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));
		}

		$mAllowed = UserRights::IsStimulusAllowed($sClass, $sStimulusCode, $iKey > 0 ? $oInstanceSet : null);

		return match ((int)$mAllowed) {
			UR_ALLOWED_NO => 'no',
			UR_ALLOWED_YES => 'yes',
			default => 'depends',
		};
	}

	/** 'no' ends the call on its own; 'depends' leaves the pair unsettled. */
	private static function stricterGrade(string $sLeft, string $sRight): string
	{
		if ($sLeft === 'no' || $sRight === 'no') {
			return 'no';
		}

		return ($sLeft === 'depends' || $sRight === 'depends') ? 'depends' : 'yes';
	}

	/** iTop's own flag for a soft-deleted object, on the classes that declare one. */
	private const ARCHIVE_FLAG = 'archive_flag';

	/**
	 * The archive flag, added to a narrowed field list.
	 *
	 * A caller that asked for every attribute already has it, and one that
	 * named it already has it. This is for the default - id and friendlyname -
	 * which describes an archived object and a live one identically.
	 *
	 * Guarded, because a read must not fail over a question about the class.
	 *
	 * @param array<int, string>|null $aFields Null means every attribute, which already includes the flag.
	 *
	 * @return array<int, string>|null
	 */
	private static function withArchiveFlag(string $sClass, ?array $aFields): ?array
	{
		if ($aFields === null || in_array(self::ARCHIVE_FLAG, $aFields, true)) {
			return $aFields;
		}

		if (!self::HasArchiveFlag($sClass)) {
			return $aFields;
		}

		$aFields[] = self::ARCHIVE_FLAG;

		return $aFields;
	}

	/**
	 * Whether this class has an archived state at all.
	 *
	 * Guarded: a question about the datamodel must not cost the read that
	 * asked it, and answering "no" leaves the payload exactly as it was before
	 * the flag existed.
	 */
	private static function HasArchiveFlag(string $sClass): bool
	{
		try {
			return MetaModel::IsValidAttCode($sClass, self::ARCHIVE_FLAG);
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Whether this caller may read this attribute of this object.
	 *
	 * IsActionAllowedOnAttribute() is tri-state. Read as a boolean,
	 * UR_ALLOWED_DEPENDS is 2 and therefore truthy, so an addon saying "ask me
	 * again with the object" was being read as "yes" - and every attribute it
	 * grades per object was served on every read. The shipped addon never
	 * answers DEPENDS for attributes, so nothing changes on a stock install;
	 * under one that does, this is the difference between honouring its answer
	 * and ignoring it.
	 *
	 * The instance set is built once per object and only if some attribute
	 * actually answers DEPENDS, which keeps the query off the common path.
	 *
	 * @param DBObjectSet|null $oInstanceSet Reused across attributes of one object; created here on first need.
	 */
	public static function MayReadAttribute(DBObject $oObject, string $sClass, string $sAttCode, ?DBObjectSet &$oInstanceSet): bool
	{
		$iAllowed = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ);

		if ($iAllowed === UR_ALLOWED_YES) {
			return true;
		}
		if ($iAllowed === UR_ALLOWED_NO) {
			return false;
		}

		// DEPENDS, and the object is what it depends on. An object with no key
		// is not in the database to be asked about, so the question cannot be
		// resolved and the answer stays no.
		$iKey = (int)$oObject->GetKey();
		if ($iKey < 1) {
			return false;
		}

		$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));

		return UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ, $oInstanceSet) === UR_ALLOWED_YES;
	}

	/**
	 * One attribute, in its JSON form.
	 *
	 * @param bool $bClip Whether the unbounded kinds are cut to their ceilings.
	 *
	 * @return mixed A scalar, or a structure of scalars; never an ORM object.
	 * @since 1.0.0
	 */
	public static function Value(DBObject $oObject, string $sClass, string $sAttCode, bool $bClip = true): mixed
	{
		if ($sAttCode === 'id') {
			return WritePlan::AsId($oObject->GetKey());
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

		if (self::IsSensitive($oAttDef)) {
			// Masked before any conversion, so no code path can read the real
			// value into the response.
			return self::MASK;
		}

		if ($oAttDef instanceof AttributeBlob) {
			// GetForJSON() would base64 the whole file into the response.
			return self::document($oObject->Get($sAttCode), $sClass, (int)$oObject->GetKey(), $sAttCode);
		}

		$value = $oAttDef->GetForJSON($oObject->Get($sAttCode));

		if ($oAttDef instanceof AttributeLinkedSet) {
			// Before anything else looks at it, and regardless of $bClip:
			// output_fields decides how much of an object is reported, never
			// what may be read at all.
			$value = self::maskSensitiveLinks($value, $oAttDef);
		}

		if (!$bClip) {
			return $value;
		}

		if ($oAttDef instanceof AttributeCaseLog) {
			return self::clip(self::recentEntries($value));
		}

		if ($oAttDef instanceof AttributeLinkedSet) {
			return self::clip(self::firstLinks($value));
		}

		return self::clip($value);
	}

	/**
	 * The tail of a case log, which is the part anyone asks about.
	 *
	 * A five-year-old incident carries hundreds of entries and reaches the
	 * caller in full. GetForJSON() returns them oldest first, so the recent
	 * ones - the state of the conversation now - are at the end.
	 *
	 * @param mixed $value As AttributeCaseLog::GetForJSON() returns it.
	 *
	 * @return mixed
	 */
	private static function recentEntries($value)
	{
		if (!is_array($value) || !isset($value['entries']) || !is_array($value['entries'])) {
			return $value;
		}

		$iTotal = count($value['entries']);
		if ($iTotal <= self::MAX_CASELOG_ENTRIES) {
			return $value;
		}

		$value['entries'] = array_values(array_slice($value['entries'], -self::MAX_CASELOG_ENTRIES));
		$value['entries_omitted'] = $iTotal - self::MAX_CASELOG_ENTRIES;
		$value['entries_total'] = $iTotal;

		return $value;
	}

	/**
	 * Masks the sensitive attributes of every row of a link set.
	 *
	 * A sensitive attribute is masked on the object that carries it. Left
	 * unmasked on a link pointing at it, an attribute the datamodel marks
	 * sensitive on a link class - and the ones reached through it - would come
	 * back in clear to anyone who read the object on the other side of the
	 * link. iTop's REST API masks these too (SanitizeTrait in
	 * core/restservices.class.inc.php).
	 *
	 * Each row is attcode => value on the linked class, so the same
	 * IsSensitive() that decides a top-level attribute decides these - which is
	 * also what covers the n-n case, where the sensitive attribute belongs to
	 * the far class and is reached through an external field. iTop spells that
	 * case out as a separate branch; here it falls out of resolving external
	 * fields in one place.
	 *
	 * @param mixed $value As AttributeLinkedSet::GetForJSON() returns it: a list of attcode => value rows.
	 *
	 * @return mixed
	 */
	private static function maskSensitiveLinks($value, AttributeLinkedSet $oAttDef)
	{
		if (!is_array($value)) {
			return $value;
		}

		$sLinkedClass = $oAttDef->GetLinkedClass();

		foreach ($value as $iRow => $aRow) {
			if (!is_array($aRow)) {
				continue;
			}

			// An extended output names the subclass in finalclass, and a
			// subclass can carry attributes the declared link class does not.
			$sRowClass = isset($aRow['finalclass']) && is_string($aRow['finalclass'])
				&& MetaModel::IsValidClass($aRow['finalclass'])
					? $aRow['finalclass']
					: $sLinkedClass;

			foreach (array_keys($aRow) as $sLnkAttCode) {
				if (!MetaModel::IsValidAttCode($sRowClass, $sLnkAttCode)) {
					continue;
				}

				if (self::IsSensitive(MetaModel::GetAttributeDef($sRowClass, $sLnkAttCode))) {
					$value[$iRow][$sLnkAttCode] = self::MASK;
				}
			}
		}

		return $value;
	}

	/**
	 * The head of a link set, with a count of what was left out.
	 *
	 * A rack with four hundred devices, expanded attribute by attribute, is
	 * larger than everything else in the response put together.
	 *
	 * @param mixed $value As AttributeLinkedSet::GetForJSON() returns it.
	 *
	 * @return mixed
	 */
	private static function firstLinks($value)
	{
		if (!is_array($value) || count($value) <= self::MAX_LINKS) {
			return $value;
		}

		return [
			'links'         => array_values(array_slice($value, 0, self::MAX_LINKS)),
			'links_omitted' => count($value) - self::MAX_LINKS,
			'links_total'   => count($value),
		];
	}

	/**
	 * Cuts long strings, wherever they sit in a value.
	 *
	 * One description can hold an entire email thread, quoted signatures and
	 * all. The cut says so in the value itself rather than in a flag beside
	 * it, so a model reading the text knows it is reading part of it - and is
	 * told, in the text, how to read the rest.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	private static function clip($value)
	{
		if (is_array($value)) {
			return array_map(static fn ($mItem) => self::clip($mItem), $value);
		}

		if (!is_string($value) || mb_strlen($value) <= self::MAX_TEXT_CHARS) {
			return $value;
		}

		$iOmitted = mb_strlen($value) - self::MAX_TEXT_CHARS;

		return mb_substr($value, 0, self::MAX_TEXT_CHARS)
			.sprintf(' […truncated, %d more characters. Name this attribute in output_fields to read it in full]', $iOmitted);
	}

	/**
	 * A document reported by what it is, never by what it contains - and by
	 * where the caller can go and get it.
	 *
	 * The `uri` is what keeps this from being a dead end. The bytes stay out of
	 * every read, because a read fans out and a file base64-encoded into one is
	 * a context window spent without anyone choosing to; naming the one document
	 * wanted is the choice, and {@see DocumentAccess} is where it is served.
	 *
	 * @param mixed $value An ormDocument, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function document($value, string $sClass, int $iId, string $sAttCode): ?array
	{
		if (!$value instanceof ormDocument || $value->IsEmpty()) {
			return null;
		}

		return DocumentAccess::Describe($value, $sClass, $iId, $sAttCode);
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
	 * @since 1.0.0
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
				"Unknown attribute%s %s on class '%s'. Call core_class_schema for the attribute codes of the class.",
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
	 * @since 1.0.0
	 */
	public static function FieldsSchemaProperty(string $sDefault): array
	{
		return [
			'type'        => 'string',
			'description' => 'Comma-separated attribute codes to return, e.g. "title, status, agent_id". "'.self::ALL_FIELDS.'" returns every readable attribute, which is large - ask for what you need. Call core_class_schema for the codes. The id is always returned.',
			'default'     => $sDefault,
		];
	}

	/**
	 * Whether an attribute holds something that must not be reported.
	 *
	 * iAttributeNoGroupBy is how the datamodel marks an attribute as
	 * sensitive - it is the interface iTop's own code tests for. The one
	 * definition, so that what the schema calls sensitive and what the object
	 * tools mask cannot come to mean different things.
	 *
	 * An external field is sensitive when the attribute it points at is. It
	 * carries the remote value, so reading it is reading that attribute, and
	 * the interface sits on the remote definition rather than on the field
	 * itself - which is why testing the interface alone let the value through
	 * unmasked. iTop's REST API resolves this the same way; the difference here
	 * is that resolving it in IsSensitive() rather than at the call site means
	 * every caller gets it, including the schema tool that reports which
	 * attributes are sensitive.
	 *
	 * @since 1.0.0
	 */
	public static function IsSensitive(AttributeDefinition $oAttDef): bool
	{
		if ($oAttDef instanceof iAttributeNoGroupBy) {
			return true;
		}

		if (!$oAttDef instanceof AttributeExternalField) {
			return false;
		}

		try {
			return MetaModel::GetAttributeDef($oAttDef->GetTargetClass(), $oAttDef->GetExtAttCode())
				instanceof iAttributeNoGroupBy;
		} catch (Throwable $e) {
			// A field whose target cannot be resolved is a broken datamodel,
			// not a licence to report it: masked is the safe reading.
			return true;
		}
	}
}
