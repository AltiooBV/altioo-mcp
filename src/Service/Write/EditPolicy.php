<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use AttributeCaseLog;
use AttributeExternalKey;
use AttributeLinkedSet;
use AttributeStopWatch;
use DBObject;
use DBObjectSet;
use DBObjectSetComparator;
use MetaModel;
use Throwable;
use UserRights;

/**
 * What a write may change: what the console's form would let this user edit,
 * in the state the object is in. doc/adr/0004 is the decision.
 *
 * iTop's IsWritable() only says the ORM will store a value, and REST
 * core/update stops there. So does DoCheckToWrite(). Between them a single
 * call could close a ticket without a transition, edit a field the lifecycle
 * locks, zero an SLA timer, rewrite a case log or delete a link set's
 * children. The console's forms never offer any of that, and this endpoint is
 * driven by a model with less supervision than a console user has.
 *
 * A form also makes fields mandatory, which neither the ORM nor DoCheckToWrite() asks (Amendment 1 of the same
 * record): a create gives them a value, filled in where only one is allowed ({@see self::FillOnlyValues()},
 * {@see self::RefusalForMissingMandatory()}), and an update does not empty one ({@see self::MandatoryHeld()},
 * {@see self::RefusalForEmptied()}).
 *
 * Three questions, asked at the three points where each can be answered:
 *
 * - RefusalFor(), on the value as sent and the object as it stands: the
 *   state attribute, the lifecycle flags, stopwatches, the shape of a case
 *   log entry.
 * - RefusalForLinkSet(), once the value is a set: what it adds, changes and
 *   removes, against edit_when, edit_mode and the rights on the linked class.
 * - RefusalsForExternalKeys(), once every value is Set(): whether a key is
 *   among its allowed values, since a datamodel filter reads the object
 *   (:this->org_id) and the other values of the same call can move it.
 *
 * A new object (IsNew()) is a creation and is held to its initial state. A
 * stimulus is named, because its transition form offers fields the current
 * state locks: agent_id on ev_assign is the usual one.
 *
 * @api
 * @since 1.2.0
 */
final class EditPolicy
{
	/** The flags that keep an attribute out of the console's edit form. */
	private const NOT_EDITABLE = OPT_ATT_HIDDEN | OPT_ATT_READONLY;

	/** What a case log entry written here may carry. Not a date, not an author. */
	private const CASE_LOG_ENTRY_KEYS = ['message', 'format'];

	/**
	 * Why $value may not be written to $sAttCode, or null when it may.
	 *
	 * @param string|null $sStimulus The stimulus the value is set for, when it is
	 */
	public static function RefusalFor(DBObject $oObject, string $sAttCode, mixed $value, ?string $sStimulus = null): ?string
	{
		$sClass = get_class($oObject);
		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		} catch (Throwable $e) {
			// An unknown attribute is refused by the write itself, in its own words.
			return null;
		}

		if (MetaModel::HasLifecycle($sClass) && MetaModel::GetStateAttributeCode($sClass) === $sAttCode) {
			return self::stateRefusal($oObject, $sAttCode);
		}

		$sFlags = self::flagsRefusal($oObject, $sAttCode, $sStimulus);
		if ($sFlags !== null) {
			return $sFlags;
		}

		if ($oAttDef instanceof AttributeStopWatch) {
			return "Attribute '{$sAttCode}' is a stopwatch, an SLA timer the lifecycle starts and stops. "
				."Any value written to it replaces it with a new one at zero, so it is never written here.";
		}

		if ($oAttDef instanceof AttributeCaseLog) {
			return self::caseLogRefusal($sAttCode, $value);
		}

		return null;
	}

	/**
	 * Why the set $oNewSet may not replace $sAttCode, or null when it may.
	 *
	 * Asked before Set(), with the same comparison ormLinkSet makes when it is
	 * handed a complete list, so what is judged is what DBWrite() would do.
	 */
	public static function RefusalForLinkSet(DBObject $oObject, string $sAttCode, mixed $oNewSet): ?string
	{
		$sClass = get_class($oObject);
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if (!$oAttDef instanceof AttributeLinkedSet || !$oNewSet instanceof DBObjectSet) {
			return null;
		}

		try {
			$sAdditionalKey = ($oAttDef->IsIndirect() && !$oAttDef->DuplicatesAllowed())
				? $oAttDef->GetExtKeyToRemote()
				: null;
			$oComparator = new DBObjectSetComparator($oObject->Get($sAttCode), $oNewSet, [$oAttDef->GetExtKeyToMe()], $sAdditionalKey);
			$aDiff = $oComparator->GetDifferences();
			$oNewSet->Rewind();
		} catch (Throwable $e) {
			// The ORM gets its say on a set it cannot compare, in its own words.
			return null;
		}

		$aAdded    = $aDiff['added'];
		$aModified = array_values($aDiff['modified']);
		$aRemoved  = $aDiff['removed'];
		if (empty($aAdded) && empty($aModified) && empty($aRemoved)) {
			return null;
		}

		$sLinkedClass = $oAttDef->GetLinkedClass();
		$sOther = " Change the {$sLinkedClass} objects themselves with core_object_create, core_object_update and core_object_delete.";

		if (!in_array($oAttDef->GetEditWhen(), [LINKSET_EDITWHEN_ALWAYS, LINKSET_EDITWHEN_ON_HOST_EDITION], true)
			|| $oAttDef->GetEditMode() === LINKSET_EDITMODE_NONE) {
			return "Attribute '{$sAttCode}' is not edited from the {$sClass} it belongs to.".$sOther;
		}
		if ($oAttDef->GetEditMode() === LINKSET_EDITMODE_ADDONLY && (!empty($aModified) || !empty($aRemoved))) {
			return "Attribute '{$sAttCode}' only accepts additions: send the current items with the new ones, unchanged.".$sOther;
		}

		// A removal is a delete, except from a direct set whose key may be
		// null: that one only detaches the child, which is a modification.
		$iRemoval = UR_ACTION_DELETE;
		if (!$oAttDef->IsIndirect()
			&& MetaModel::GetAttributeDef($sLinkedClass, $oAttDef->GetExtKeyToMe())->IsNullAllowed()) {
			$iRemoval = UR_ACTION_MODIFY;
		}

		foreach ([
			[$aAdded, UR_ACTION_CREATE, 'add'],
			[$aModified, UR_ACTION_MODIFY, 'change'],
			[$aRemoved, $iRemoval, $iRemoval === UR_ACTION_DELETE ? 'remove (which deletes them)' : 'remove'],
		] as [$aObjects, $iAction, $sVerb]) {
			if (empty($aObjects)) {
				continue;
			}
			// Only YES is a yes: this is tri-state, and DEPENDS read as
			// truthy would be a permission nobody granted.
			$oSet = DBObjectSet::FromArray($sLinkedClass, $aObjects);
			if (UserRights::IsActionAllowed($sLinkedClass, $iAction, $oSet) !== UR_ALLOWED_YES) {
				return "Attribute '{$sAttCode}': this account may not {$sVerb} {$sLinkedClass} objects.";
			}
		}

		return null;
	}

	/**
	 * The external keys among $aAttCodes whose value is not one the attribute
	 * allows, each with why, keyed by attribute code.
	 *
	 * Asked once every value of the call is Set(), because a filter reads the
	 * object: an agent_id limited to the ticket's team is judged against the
	 * team_id the same call sets.
	 *
	 * @param string[] $aAttCodes
	 *
	 * @return array<string, string>
	 */
	public static function RefusalsForExternalKeys(DBObject $oObject, array $aAttCodes): array
	{
		$sClass = get_class($oObject);
		$aRefusals = [];
		foreach ($aAttCodes as $sAttCode) {
			try {
				$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
				if (!$oAttDef instanceof AttributeExternalKey) {
					continue;
				}
				$iValue = (int) $oObject->Get($sAttCode);
				// No target is the mandatory check's to judge, and a value the
				// object already held is not this call's doing.
				if ($iValue === 0 || (!$oObject->IsNew() && $iValue === (int) $oObject->GetOriginal($sAttCode))) {
					continue;
				}

				$aArgs = ['this' => $oObject];
				$oFilter = MetaModel::GetAllowedValuesAsObjectSet($sClass, $sAttCode, $aArgs)->GetFilter();
				$oFilter->AddCondition('id', $iValue, '=');
				if ((new DBObjectSet($oFilter, [], $aArgs))->CountExceeds(0)) {
					continue;
				}
			} catch (Throwable $e) {
				// iTop's own answer to a filter it cannot evaluate is to widen
				// it to every target (AttributeExternalKey::GetAllowedValues()),
				// and the existence check has already been made.
				continue;
			}

			$sFilter = (string)$oAttDef->GetValuesDef()->GetFilterExpression();
			if (PortalMode::Current() !== null) {
				// The datamodel's query is staff vocabulary, and an end user cannot act on it: the log keeps it (review
				// 2026-11-18, RT-13; the same split ADR 0007 makes for a portal that is refused).
				ErrorReport::Log(sprintf("Refused %s %d as '%s' of a %s for a portal user: the datamodel limits it to: %s", $oAttDef->GetTargetClass(), $iValue, $sAttCode, $sClass, $sFilter));
				$aRefusals[$sAttCode] = sprintf("%s %d is not a value '%s' accepts on this %s.", $oAttDef->GetTargetClass(), $iValue, $sAttCode, $sClass);
				continue;
			}

			$aRefusals[$sAttCode] = sprintf(
				"%s %d is not a value '%s' accepts on this %s. The datamodel limits it to: %s",
				$oAttDef->GetTargetClass(),
				$iValue,
				$sAttCode,
				$sClass,
				$sFilter
			);
		}

		return $aRefusals;
	}

	/**
	 * Gives each of $aMandatory that holds nothing the only value it allows, where it allows exactly one, as the
	 * console's creation form does. Only what this account may write and read, and only a value this mode lets it
	 * reach. Asked again after each value, since one narrows another (a service by its organisation).
	 *
	 * @param array<int, string> $aMandatory As {@see ObjectAccess::MandatoryOn()} answers.
	 *
	 * @return array<int, string> The attributes filled.
	 *
	 * @since 2.2.0
	 */
	public static function FillOnlyValues(DBObject $oObject, array $aMandatory, ObjectAccess $oAccess): array
	{
		$sClass = get_class($oObject);
		$aFilled = [];
		do {
			$bMore = false;
			foreach ($aMandatory as $sAttCode) {
				if (in_array($sAttCode, $aFilled, true) || $oObject->HasAValue($sAttCode)
					|| UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY) === UR_ALLOWED_NO
					|| UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
					continue;
				}
				try {
					$mValue = self::onlyAllowedValue($oObject, $sAttCode);
					if ($mValue === null) {
						continue;
					}
					$mBefore = $oObject->Get($sAttCode);
					$oObject->Set($sAttCode, $mValue);
					if ($oAccess->RefusalsForReach($oObject, [$sAttCode]) !== []) {
						$oObject->Set($sAttCode, $mBefore);
						continue;
					}
				} catch (Throwable $e) {
					// A list that cannot be worked out is not a list of one: the attribute stays the caller's to give.
					ErrorReport::Log('Could not fill the only value of '.$sClass.'.'.$sAttCode.': '.get_class($e));
					continue;
				}
				$aFilled[] = $sAttCode;
				$bMore = true;
			}
		} while ($bMore);

		return $aFilled;
	}

	/**
	 * Why a new object may not be written: attributes its form makes mandatory that still hold nothing, once every
	 * value is Set() and iTop has computed its own. One the account may not read is not named.
	 *
	 * @param array<int, string> $aMandatory As {@see ObjectAccess::MandatoryOn()} answers.
	 *
	 * @since 2.2.0
	 */
	public static function RefusalForMissingMandatory(DBObject $oObject, array $aMandatory): ?string
	{
		$sClass = get_class($oObject);
		$aToSend = [];
		$aBlocked = [];
		$bUnnamed = false;
		foreach ($aMandatory as $sAttCode) {
			if ($oObject->HasAValue($sAttCode)) {
				continue;
			}
			if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
				$bUnnamed = true;
			} elseif (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY) === UR_ALLOWED_NO) {
				$aBlocked[] = $sAttCode;
			} else {
				$aToSend[] = $sAttCode;
			}
		}
		if ($aToSend === [] && $aBlocked === [] && !$bUnnamed) {
			return null;
		}

		$sRefusal = "This {$sClass} cannot be created as described.";
		if ($aBlocked !== []) {
			$sRefusal .= ' This account may not write '.implode(', ', $aBlocked).', mandatory on a new one, so no value you send can complete it.';
		}
		if ($aToSend !== []) {
			$sRefusal .= ' Mandatory on a new one and holding no value: '.implode(', ', $aToSend).'. Send '
				.(count($aToSend) === 1 ? 'it' : 'them').' in the fields; core_class_schema says what a create needs.';
		}

		return $sRefusal;
	}

	/**
	 * Among $aMandatory, those that hold a value now: the ones an update must leave with one. Asked before Set().
	 *
	 * An attribute already empty is not one of them. The console's form would ask for it on any edit; here an old
	 * object stays editable.
	 *
	 * @param array<int, string> $aMandatory As {@see ObjectAccess::MandatoryOn()} answers.
	 *
	 * @return array<int, string>
	 *
	 * @since 2.2.0
	 */
	public static function MandatoryHeld(DBObject $oObject, array $aMandatory): array
	{
		return array_values(array_filter($aMandatory, static fn (string $sAttCode): bool => $oObject->HasAValue($sAttCode)));
	}

	/**
	 * Why an update may not be written: it empties attributes {@see MandatoryHeld()} listed before it.
	 *
	 * @param array<int, string> $aHeld
	 *
	 * @since 2.2.0
	 */
	public static function RefusalForEmptied(DBObject $oObject, array $aHeld): ?string
	{
		$aEmptied = array_values(array_filter($aHeld, static fn (string $sAttCode): bool => !$oObject->HasAValue($sAttCode)));
		if ($aEmptied === []) {
			return null;
		}

		$sClass = get_class($oObject);
		$sState = MetaModel::HasLifecycle($sClass) ? " in state '".(string)$oObject->Get(MetaModel::GetStateAttributeCode($sClass))."'" : '';
		$bOne = count($aEmptied) === 1;

		return implode(', ', $aEmptied).($bOne ? ' is' : ' are')." mandatory on this {$sClass}{$sState}, so "
			.($bOne ? 'it' : 'they').' cannot be emptied: send another value, or leave '.($bOne ? 'it' : 'them').' out.';
	}

	/** The one value the attribute allows on the object as it stands, or null when there is none, more than one, or no list. */
	private static function onlyAllowedValue(DBObject $oObject, string $sAttCode): mixed
	{
		$sClass = get_class($oObject);
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		$aArgs = ['this' => $oObject];
		if ($oAttDef instanceof AttributeExternalKey) {
			$oSet = MetaModel::GetAllowedValuesAsObjectSet($sClass, $sAttCode, $aArgs);
			if ($oSet->CountWithLimit(2) != 1) {
				return null;
			}
			$oOnly = $oSet->Fetch();

			return $oOnly instanceof DBObject ? $oOnly->GetKey() : null;
		}
		if (!$oAttDef->IsScalar()) {
			return null;
		}
		$aValues = MetaModel::GetAllowedValues_att($sClass, $sAttCode, $aArgs);

		return is_array($aValues) && count($aValues) === 1 ? array_key_first($aValues) : null;
	}

	private static function stateRefusal(DBObject $oObject, string $sAttCode): string
	{
		$sClass = get_class($oObject);
		if ($oObject->IsNew()) {
			return "Attribute '{$sAttCode}' is the lifecycle state of {$sClass}. A new {$sClass} starts in its initial state: "
				."leave '{$sAttCode}' out, then move it with core_object_apply_stimulus once it exists.";
		}

		return "Attribute '{$sAttCode}' is the lifecycle state of {$sClass}, and it changes only through a transition: "
			."call core_object_apply_stimulus with one of the stimuli core_object_get lists for this object.";
	}

	private static function flagsRefusal(DBObject $oObject, string $sAttCode, ?string $sStimulus): ?string
	{
		$sClass = get_class($oObject);
		$bLifecycle = MetaModel::HasLifecycle($sClass);
		$sState = $bLifecycle ? (string) $oObject->Get(MetaModel::GetStateAttributeCode($sClass)) : '';

		if ($oObject->IsNew()) {
			$iFlags = (int)$oObject->GetInitialStateAttributeFlags($sAttCode);
			// As on the console, a mandatory field is not read-only on a create: it could never be given otherwise.
			if (($iFlags & self::NOT_EDITABLE) === 0 || (($iFlags & OPT_ATT_MANDATORY) !== 0 && ($iFlags & OPT_ATT_HIDDEN) === 0)) {
				return null;
			}

			return "Attribute '{$sAttCode}' is not editable when a {$sClass} is created (initial state '{$sState}'). "
				."Create it without '{$sAttCode}', then set it with the stimulus that offers it.";
		}

		$iFlags = $oObject->GetAttributeFlags($sAttCode);
		if (($iFlags & self::NOT_EDITABLE) === 0) {
			return null;
		}
		// A data source owns it, and nothing a stimulus offers changes that.
		if (($iFlags & OPT_ATT_SLAVE) !== 0) {
			return "Attribute '{$sAttCode}' is kept up to date by a synchronisation data source, and is read-only on this {$sClass}.";
		}
		if ($sStimulus !== null && self::isPromptedBy($oObject, $sAttCode, $sStimulus)) {
			return null;
		}

		if (!$bLifecycle) {
			return "Attribute '{$sAttCode}' is read-only on this {$sClass}.";
		}

		return "Attribute '{$sAttCode}' is not editable while this {$sClass} is in state '{$sState}'. "
			."A transition that offers it can set it: core_object_get lists the stimuli this object accepts, "
			."and core_class_schema what each one asks for.";
	}

	/**
	 * Whether the stimulus's form would ask for $sAttCode: iTop's
	 * DisplayStimulusForm() prompts for what the target state flags
	 * must-change or must-prompt, and for what it makes mandatory while empty.
	 */
	private static function isPromptedBy(DBObject $oObject, string $sAttCode, string $sStimulus): bool
	{
		$sClass = get_class($oObject);
		$sState = (string) $oObject->Get(MetaModel::GetStateAttributeCode($sClass));
		$aTransitions = MetaModel::EnumTransitions($sClass, $sState);
		if (!isset($aTransitions[$sStimulus])) {
			return false;
		}
		$aStates = MetaModel::EnumStates($sClass);
		$iExpected = $aStates[$aTransitions[$sStimulus]['target_state']]['attribute_list'][$sAttCode] ?? 0;

		return ($iExpected & (OPT_ATT_MUSTCHANGE | OPT_ATT_MUSTPROMPT)) !== 0
			|| (($iExpected & OPT_ATT_MANDATORY) !== 0 && !$oObject->HasAValue($sAttCode));
	}

	/**
	 * A case log takes a new entry, written now and signed by the caller.
	 *
	 * ormCaseLog::FromJSON() would replace the whole log, and an add_item may
	 * carry a date (backdating) and, for an administrator, an author.
	 */
	private static function caseLogRefusal(string $sAttCode, mixed $value): ?string
	{
		if (is_string($value) || $value === null) {
			return null;
		}

		$aValue = is_object($value) ? (array) $value : $value;
		if (is_array($aValue) && array_keys($aValue) === ['add_item']) {
			$aEntry = is_object($aValue['add_item']) ? (array) $aValue['add_item'] : $aValue['add_item'];
			if (is_array($aEntry) && array_diff(array_keys($aEntry), self::CASE_LOG_ENTRY_KEYS) === []) {
				return null;
			}
		}

		return "Attribute '{$sAttCode}' is a case log: send the new entry as text, or as {\"add_item\": {\"message\": \"...\"}}. "
			."An entry is dated now and signed by this account, and earlier entries cannot be replaced.";
	}
}
