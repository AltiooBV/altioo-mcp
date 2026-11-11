<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\SecretPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\AttributeDescription;

use DBObject;
use DBObjectSet;
use MetaModel;
use RestUtils;
use Throwable;
use UserRights;

/**
 * The caller's values for an existing or new object, each held to every rule a value has to pass before it may be Set(), and
 * converted.
 *
 * @api
 * @since 2.0.0
 */
final class WriteValues
{
	/**
	 * The caller's values for an existing object, each held to every rule a
	 * value has to pass before it may be Set(), and converted.
	 *
	 * One copy for every tool that writes one object - the create, the
	 * update, the stimulus and the portal's writes - because a rule kept in
	 * four places is four rules that drift apart. Each refused attribute is
	 * named with why; nothing is Set() here, so the caller decides what a
	 * refusal costs before the object is touched.
	 *
	 * The rules are spelled WriteValues:: rather than self:: below, as at every
	 * other conversion site, so that the checks that look for them at each
	 * site find them here too.
	 *
	 * @param array<string, mixed> $aFields      Attribute code => value, as the client sent them.
	 * @param DBObjectSet|null     $oInstanceSet A set holding this object and nothing else, for the rights addon; null for an object not yet created.
	 * @param string|null          $sStimulus    The transition the values are set for, which may prompt for what the state locks.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>} The converted values, and attribute code => refusal.
	 * @since 2.0.0
	 */
	public static function Validated(DBObject $oObject, string $sClass, array $aFields, ?DBObjectSet $oInstanceSet, ?string $sStimulus = null): array
	{
		$aIssues = [];
		$aValidatedValues = [];
		foreach ($aFields as $sAttCode => $value) {
			$sAttCode = (string)$sAttCode;
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
				$aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$sClass}'.";
				continue;
			}
			// Two things, and it is worth being clear which is which.
			//
			// The comparison is the part that bites today: this returns
			// UR_ALLOWED_NO, _YES or _DEPENDS, and a truthy test reads DEPENDS
			// as a yes. Only YES is a yes.
			//
			// The set is the part that may bite later. iTop's shipped addon
			// documents that it ignores the instance set for attributes, so
			// under a stock install this reads the same with or without it -
			// object-level protection here comes from the set the object was
			// fetched through and from CheckToWrite() below. An addon that does
			// grade per object signals it with DEPENDS, and iTop passes a mono
			// set at every equivalent call site.
			//
			// No object exists yet on a creation, so "depends on the object"
			// cannot be answered there; only an outright refusal counts, and
			// DBInsert() checks again once there is something to check against.
			$iRight = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY, $oInstanceSet);
			if ($oInstanceSet === null ? $iRight === UR_ALLOWED_NO : $iRight !== UR_ALLOWED_YES) {
				$aIssues[$sAttCode] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if (!$oAttDef->IsWritable()) {
				$aIssues[$sAttCode] = "Attribute '{$sAttCode}' is not writable.";
				continue;
			}
			// Writable is the ORM's word; this is the console's. The state, what
			// the lifecycle locks, a stopwatch, a case log's history. See EditPolicy.
			$sNotEditable = EditPolicy::RefusalFor($oObject, $sAttCode, $value, $sStimulus);
			if ($sNotEditable !== null) {
				$aIssues[$sAttCode] = $sNotEditable;
				continue;
			}
			// Asked before the ORM sees it: an id pointing at nothing is a
			// mistake the caller can correct, and iTop answers it with an
			// exception this module can only report opaquely.
			$sBadTarget = ExternalKeyPolicy::RefusalFor($sClass, $sAttCode, $value);
			if ($sBadTarget !== null) {
				$aIssues[$sAttCode] = $sBadTarget;
				continue;
			}
			// A mention is a notification: held to what the console would
			// have let this user write, and to a per-call cap. See MentionPolicy.
			$sBadMention = MentionPolicy::RefusalFor($sClass, $sAttCode, $value);
			if ($sBadMention !== null) {
				$aIssues[$sAttCode] = $sBadMention;
				continue;
			}
			// Text a spreadsheet would run as a formula. See FormulaPolicy.
			$sBadFormula = FormulaPolicy::RefusalFor($sClass, $sAttCode, $value);
			if ($sBadFormula !== null) {
				$aIssues[$sAttCode] = $sBadFormula;
				continue;
			}
			// A secret the instance logs in to something else with. See SecretPolicy.
			$sBadSecret = SecretPolicy::RefusalFor($sClass, $sAttCode, $value);
			if ($sBadSecret !== null) {
				$aIssues[$sAttCode] = $sBadSecret;
				continue;
			}

			try {
				// The SDK hands us arrays for nested JSON objects; RestUtils
				// branches on stdClass. See RestValue.
				$aValidatedValues[$sAttCode] = RestUtils::MakeValue($sClass, $sAttCode, RestValue::FromDecodedJson($value));
				// iTop's set attributes throw away an element they do not
				// recognise without saying so. A value the caller sent and the
				// object will not hold is a refusal here, the way a scalar enum
				// already refuses one.
				$sDropped = WriteValues::RefusalForDroppedSetValues($sClass, $sAttCode, $value, $aValidatedValues[$sAttCode]);
				if ($sDropped !== null) {
					unset($aValidatedValues[$sAttCode]);
					$aIssues[$sAttCode] = $sDropped;
					continue;
				}
				// What the set would add, change and remove, judged before
				// Set() hands it to ormLinkSet. See EditPolicy.
				$sBadLinks = EditPolicy::RefusalForLinkSet($oObject, $sAttCode, $aValidatedValues[$sAttCode]);
				if ($sBadLinks !== null) {
					unset($aValidatedValues[$sAttCode]);
					$aIssues[$sAttCode] = $sBadLinks;
					continue;
				}
			} catch (Throwable $e) {
				$aIssues[$sAttCode] = ErrorReport::RejectedValue("Invalid value for attribute '{$sAttCode}'", $e)
					.AttributeDescription::ValueHint($sClass, $sAttCode, $value);
			}
		}

		return [$aValidatedValues, $aIssues];
	}

	/**
	 * A set attribute that quietly threw away part of what the caller sent.
	 *
	 * AttributeSet::MakeRealValue() walks the elements it parsed and unsets
	 * every one that is not in the allowed values - no exception, no flag,
	 * nothing on the object to compare against afterwards:
	 *
	 *     if (!isset($aAllowedValues[$sValue])) { unset($aValues[$i]); }
	 *
	 * So `scope: "mcp"` on a token came back valid: true with an empty scope,
	 * and a caller that asked for a narrow credential got one that grants
	 * nothing - or, on an attribute where empty means something else, one that
	 * grants more. A scalar enum refuses the same mistake by name; the set
	 * form is the inconsistency, not the refusal.
	 *
	 * `overridden` cannot see this and never could. It compares what the
	 * object holds before CheckToWrite() with what it holds after, which
	 * catches DoComputeValues() - and this happens earlier still, inside the
	 * conversion, so by the time anything is Set() the asked-for value is
	 * already gone. The comparison has to be against what the caller actually
	 * sent, which is why this takes the raw value rather than reading the
	 * object.
	 *
	 * Elements are split here rather than through FromStringToArray(), which
	 * has a silent drop of its own - it ignores anything shorter than three
	 * characters - and a rule about silent drops should not inherit one.
	 *
	 * @param mixed $mSupplied  As the caller sent it.
	 * @param mixed $mConverted What MakeValue() made of it.
	 *
	 * @return string|null The refusal, or null when nothing was lost.
	 * @since 1.0.0
	 */
	public static function RefusalForDroppedSetValues(string $sClass, string $sAttCode, mixed $mSupplied, mixed $mConverted): ?string
	{
		if (!$mConverted instanceof \ormSet) {
			return null;
		}

		try {
			$aAsked = self::setElements($mSupplied);
			if ($aAsked === []) {
				return null;
			}

			$aKept = array_map('strval', $mConverted->GetValues());
			$aLost = array_values(array_diff($aAsked, $aKept));
			if ($aLost === []) {
				return null;
			}

			$oAttDef  = MetaModel::GetAttributeDef($sClass, $sAttCode);
			$aAllowed = array_keys($oAttDef->GetPossibleValues());
			sort($aAllowed);

			return sprintf(
				"'%s' does not accept %s. iTop drops an unknown element of a set without saying so, and the write would "
				."have reported success with %s stored instead - so it is refused here rather than applied by halves. "
				."Allowed: %s.",
				$sAttCode,
				implode(', ', array_map(static fn ($s) => json_encode($s), $aLost)),
				$aKept === [] ? 'nothing' : json_encode(implode(', ', $aKept)),
				$aAllowed === [] ? '(none declared)' : implode(', ', $aAllowed)
			);
		} catch (Throwable $e) {
			// A question about the value must not become the refusal.
			return null;
		}
	}

	/**
	 * The elements of a set as the caller wrote them.
	 *
	 * Both shapes a client sends: a list, and the comma-separated string iTop
	 * itself uses. The configured item separator is honoured where there is a
	 * configuration to ask.
	 *
	 * @return array<int, string>
	 */
	private static function setElements(mixed $mSupplied): array
	{
		if (is_array($mSupplied)) {
			$aRaw = $mSupplied;
		} elseif (is_string($mSupplied)) {
			$sSeparator = ',';
			try {
				$sConfigured = (string) MetaModel::GetConfig()->Get('tag_set_item_separator');
				if ($sConfigured !== '') {
					$mSupplied  = str_replace($sConfigured, ',', $mSupplied);
				}
			} catch (Throwable $e) {
				// No configuration to ask; the comma is iTop's own default.
			}
			$aRaw = explode($sSeparator, $mSupplied);
		} else {
			return [];
		}

		$aElements = [];
		foreach ($aRaw as $mElement) {
			if (!is_scalar($mElement)) {
				continue;
			}
			$sElement = trim((string) $mElement);
			if ($sElement !== '') {
				$aElements[] = $sElement;
			}
		}

		return array_values(array_unique($aElements));
	}
}
