<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use DBObjectSearch;
use DBObjectSet;
use DeletionPlan;

/**
 * Delete an existing iTop object.
 *
 * iTop's DBDelete handles cascading deletion plans automatically.
 * The response includes the deletion plan summary so the caller
 * knows what related objects were also affected.
 */
class ObjectDelete extends AbstractMCPTool
{

	public function getNamespace(): string
	{
		return 'core';
	}

	public function getTitle(): ?string
	{
		return 'Delete Object';
	}

	public function getDescription(): ?string
	{
		return 'Delete an iTop object by class and ID. Related objects may also be deleted or modified according to iTop\'s cascading deletion rules. '
			.'Runs as a dry run by default: call it with simulate=true to obtain the deletion plan, show that plan to the user, and only then call it again with simulate=false to delete for real.';
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

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_ClassList to find the class name.',
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
	 * @return array The result of the deletion operation
	 * @throws ToolCallException if the class is unknown, if access is denied, if the object is not found, or if the deletion plan has a stopper.
	 */
	public static function execute(
		string $class,
		int    $id,
		bool   $simulate = true,
	): mixed {
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
			throw new ToolCallException("Object {$class}::{$id} is of class '{$sFinalClass}'. Rerun the delete with the correct final class."); // hide that the object exists
		}

		if (!UserRights::IsActionAllowed($class,  UR_ACTION_DELETE, $oSet)) {
			throw new ToolCallException("Access denied: cannot delete objects of class '{$class}'.");
		}
		$oObject = $oSet->Fetch();
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

		if (!$simulate)
		{
			try {
				$oDeletionPlan = new DeletionPlan();
				$oObject->DBDelete($oDeletionPlan);
			} catch (\Exception $e) {
				throw new ToolCallException("Failed to delete object: " . $e->getMessage());
			}
		}

		return ToolOutput::Json([
			'class'        => $class,
			MetaModel::DBGetKey($class)          => $id,
			'simulated'    => $simulate,
			'deletionPlan' => self::serializeDeletionPlan($oDeletionPlan),
		]);
	}

	/**
	 * Serializes a deletion plan into an array.
	 *
	 * @param ?\DeletionPlan $oPlan The deletion plan to serialize
	 * @return array The serialized deletion plan
	 */
	private static function serializeDeletionPlan(?\DeletionPlan $oPlan): array
	{
		if ($oPlan === null) {
			return [
				'deleted'  => [],
				'updated' => [],
			];
		}

		$aDeleted  = [];
		$aUpdated = [];

		foreach ($oPlan->ListDeletes() as $sClass => $aObjects) {
			foreach ($aObjects as $iId => $aData) {
				$aDeleted[] = ['class' => $sClass, MetaModel::DBGetKey($sClass) => $iId];
			}
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aObjects) {
			foreach ($aObjects as $iId => $aData) {
				$aUpdated[] = ['class' => $sClass, MetaModel::DBGetKey($sClass) => $iId];
			}
		}

		return [
			'deleted'  => $aDeleted,
			'updated' => $aUpdated,
		];
	}
}
