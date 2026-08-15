<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * Delete an existing iTop object.
 *
 * iTop's DBDelete handles cascading deletion plans automatically.
 * The response includes the deletion plan summary so the caller
 * knows what related objects were also affected.
 */
class ObjectDelete extends AbstractMCPTool
{

	public function getTitle(): ?string
	{
		return 'Delete Object';
	}

	public function getDescription(): ?string
	{
		return 'Delete an iTop object by class and ID. Related objects may also be deleted or modified according to iTop\'s cascading deletion rules.';
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
					'description' => 'iTop class name (e.g. UserRequest, Server). Use the itop://core/classes resource to list available classes.',
				],
				'id'  => [
					'type'        => 'integer',
					'description' => 'The ID of the object to delete.',
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
	 * @return array The result of the deletion operation
	 * @throws ToolCallException if the class is unknown, if access is denied, or if the object is not found.
	 */
	public static function execute(
		string $class,
		int    $id,
		bool    $simulate = true,
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
			$sKeyFinal = MetaModel::DBGetKey($class);
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

		$oDeletionPlan = new DeletionPlan();
		$aIssues = $oObject->CheckToDelete($oDeletionPlan);
		if (!empty($aIssues)) {
			throw new ToolCallExeception("Failed to delete due to issues : " .implode(', ', $aIssues));
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

		return [
			'class'        => $class,
			MetaModel::DBGetKey($class)          => $id,
			'simulated'    => $simulate,
			'deletionPlan' => self::serializeDeletionPlan($oDeletionPlan),
		];
	}

	private static function checkDeletionPlan(\DeletionPlan $oPlan): array
	{
		$aIssues = [];

		if ($oDeletionPlan->FoundStopper())
		{
			if ($oDeletionPlan->FoundSecurityIssue())
			{
				$aIssues[] = "The deletion cannot be performed because of access rights issues on some related objects. See the list of planned changes for more information about the affected objects and the required permissions.";
				if ($oDeletionPlan->FoundManualOperation())
				{
					$aIssues[] = "The deletion cannot be performed because it requires that other objects be deleted or updated, and those operations must be requested explicitely. See the list of planned changes for more information about the affected objects.";
				}
			}
			elseif ($oDeletionPlan->FoundManualOperation())
			{
				$aIssues[] = "The deletion cannot be performed because it requires that other objects be deleted or updated, and those operations must be requested explicitely. See the list of planned changes for more information about the affected objects.";
			}
			else {
				$aIssues[] = "The deletion cannot be performed because of issues on some related objects. See the list of planned changes for more information about the affected objects and the issues to be resolved.";
			}
		}

		// Check access rights on all objects that would be deleted or updated
		foreach ($oDeletionPlan->ListDeletes() as $sTargetClass => $aDeletes)
		{
			if (!UserRights::IsActionAllowed($sTargetClass, UR_ACTION_READ)) {
				$aIssues[] = "Access denied: cannot read related objects."; // hide the class not allowed
				continue;
			}
			if (!UserRights::IsActionAllowed($sTargetClass, UR_ACTION_DELETE)) {
				$aIssues[] = "Access denied: cannot delete related objects of class '{$sClass}'.";
				continue;
			}

			foreach ($aDeletes as $iId => $aData)
			{
				$oToDelete = $aData['to_delete'];
				$bAutoDel = (($aData['mode'] === DEL_SILENT) || ($aData['mode'] === DEL_AUTO));
				if (array_key_exists('issue', $aData))
				{
					if ($bAutoDel)
					{
						if (isset($aData['requested_explicitely'])) // i.e. in the initial list of objects to delete
						{
							$aIssues[] = "The object {$sTargetClass}::{$iId} cannot be deleted due to the following issue: {$aData['issue']}";
						} else {
							$aIssues[] = "The object {$sTargetClass}::{$iId} should be deleted automatically but an issue has been detected: {$aData['issue']}";
						}
					} else {
						$aIssues[] = "The object {$sTargetClass}::{$iId} cannot be deleted due to the following issue: {$aData['issue']}";
					}
				}
			}
		}
		foreach ($oDeletionPlan->ListUpdates() as $sTargetClass => $aToUpdate)
		{
			if (!UserRights::IsActionAllowed($sTargetClass, UR_ACTION_READ)) {
				$aIssues[] = "Access denied: cannot read related objects."; // hide the class not allowed
				continue;
			}
			if (!UserRights::IsActionAllowed($sTargetClass, UR_ACTION_MODIFY)) {
				$aIssues[] = "Access denied: cannot update related objects of class '{$sClass}'.";
				continue;
			}

			foreach ($aToUpdate as $iId => $aData)
			{
				$oToUpdate = $aData['to_reset'];
				if (array_key_exists('issue', $aData))
				{
					$aIssues[] = "The object {$sTargetClass}::{$iId} should be updated automatically but an issue has been detected: {$aData['issue']}";
				}
			}
		}

		return $aIssues;
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
