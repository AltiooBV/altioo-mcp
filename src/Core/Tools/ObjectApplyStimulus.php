<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use DBObjectSearch;
use DBObjectSet;
use RestUtils;

/**
 * Apply a lifecycle stimulus on an iTop object.
 *
 * Equivalent to the REST core/apply_stimulus operation.
 *
 * Example:
 *   class:    "UserRequest"
 *   id:       42
 *   stimulus: "ev_assign"
 *   fields:   { "agent_id": 3, "team_id": 12 }
 *   comment:  "Assigned via MCP"
 */
class ObjectApplyStimulus extends AbstractMCPTool
{

	public function getTitle(): ?string
	{
		return 'Apply Stimulus';
	}

	public function getDescription(): ?string
	{
		return 'Apply a lifecycle stimulus (state transition) on an iTop object. Use the itop://core/class/{classname} resource to discover available stimuli and states for a class before calling this tool.';
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'    => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Incident).',
				],
				'id'       => [
					'type'        => 'integer',
					'description' => 'ID of the object to transition.',
				],
				'stimulus' => [
					'type'        => 'string',
					'description' => 'Stimulus code to apply (e.g. ev_assign, ev_resolve, ev_close). Use itop://iTop/class/{classname} to list valid stimuli for the current state.',
				],
				'fields'   => [
					'type'                 => 'object',
					'description'          => 'Optional attribute values to set before applying the stimulus (e.g. agent_id, team_id for ev_assign).',
					'additionalProperties' => true,
				],
			],
			'required' => ['class', 'id', 'stimulus'],
		];
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Apply stimulus to iTop object',
			false,  // readOnlyHint
			false,  // destructiveHint — additive create
			false,  // idempotentHint — each call can create another row
			false,  // openWorldHint
		);
	}

	/**
	 * Apply a stimulus on an iTop object.
	 *
	 * @param string $class The class of the object to transition, e.g. 'UserRequest'
	 * @param int $id The ID of the object to transition, e.g. 42
	 * @param string $stimulus The stimulus code to apply, e.g. 'ev_assign'
	 * @param array $fields Optional attribute values to set before applying the stimulus, e.g. ['agent_id' => 3]
	 * @return array The new state of the object after applying the stimulus
	 * @throws ToolCallException if the class is unknown, if access is denied, if the object is not found, if the stimulus is invalid for the current state, or if mandatory attributes are missing.
	 */
	public static function execute(
		string $class,
		int $id,
		string $stimulus,
		array  $fields = [],
	): mixed {
		// Validate input parameters
		if ($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}
		if ($stimulus === '') {
			throw new ToolCallException("Stimulus cannot be empty. Please specify a valid stimulus code.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException("The database is in read-only mode, cannot apply stimulus on objects.");
		}

		if (MetaModel::IsAbstract($class)) {
			throw new ToolCallException("Class '{$class}' is abstract, cannot apply stimulus.");
		}
		if (!MetaModel::HasLifecycle($class)) {
			throw new ToolCallException("Class '{$class}' does not have a lifecycle, cannot apply stimulus.");
		}

		// Applying a stimulus is a modification
		if (!UserRights::IsActionAllowed($class, UR_ACTION_MODIFY)) {
			throw new ToolCallException("Access denied: cannot modify objects of class '{$class}'.");
		}


		// Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
		$sKey = MetaModel::DBGetKey($class);
		$oSearch = DBObjectSearch::FromOQL("SELECT {$class} WHERE {$sKey} = {$id}");
		$oSet = new DBObjectSet($oSearch);
		// Based on GetRelated - object search manages read access
		if ($oSet->Count() === 0) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		// Check the final class of the object
		$sFinalClass = MetaModel::GetFinalClassName($class, $id);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$sKeyFinal = MetaModel::DBGetKey($sFinalClass);
			$oSearchFinal = DBObjectSearch::FromOQL("SELECT {$sFinalClass} WHERE {$sKeyFinal} = {$id}");
			$oSetFinal = new DBObjectSet($oSearchFinal);
			// Based on GetRelated - object search manages read access
			if ($oSetFinal->Count() === 0) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			// Even if the rights are allowed, this is not the correct class to use.
			throw new ToolCallException("Object {$class}::{$id} is of class '{$sFinalClass}'. Rerun the apply stimulus with the correct final class."); // hide that the object exists
		}

		if (!UserRights::IsActionAllowed($class,  UR_ACTION_MODIFY, $oSet)) {
			throw new ToolCallException("Access denied: cannot update objects of class '{$class}'.");
		}
		// Validate its current state
		$oObject = $oSet->Fetch();
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot apply stimulus on object.");
		}
		$sCurrentState = $oObject->GetState();
		if ($sCurrentState === '') {
			throw new ToolCallException("Object {$class}::{$id} is in an invalid state, cannot apply stimulus.");
		}

		// Validate the stimulus is available on this class
		$aTransitions = MetaModel::EnumTransitions($class, $sCurrentState);
		if (!isset($aTransitions[$stimulus])) {
			$aAvailable = array_keys($aTransitions);
			throw new ToolCallException(
				"Invalid stimulus '{$stimulus}' on {$class}::{$id} in state '{$sCurrentState}'."
				.(empty($aAvailable) ? ' No transitions available.' : ' Available: '.implode(', ', $aAvailable).'.')
			);
		}

		// Check access rights on the stimulus (e.g. agent can apply ev_assign but not ev_reassign)
		if (!UserRights::IsStimulusAllowed($class,  $stimulus, $oSet)) {
			throw new ToolCallException("Access denied: cannot apply stimulus '{$stimulus}' to this object of class '{$class}'.");
		}

		// Set fields before applying the stimulus (e.g. agent_id for ev_assign)
		// Validate fields before applying any changes
		$aIssues = [];
		$aValidatedValues = [];
		foreach ($fields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
				$aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$class}'.";
				continue;
			}
			if (!UserRights::IsActionAllowedOnAttribute($class, $sAttCode, UR_ACTION_MODIFY)) {
				$aIssues[$sAttCode] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			$oAttDef = MetaModel::GetAttributeDef($class, $sAttCode);
			if (!$oAttDef->IsWritable()) {
				$aIssues[$sAttCode] = "Attribute '{$sAttCode}' is not writable.";
				continue;
			}
			try {
				$aValidatedValues[$sAttCode] = RestUtils::MakeValue($class, $sAttCode, $value);
			} catch (\Exception $e) {
				$aIssues[$sAttCode] = "Invalid value for attribute '{$sAttCode}': " . $e->getMessage();
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to apply stimulus due to fields : ".json_encode($aIssues));
		}
		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try
			{
				$oObject->Set($sAttCode, $realValue);
			}
			catch (\Exception $e)
			{
				$aIssues[$sAttCode] = "Failed to set  attribute '{$sAttCode}': " . $e->getMessage();
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to apply stimulus due to setting fields : " .implode(', ', $aIssues));
		}

		// Check for missing mandatory attributes before applying the stimulus, to provide a more helpful error message in this common case
		$aTransition = $aTransitions[$stimulus];
		$sTargetState = $aTransition['target_state'];
		$aStates = MetaModel::EnumStates($class);
		$aTargetStateDef = $aStates[$sTargetState];
		$aExpectedAttributes = $aTargetStateDef['attribute_list'] ?? [];

		$aMissingMandatory = array();
		foreach($aExpectedAttributes as $sAttCode => $iExpectCode)
		{
			// Soft comparison on purpose: Get() returns mixed (string, int, ormLinkSet,
			// AttributeDate...) depending on the attribute, so neither === '' nor
			// utils::IsNullOrEmptyString() (typed ?string) can stand in here.
			if (($iExpectCode & OPT_ATT_MANDATORY) && ($oObject->Get($sAttCode) == ''))
			{
				$aMissingMandatory[] = $sAttCode;
			}
		}
		if (!empty($aMissingMandatory)) {
			throw new ToolCallException(
				"Missing mandatory attribute(s) for applying stimulus '{$stimulus}': ".implode(', ', $aMissingMandatory).'.'
			);
		}

		// All validations passed, apply the stimulus
		$bApplied = false;
		try {
			$bApplied = $oObject->ApplyStimulus($stimulus);
		} catch (\Exception $e) {
			throw new ToolCallException("Failed to apply stimulus '{$stimulus}' on {$class}::{$id}: " . $e->getMessage());
		}
		// ApplyStimulus returns false if the state transition did not happen (e.g. due to a condition on the transition that is not met), but no exception is thrown in this case, so we need to check the return value to provide a helpful error message.
		if (!$bApplied) {
			throw new ToolCallException("Failed to apply stimulus '{$stimulus}' on {$class}::{$id}.");
		}

		return [
			'class'    => $class,
			MetaModel::DBGetKey($class)       => $id,
			'stimulus' => $stimulus,
			'state'    => $oObject->GetState(),
		];
	}
}
