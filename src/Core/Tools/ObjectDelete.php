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
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
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
			.'Runs as a dry run by default: call it with simulate=true to obtain the deletion plan, show that plan to the user, and only then call it again with simulate=false to delete for real. '
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
				'simulate' => [
					'type'        => 'boolean',
					'description' => 'true (the default) computes and returns the deletion plan without deleting anything. Set it to false to actually delete, once the plan has been confirmed by the user.',
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
	 * @param string|null $comment Why the object is being deleted, recorded in the history of everything the deletion touches
	 * @return array The result of the deletion operation
	 * @throws ToolCallException if the class is unknown, if access is denied, if the object is not found, or if the deletion plan has a stopper.
	 */
	public static function execute(
		string  $class,
		int     $id,
		bool    $simulate = true,
		?string $comment = null,
	): mixed
	{
		if ($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
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

		if (!UserRights::IsActionAllowed($class,  UR_ACTION_DELETE, $oSet)) {
			throw new ToolCallException("Access denied: cannot delete objects of class '{$class}'.");
		}
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot delete object.");
		}

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
				throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to delete {$class}::{$id}", $e));
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
}
