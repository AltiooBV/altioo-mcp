<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
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
 *
 * @since 1.0.0
 */
class ObjectApplyStimulus extends AbstractMCPTool
{

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves. */
	public function getToolset(): string
	{
		return 'objects';
	}


	protected function defaultTitle(): string
	{
		return 'Apply Stimulus';
	}

	public function getDescription(): ?string
	{
		return 'Apply a lifecycle stimulus (state transition) on an iTop object. Call core_class_schema first: it reports the states, the stimuli, and which attributes each transition needs. '
			.'Runs as a dry run by default: call it with simulate=true to check that the transition is allowed from the current state and that nothing mandatory is missing, show that to the user, then call again with simulate=false to apply it.';
	}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'stimulus'      => [
				'type'        => 'string',
				'description' => 'The stimulus that was applied.',
			],
			'state'         => [
				'type'        => 'string',
				'description' => 'The state the object is in now: the one it reached on a real call, the one it is still in on a dry run.',
			],
			'would_move_to' => [
				'type'        => 'string',
				'description' => 'The state this transition targets. On a dry run it is where the object would go; on a real call the object is already there, so it equals state. Compare the two to see whether the call moved anything.',
			],
			'changes'       => WritePlan::ChangesSchemaProperty('The attributes the transition set, including the ones the lifecycle filled in by itself.'),
		]);
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
					'description' => 'Stimulus code to apply (e.g. ev_assign, ev_resolve, ev_close). Call core_class_schema for the stimuli valid in the current state.',
				],
				'fields'   => [
					'type'                 => 'object',
					'description'          => 'Optional attribute values to set before applying the stimulus (e.g. agent_id, team_id for ev_assign).',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('apply the stimulus'),
				'comment'  => ChangeTracking::CommentSchemaProperty('the transition is being applied'),
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
	 * @param bool $simulate When true (default), the transition is validated but not applied
	 * @param string|null $comment Why the transition is being applied, recorded in the object's history
	 * @return array The state the object is in, or the state it would move to
	 * @throws ToolCallException if the class is unknown, if access is denied, if the object is not found, if the stimulus is invalid for the current state, or if mandatory attributes are missing.
	 */
	public static function execute(
		string  $class,
		int     $id,
		string  $stimulus,
		array   $fields = [],
		bool    $simulate = WritePlan::SIMULATE_BY_DEFAULT,
		?string $comment = null,
	): mixed
	{
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
		$oSearch = ObjectQuery::ById($class, $id);
		$oSet = new DBObjectSet($oSearch);
		// Based on GetRelated - object search manages read access
		if ($oSet->Count() === 0) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		// Fetch() instantiates the leaf from the finalclass column it has
		// already read, so the object in hand names its own class.
		// GetFinalClassName() was a query asking for something that had already
		// arrived. Rewound immediately: IsActionAllowed() below is handed this
		// same set, and the rights addon is free to iterate it.
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$oSetFinal = new DBObjectSet(ObjectQuery::ById($sFinalClass, $id));
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

		// A set holding this object and nothing else, built fresh: $oSet has
		// been Fetch()ed above and its cursor is spent, and the rights addon is
		// free to iterate whatever it is handed.
		$oInstanceSet = DBObjectSet::FromObject($oObject);

		// Set fields before applying the stimulus (e.g. agent_id for ev_assign)
		// Validate fields before applying any changes
		$aIssues = [];
		$aValidatedValues = [];
		foreach ($fields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
				$aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$class}'.";
				continue;
			}
			// Only UR_ALLOWED_YES is a yes - this is tri-state, and a truthy
			// test reads UR_ALLOWED_DEPENDS as permission. The mono set is
			// passed for the addon that grades per object; the shipped one does
			// not. See ObjectUpdate for the long version.
			if (UserRights::IsActionAllowedOnAttribute($class, $sAttCode, UR_ACTION_MODIFY, $oInstanceSet) !== UR_ALLOWED_YES) {
				$aIssues[$sAttCode] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			$oAttDef = MetaModel::GetAttributeDef($class, $sAttCode);
			if (!$oAttDef->IsWritable()) {
				$aIssues[$sAttCode] = "Attribute '{$sAttCode}' is not writable.";
				continue;
			}
			try {
				// The SDK hands us arrays for nested JSON objects; RestUtils
				// branches on stdClass. See RestValue.
				$aValidatedValues[$sAttCode] = RestUtils::MakeValue($class, $sAttCode, RestValue::FromDecodedJson($value));
			} catch (\Exception $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Invalid value for attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to apply stimulus due to fields : ".json_encode($aIssues));
		}
		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try {
				$oObject->Set($sAttCode, $realValue);
			} catch (\Exception $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Failed to set attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to apply stimulus due to setting fields : ".implode(', ', $aIssues));
		}

		// Check for missing mandatory attributes before applying the stimulus, to provide a more helpful error message in this common case
		$aTransition = $aTransitions[$stimulus];
		$sTargetState = $aTransition['target_state'];
		$aStates = MetaModel::EnumStates($class);
		$aTargetStateDef = $aStates[$sTargetState];
		$aExpectedAttributes = $aTargetStateDef['attribute_list'] ?? [];

		// Two buckets, not one list: an empty mandatory attribute the caller may
		// set is a call it can correct, and one it may not set is a call that
		// will never work. Told only "missing mandatory: a, b, c", a model sets
		// all three, is refused on c, and cannot tell a typo from a denial.
		$aMissingFillable = [];
		$aMissingBlocked  = [];

		foreach ($aExpectedAttributes as $sAttCode => $iExpectCode) {
			// Soft comparison on purpose: Get() returns mixed (string, int, ormLinkSet,
			// AttributeDate...) depending on the attribute, so neither === '' nor
			// utils::IsNullOrEmptyString() (typed ?string) can stand in here.
			if (!($iExpectCode & OPT_ATT_MANDATORY) || ($oObject->Get($sAttCode) != '')) {
				continue;
			}

			$sBlocked = self::whyItCannotBeSet($class, $sAttCode, $oInstanceSet);
			if ($sBlocked !== null) {
				$aMissingBlocked[$sAttCode] = $sBlocked;
				continue;
			}

			$aMissingFillable[] = $sAttCode;
		}

		// Both at once. Raising the fillable ones first and the blocked ones
		// only on the next call means the caller sets a and b onto an object
		// for a transition it was never going to be allowed to complete, and
		// learns that on a second round trip.
		if (!empty($aMissingFillable) || !empty($aMissingBlocked)) {
			throw new ToolCallException(self::missingMandatoryMessage($stimulus, $aMissingFillable, $aMissingBlocked));
		}

		// iTop's own pre-write check, on top of the target-state check above:
		// that one knows what the transition requires, this one knows what the
		// class and its extensions require of any write.
		WritePlan::Check($oObject, "{$class}::{$id}");
		$aChanges = WritePlan::Changes($oObject, $class);

		if ($simulate) {
			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $id)
				+ [
					'stimulus'      => $stimulus,
					'simulated'     => true,
					'valid'         => true,
					'state'         => $sCurrentState,
					'would_move_to' => $sTargetState,
					'changes'       => $aChanges,
				]);
		}

		// Said before the write, because the change record is built by the
		// write itself and reads what was last said.
		ChangeTracking::Explain($comment);

		// All validations passed, apply the stimulus
		$bApplied = false;
		try {
			$bApplied = $oObject->ApplyStimulus($stimulus);
		} catch (\Exception $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to apply stimulus '{$stimulus}' on {$class}::{$id}", $e));
		}
		// ApplyStimulus returns false if the state transition did not happen (e.g. due to a condition on the transition that is not met), but no exception is thrown in this case, so we need to check the return value to provide a helpful error message.
		if (!$bApplied) {
			throw new ToolCallException("Failed to apply stimulus '{$stimulus}' on {$class}::{$id}.");
		}

		return ToolOutput::Structured(['class' => $class]
			+ WritePlan::Identity($class, $id)
			+ [
				'stimulus'      => $stimulus,
				'simulated'     => false,
				'valid'         => true,
				'state'         => $oObject->GetState(),
				// The target is reported on a real call too, so the shape does
				// not depend on simulate. The object is in it by now, which is
				// what makes the pair readable: state === would_move_to says
				// the transition happened.
				'would_move_to' => $sTargetState,
				'changes'       => $aChanges,
			]);
	}
	/**
	 * Why this caller cannot set an attribute the target state requires, or
	 * null when it can.
	 *
	 * Two reasons, and they are not the same problem. A right is a grant an
	 * administrator can make; an attribute the datamodel declares unwritable is
	 * not writable by anybody, and no grant changes that. Saying which one it
	 * is decides whether the caller has anyone to ask.
	 *
	 * Both are checked, in that order, exactly as the field validation earlier
	 * in this method checks them - it asked the right and then IsWritable(),
	 * and the mandatory-attribute check asked only the right, so a mandatory
	 * attribute that is read-only on the class was reported as one the caller
	 * should fill in.
	 *
	 * UR_ALLOWED_DEPENDS counts as a no: the object is in hand, so the addon
	 * has been given everything it needs to answer, and anything short of a yes
	 * is a no. See ObjectUpdate for the long version.
	 */
	private static function whyItCannotBeSet(string $sClass, string $sAttCode, DBObjectSet $oInstanceSet): ?string
	{
		if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY, $oInstanceSet) !== UR_ALLOWED_YES) {
			return 'write access denied for this user';
		}

		if (!MetaModel::GetAttributeDef($sClass, $sAttCode)->IsWritable()) {
			return 'not writable on this class';
		}

		return null;
	}

	/**
	 * One refusal naming everything the transition is missing, and separating
	 * what the caller can do something about from what it cannot.
	 *
	 * Blocked attributes lead, because they decide whether retrying is worth
	 * anything at all: a caller that reads only the first sentence should come
	 * away knowing the call cannot succeed, rather than setting the fillable
	 * ones and arriving back here.
	 *
	 * @param array<int, string>    $aFillable Attribute codes this caller may set.
	 * @param array<string, string> $aBlocked  Attribute code => why it cannot be set.
	 */
	private static function missingMandatoryMessage(string $sStimulus, array $aFillable, array $aBlocked): string
	{
		if (empty($aBlocked)) {
			return "Missing mandatory attribute(s) for applying stimulus '{$sStimulus}': ".implode(', ', $aFillable).'.';
		}

		$aNamed = [];
		foreach ($aBlocked as $sAttCode => $sReason) {
			$aNamed[] = "{$sAttCode} ({$sReason})";
		}

		$sMessage = "Stimulus '{$sStimulus}' cannot be applied: the target state requires mandatory attribute(s) this call cannot set: "
			.implode(', ', $aNamed).'. Retrying will not help - the missing rights have to be granted, or those attributes set another way.';

		if (!empty($aFillable)) {
			$sMessage .= ' Also missing, and settable by this user: '.implode(', ', $aFillable)
				.' - setting those alone will not be enough.';
		}

		return $sMessage;
	}
}
