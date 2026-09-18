<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use DBObject;
use DBObjectSet;
use DeletionPlan;

/**
 * Delete an existing iTop object.
 *
 * iTop's DBDelete handles cascading deletion plans automatically.
 * The response includes the deletion plan summary so the caller
 * knows what related objects were also affected.
 *
 * @since 1.0.0
 */
class ObjectDelete extends AbstractMCPTool
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
		return 'Delete Object';
	}

	public function getDescription(): ?string
	{
		return 'Delete an iTop object by class and ID. Related objects may also be deleted or modified according to iTop\'s cascading deletion rules. '
			.'The dry run reports the deletion plan: what else iTop would delete or update along with this object. '
			.'The whole cascade is checked against this user\'s rights, not just the object named here, so a deletion can be refused because of what it would reach; the refusal says which class is in the way.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Delete iTop object',
			false,  // readOnlyHint
			true,   // destructiveHint — permanent + cascade
			false,  // idempotentHint — 2nd call typically errors
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'deletionPlan' => WritePlan::DeletionPlanSchema(),
		]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name.',
				],
				'id'  => [
					'type'        => 'integer',
					'description' => 'The ID of the object to delete.',
					'minimum'     => 1,
				],
				'obsolete_ok' => WritePlan::ObsoleteOkSchemaProperty('delete'),
				'simulate' => [
					'type'        => 'boolean',
					'description' => 'true (the default) computes and returns the deletion plan without deleting anything. Show the plan to the user, then call again with simulate=false to delete.',
					'default'     => true,
				],
				'comment'  => ChangeTracking::CommentSchemaProperty('the object is being deleted'),
			],
			'required' => ['class', 'id'],
		];
	}

	/**
	 * Calls the object deletion.
	 *
	 * @param string $class The class of the object to delete
	 * @param int $id The ID of the object to delete
	 * @param bool $simulate When true (default), only the deletion plan is computed and returned
	 * @param bool $obsolete_ok Delete an obsolete object even though this account hides them
	 * @param string|null $comment Why the object is being deleted, recorded in the history of everything the deletion touches
	 * @return array The result of the deletion operation
	 * @throws ToolCallException if the class is unknown, if access is denied, if the object is not found, or if the deletion plan has a stopper.
	 */
	public static function execute(
		string  $class,
		int     $id,
		bool    $simulate = true,
		bool    $obsolete_ok = false,
		?string $comment = null,
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = WritePlan::Simulated($simulate);

		if ($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}
		$sAccessRefusal = AccessGrants::RefusalFor($class, $id);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException("The database is in read-only mode, cannot delete objects.");
		}

		if (MetaModel::IsAbstract($class)) {
			throw new ToolCallException("Class '{$class}' is abstract, cannot delete.");
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_DELETE)) {
			throw new ToolCallException("Access denied: cannot delete objects of class '{$class}'.");
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
			throw new ToolCallException("Object {$class}::{$id} is of class '{$sFinalClass}'. Rerun the delete with the correct final class."); // hide that the object exists
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_DELETE, $oSet)) {
			throw new ToolCallException(
				"Access denied: cannot delete objects of class '{$class}'.".self::retirementHint($oObject, $sFinalClass)
			);
		}
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot delete object.");
		}

		// Before the plan is computed: an object this account cannot see in its
		// own searches is one it is probably acting on from a stale id.
		WritePlan::RefuseHiddenObsolete($oObject, $sFinalClass, $obsolete_ok, 'delete');

		// CheckToDelete() returns a *boolean* (!FoundStopper()) and fills the
		// plan by reference; the reasons live on the plan itself.
		$oDeletionPlan = new DeletionPlan();
		if (!$oObject->CheckToDelete($oDeletionPlan)) {
			$aIssues = $oDeletionPlan->GetIssues();
			throw new ToolCallException(
				"Object {$class}::{$id} cannot be deleted."
				.(empty($aIssues)
					? ' Some related objects must be deleted or updated explicitly first.'
					: ' Reasons: '.implode(', ', $aIssues))
			);
		}

		// iTop builds the plan with rights off, so what it came back with may
		// reach classes this caller was never granted. Refused rather than
		// filtered, and refused on a dry run as well as on the real call - see
		// WritePlan::CheckDeletionRights().
		WritePlan::CheckDeletionRights($oDeletionPlan, "{$class}::{$id}");

		if (!$simulate) {
			// Said before the write: a deletion also updates the objects that
			// pointed at this one, and the reason belongs in their history too.
			ChangeTracking::Explain($comment);

			try {
				$oDeletionPlan = new DeletionPlan();
				$oObject->DBDelete($oDeletionPlan);
			} catch (\Throwable $e) {
				// A throw here does not mean the row is still there. DBDelete()
				// removes it and then runs what follows - the objects that
				// pointed at it, the AfterDelete hooks - so the failure can
				// arrive with the deletion already done, exactly as a creation's
				// can arrive with the row already written.
				//
				// Reporting that as a plain failure is what an operator acts on
				// when they are removing something on purpose: they are told the
				// cleanup did not work, and go looking for a record that is not
				// there. So the object is asked for rather than assumed, and a
				// deletion that happened is reported as one with the failure
				// attached - and only ever on positive evidence, since the
				// opposite mistake says a thing is gone when it is not.
				if (!WritePlan::IsGone($class, $id)) {
					throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to delete {$class}::{$id}", $e));
				}

				return ToolOutput::Structured(['class' => $class]
					+ WritePlan::Identity($class, $id)
					+ [
						'simulated'    => false,
						'valid'        => true,
						'deletionPlan' => WritePlan::SerializeDeletionPlan($oDeletionPlan),
						'warning'      => MCPHelper::OpaqueFailure(
							"The {$class}::{$id} was deleted, but the call failed after the deletion",
							$e
						),
					]);
			}
		}

		return ToolOutput::Structured(['class' => $class]
			+ WritePlan::Identity($class, $id)
			+ [
				'simulated'    => $simulate,
				'valid'        => true,
				'deletionPlan' => WritePlan::SerializeDeletionPlan($oDeletionPlan),
			]);
	}

	/**
	 * The other way to retire an object, when deleting it is refused.
	 *
	 * A profile that may not delete is the normal case rather than the
	 * exception - service desks retire tickets and decommission CIs through
	 * the lifecycle, and deletion is reserved for administrators. The refusal
	 * said only that the door was shut, so a caller had to know the datamodel
	 * to find the door that is open, and an agent asked to clean something up
	 * stopped there.
	 *
	 * Only transitions this caller may actually apply: StimuliOn() grades each
	 * one by UR_ACTION_MODIFY on the object and by IsStimulusAllowed(), and
	 * offering a transition that would itself be refused replaces one dead end
	 * with another.
	 *
	 * Guarded and silent when there is nothing to say - a class with no
	 * lifecycle, an object in a state with no way out, a caller who may not
	 * move it either. A refusal must not fail while explaining itself.
	 */
	private static function retirementHint(DBObject $oObject, string $sClass): string
	{
		try {
			$oInstanceSet = null;
			$aStimuli = ObjectSerializer::StimuliOn($oObject, $sClass, null, $oInstanceSet);
			if ($aStimuli === null) {
				return '';
			}

			$aAllowed = [];
			foreach ($aStimuli['available'] ?? [] as $aStimulus) {
				if (($aStimulus['allowed'] ?? 'no') !== 'no') {
					$aAllowed[] = (string)$aStimulus['stimulus'];
				}
			}

			if ($aAllowed === []) {
				return '';
			}

			return sprintf(
				' Retiring an object is usually a transition rather than a deletion: from state "%s" you may apply %s'
				.' with core_object_apply_stimulus.',
				(string)($aStimuli['state'] ?? ''),
				implode(', ', $aAllowed)
			);
		} catch (\Throwable $e) {
			return '';
		}
	}
}
