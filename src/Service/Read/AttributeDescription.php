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
use AttributeClass;
use AttributeDefinition;
use AttributeExternalField;
use AttributeExternalKey;
use AttributeFinalClass;
use AttributeLinkedSet;
use AttributeLinkedSetIndirect;
use MetaModel;
use UserRights;
use ValueSetObjects;

/**
 * The attributes of a class, described for a caller that has to read or write them.
 *
 * Each attribute's type, whether it is mandatory or writable, the values it
 * accepts, what it depends on, what the caller is told about writing it, and
 * what the caller's rights on it are - the `attributes` block of
 * {@see DatamodelReader::Describe()} - plus the hint a refused value is answered
 * with ({@see self::ValueHint()}) and what a filter on a list of values may be
 * given ({@see self::FilterRefusal()}). Attributes the exclusions of ADR 0012 name are
 * absent, and a secret is described without its value.
 *
 * @api
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
				// "no" for an attribute nobody writes: readOnly already says why, and "yes" beside it read as a
				// contradiction (review 2026-11-23, C9). The grade is the caller's answer for what can be written.
				'modify'        => $oAttDef->IsWritable() ? GateGrades::grade($iModify) : GateGrades::grade(UR_ALLOWED_NO),
				'nullable'      => $oAttDef->IsNullAllowed(),
				'isExternalKey' => $oAttDef->IsExternalKey(),
				'isScalar'      => $oAttDef->IsScalar(),
				'isSensible'    => ObjectSerializer::IsSensitive($oAttDef, $sClass),
			] + self::defaultValue($oAttDef) + self::allowedValues($oAttDef, $sClass) + self::dependsOn($oAttDef, $sClass) + self::writeHint($oAttDef);
		}

		return $aAttributes;
	}

	/**
	 * The value a write gives an attribute nobody set, when there is one worth saying.
	 *
	 * `required` is the datamodel forbidding null, which is not "you must send it": an attribute with a default
	 * is filled by the write (a ticket's status, impact and urgency), and a model that sends every `required` one
	 * sends what iTop would have chosen anyway (review 2026-11-23, C4). Reported only where there is one, so
	 * an attribute with no `default` and `required: true` is the one a create has to be given.
	 *
	 * Nothing is said for an empty default (an empty string, 0 or an empty key), for a default that is not a
	 * plain value (a link set's), for a secret, or for class names, which may name a class the operator
	 * excluded (ADR 0012).
	 *
	 * @return array<string, scalar>
	 */
	private static function defaultValue(AttributeDefinition $oAttDef): array
	{
		if (self::listsClassNames($oAttDef)) {
			return [];
		}

		try {
			$mDefault = $oAttDef->GetDefaultValue();
		} catch (\Throwable $e) {
			return [];
		}

		if (!is_scalar($mDefault) || $mDefault === '' || $mDefault === 0 || $mDefault === false || ($oAttDef->IsExternalKey() && (string)$mDefault === '0')) {
			return [];
		}

		return ['default' => $mDefault];
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
	private static function allowedValues(AttributeDefinition $oAttDef, string $sClass): array
	{
		if ($oAttDef->IsExternalKey()) {
			return [
				'values'      => null,
				'targetClass' => $oAttDef->GetTargetClass(),
				'valuesHint'  => 'Search the target class for the object you want, then pass its id.',
			];
		}

		$sListed = self::classOfListedObjects($oAttDef);
		if ($sListed !== null && (ExclusionPolicy::ExcludesClass($sListed) || ExclusionPolicy::ExcludesAttribute($sListed, 'friendlyname'))) {
			// The values are the names of objects of a class the operator excluded, or names made of what they excluded
			// (doc/adr/0012, Amendment 3): none is listed, as for a key. The attribute itself is already absent when
			// it points at such a class through a parent; this is what is left when it does not.
			return ['values' => null, 'targetClass' => $sListed, 'valuesHint' => 'Search the target class for the object you want, then pass its id.'];
		}

		$aValues = self::ServedValues($oAttDef);
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
	 * The allowed values of an attribute, less the classes the operator excluded where the values are classes.
	 *
	 * The values of an `AttributeClass` (`target_class` of a trigger, `scope_class` of a synchronisation source) and of
	 * an `AttributeFinalClass` (a parent's `finalclass`) are class names. An excluded class is absent from every
	 * route, so it is absent from this list too, and a value naming one is as unknown as one the datamodel has
	 * not (doc/adr/0012). Every route that lists, or checks a value against, an attribute's allowed values asks this
	 * rather than `GetAllowedValues()`.
	 *
	 * @return array<array-key, mixed>|null As `GetAllowedValues()` answers.
	 * @since 2.1.0
	 */
	public static function ServedValues(AttributeDefinition $oAttDef): ?array
	{
		$aValues = $oAttDef->GetAllowedValues();
		if (!is_array($aValues) || !self::listsClassNames($oAttDef)) {
			return is_array($aValues) ? $aValues : null;
		}

		return array_filter($aValues, static fn ($mLabel, $mCode): bool => !ExclusionPolicy::ExcludesClass((string)$mCode), ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * Whether an attribute's allowed values are names of classes: an `AttributeClass` (its values come from the datamodel's
	 * classes) or an `AttributeFinalClass` (the classes under the one that declares it).
	 */
	private static function listsClassNames(AttributeDefinition $oAttDef): bool
	{
		/** @psalm-suppress TypeDoesNotContainType iTop's attribute classes are not all in the stubs */
		return $oAttDef instanceof AttributeClass || $oAttDef instanceof AttributeFinalClass;
	}

	/**
	 * Whether the `values` an attribute lists are names of objects, which people wrote, and not codes the datamodel declares:
	 * a link set's, where it lists any. The schema marks those as it marks the same names anywhere else (ADR 0008).
	 *
	 * @since 2.1.0
	 */
	public static function ListsObjectNames(string $sClass, string $sAttCode): bool
	{
		try {
			return self::classOfListedObjects(MetaModel::GetAttributeDef($sClass, $sAttCode)) !== null;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The class whose objects an attribute lists as its values - a link set's: the class it links, or the one
	 * its link class leads to - or null for an attribute whose values are not objects.
	 */
	private static function classOfListedObjects(AttributeDefinition $oAttDef): ?string
	{
		/** @psalm-suppress RedundantCondition */
		if (!$oAttDef instanceof AttributeLinkedSet) {
			return null;
		}

		try {
			$sLinked = $oAttDef->GetLinkedClass();
			/** @psalm-suppress TypeDoesNotContainType */
			if ($oAttDef instanceof AttributeLinkedSetIndirect) {
				$oToRemote = MetaModel::GetAttributeDef($sLinked, $oAttDef->GetExtKeyToRemote());

				return $oToRemote instanceof AttributeExternalKey ? $oToRemote->GetTargetClass() : $sLinked;
			}

			return $sLinked;
		} catch (\Throwable $e) {
			return null;
		}
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
	 * The value to filter on, for an attribute that holds one of a list of values: an enumeration, a yes/no flag, a
	 * stopwatch's `passed` and `triggered`, a class name, and an external field showing one of these.
	 *
	 * @param mixed $value The filter value as sent.
	 *
	 * @return mixed The code a label or a code in another case stands for, and otherwise the value as sent.
	 *
	 * @since 2.2.0
	 */
	public static function ForFilter(AttributeDefinition $oAttDef, mixed $value): mixed
	{
		$aValues = self::filterList($oAttDef);

		return $aValues === null ? $value : (self::MatchingValue($aValues, $value) ?? $value);
	}

	/**
	 * Why a filter cannot be given this value, or null when it can: the attribute holds one of a list of values, and
	 * this is none of them by code or label. The value is not quoted back.
	 *
	 * The database compares what it is handed, its own way. A column holding 0 and 1 reads `'yes'` as 0, so
	 * `sla_tto_passed: "yes"` counted the requests that had **not** breached, and `status: "nonsense"` counted none,
	 * which reads as "there is none" (retest of 2026-11-28, B12).
	 *
	 * The list is the one this endpoint serves ({@see self::ServedValues()}): a class the operator excluded
	 * (doc/adr/0012) is neither named in a refusal nor found by its label, and answers as a word the datamodel has not.
	 * Whether the attribute may be filtered on at all is the tool's question, asked first. An external key, a link set
	 * and a list made of objects are not held to a list: their values are objects. Null is left to the search, as it
	 * was.
	 *
	 * @param mixed $value The filter value as sent.
	 *
	 * @since 2.2.0
	 */
	public static function FilterRefusal(AttributeDefinition $oAttDef, string $sAttCode, mixed $value): ?string
	{
		$aValues = self::filterList($oAttDef);
		if ($aValues === null || $value === null || self::MatchingValue($aValues, $value) !== null) {
			return null;
		}

		return "The filter on '{$sAttCode}' takes one of its values, by code or label".self::NamingValues($aValues);
	}

	/**
	 * The value to filter on when $value names one of $aValues, or null when it names none, or more than one. Pure.
	 *
	 * A code as it is stands for itself. Otherwise codes and labels are compared without their case, and the answer is
	 * the one code that matches: a word that stands for two values is neither. A JSON true or false is 1 or 0 where the
	 * list is a yes/no one.
	 *
	 * @param array<array-key, mixed> $aValues Code => label.
	 * @param mixed                   $value   As the caller sent it.
	 *
	 * @internal Public for the tests.
	 */
	public static function MatchingValue(array $aValues, mixed $value): mixed
	{
		if (is_bool($value)) {
			return array_keys($aValues) === [0, 1] ? (int)$value : null;
		}
		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			return null;
		}
		if (array_key_exists((string)$value, $aValues)) {
			return $value;
		}

		$sNeedle = mb_strtolower(trim((string)$value));
		if ($sNeedle === '') {
			return null;
		}
		$aFound = [];
		foreach ($aValues as $mCode => $mLabel) {
			if (mb_strtolower((string)$mCode) === $sNeedle || (is_string($mLabel) && mb_strtolower(trim($mLabel)) === $sNeedle)) {
				$aFound[] = $mCode;
			}
		}

		return count($aFound) === 1 ? $aFound[0] : null;
	}

	/**
	 * The end of a refusal: ": new (New), closed." - each code, with its label where that is another word - or, past
	 * {@see self::MAX_ALLOWED_VALUES}, where to read them. Pure.
	 *
	 * @param array<array-key, mixed> $aValues Code => label.
	 *
	 * @internal Public for the tests.
	 */
	public static function NamingValues(array $aValues): string
	{
		if (count($aValues) > self::MAX_ALLOWED_VALUES) {
			return '. There are '.count($aValues).' of them; core_class_schema lists the first '.self::MAX_ALLOWED_VALUES.'.';
		}

		$aNamed = [];
		foreach ($aValues as $mCode => $mLabel) {
			$sCode = (string)$mCode;
			$aNamed[] = !is_string($mLabel) || $mLabel === '' || mb_strtolower($mLabel) === mb_strtolower($sCode) ? $sCode : "{$sCode} ({$mLabel})";
		}

		return ': '.implode(', ', $aNamed).'.';
	}

	/**
	 * The values a filter on this attribute may be given, or null when it is not one that holds one of a list.
	 *
	 * An external field is asked as the attribute it shows, which is where iTop declares the list. A field excluded with
	 * what it shows never gets here.
	 *
	 * @return array<array-key, mixed>|null Code => label.
	 *
	 * @psalm-suppress TypeDoesNotContainType, MixedAssignment, MixedMethodCall, RedundantCondition iTop's attribute classes are not known to the analysis as related.
	 */
	private static function filterList(AttributeDefinition $oAttDef): ?array
	{
		try {
			if ($oAttDef instanceof AttributeExternalField) {
				$oAttDef = $oAttDef->GetFinalAttDef();
			}
			if ($oAttDef->IsExternalKey() || $oAttDef instanceof AttributeLinkedSet || $oAttDef->GetValuesDef() instanceof ValueSetObjects) {
				return null;
			}
			$aValues = self::ServedValues($oAttDef);
		} catch (\Throwable $e) {
			// A list that needs an object to be worked out (the states of a class named by another attribute) is none.
			return null;
		}

		return $aValues === null || $aValues === [] ? null : $aValues;
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
	 * How to write a link set, which reads as rows and is written as the complete list of its links (review 2026-11-23, C19).
	 *
	 * Each item is an object keyed by the attribute codes of the link class: for an indirect set the key to the remote
	 * object, which is the one a caller guesses ([{"functionalci_id": 1}] attaches that CI), for a direct one the
	 * linked class's own attributes. The key back to the host is implied, and what the list leaves out is removed,
	 * which iTop then checks against this account's rights on the link class. A name the operator excluded
	 * (ADR 0012) is not written here: the hint then says the same without it.
	 */
	private static function linkSetHint(AttributeLinkedSet $oAttDef): string
	{
		$sGeneral = 'Send the complete list, as an array of objects keyed by the link class\'s attribute codes; a link left out is removed.';

		try {
			$sLinkClass = $oAttDef->GetLinkedClass();
			if (ExclusionPolicy::ExcludesClass($sLinkClass)) {
				return $sGeneral;
			}

			/** @psalm-suppress RedundantCondition iTop's attribute classes are not all in the stubs */
			if (!$oAttDef instanceof AttributeLinkedSetIndirect) {
				return "Send the complete list, as an array of {$sLinkClass} objects keyed by attribute code; one left out is removed.";
			}

			$sRemoteKey = $oAttDef->GetExtKeyToRemote();
			$oToRemote = MetaModel::GetAttributeDef($sLinkClass, $sRemoteKey);
			$sRemote = $oToRemote instanceof AttributeExternalKey ? $oToRemote->GetTargetClass() : '';
			if (ExclusionPolicy::ExcludesAttribute($sLinkClass, $sRemoteKey) || ($sRemote !== '' && ExclusionPolicy::ExcludesClass($sRemote))) {
				return $sGeneral;
			}

			return "Send the complete list, as {$sLinkClass} objects keyed by attribute code: [{\"{$sRemoteKey}\": 1}] links {$sRemote} 1. A link left out is removed.";
		} catch (\Throwable $e) {
			return $sGeneral;
		}
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

		/** @psalm-suppress TypeDoesNotContainType iTop's attribute classes are not all in the stubs */
		if ($oAttDef instanceof AttributeLinkedSet) {
			return ['writeHint' => self::linkSetHint($oAttDef)];
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
