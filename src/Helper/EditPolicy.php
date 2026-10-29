<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

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

			$aRefusals[$sAttCode] = sprintf(
				"%s %d is not a value '%s' accepts on this %s. The datamodel limits it to: %s",
				$oAttDef->GetTargetClass(),
				$iValue,
				$sAttCode,
				$sClass,
				$oAttDef->GetValuesDef()->GetFilterExpression()
			);
		}

		return $aRefusals;
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
			if (($oObject->GetInitialStateAttributeFlags($sAttCode) & self::NOT_EDITABLE) === 0) {
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
