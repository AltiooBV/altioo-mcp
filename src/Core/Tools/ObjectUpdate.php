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
use RestUtils;
use DBObjectSet;

/**
 * Update attributes on an existing iTop object.
 *
 * Only the provided fields are updated; omitted attributes are left untouched.
 *
 * @since 1.0.0
 */
class ObjectUpdate extends AbstractMCPTool
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
		return 'Update Object';
	}

	public function getDescription(): ?string
	{
		return 'Update one or more attributes of an existing iTop object. Only provided fields are modified; omitted attributes are left untouched. '
			.'Runs as a dry run by default: call it with simulate=true to have iTop validate the change and report exactly which attributes would change, show that to the user, then call again with simulate=false to apply it.';
	}

	public function getAnnotations(): ?ToolAnnotations
{
	return new ToolAnnotations(
		$this->getTitle() ?? 'Update iTop object',
		false,  // readOnlyHint
		false,  // destructiveHint — modifies, but not “delete/overwrite world”
		true,   // idempotentHint
		false,  // openWorldHint — closed CMDB domain
	);
}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'changes' => WritePlan::ChangesSchemaProperty('Only the attributes this update actually modifies.'),
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
					'description' => 'The ID of the object to update.',
				],
				'fields' => [
					'type'                 => 'object',
					'description'          => 'Key/value pairs to update. Keys are attribute codes. Call core_class_schema for the attribute codes of the class, their types and which ones are mandatory.',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('apply the change'),
				'comment'  => ChangeTracking::CommentSchemaProperty('the change is being made'),
			],
			'required' => ['class', 'id', 'fields'],
		];
	}

	/**
	 * @param string $class The class of the object to update, e.g. 'UserRequest'
	 * @param int $id The ID of the object to update, e.g. 123
	 * @param array $fields An array of attribute => value pairs to update
	 * @param bool $simulate When true (default), the change is validated and described but not written
	 * @param string|null $comment Why the change is being made, recorded in the object's history
	 * @return array The class and ID of the object, and the attributes the call changes or would change
	 * @throws ToolCallException if the class is unknown, if access is denied, or if the object is not found.
	 */
	public static function execute(
		string  $class,
		int     $id,
		array   $fields,
		bool    $simulate = WritePlan::SIMULATE_BY_DEFAULT,
		?string $comment = null,
	): mixed {
		if ($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}
		if (empty($fields)) {
			throw new ToolCallException('No fields provided for update.');
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException("The database is in read-only mode, cannot update objects.");
		}

		if (MetaModel::IsAbstract($class)) {
			throw new ToolCallException("Class '{$class}' is abstract, cannot update.");
		}

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
			throw new ToolCallException("Object {$class}::{$id} is of class '{$sFinalClass}'. Rerun the update with the correct final class."); // hide that the object exists
		}
		if (!UserRights::IsActionAllowed($class,  UR_ACTION_MODIFY, $oSet)) {
			throw new ToolCallException("Access denied: cannot update objects of class '{$class}'.");
		}

		// Get the object
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot update object.");
		}

		// A set holding this object and nothing else, built fresh: $oSet has
		// been Fetch()ed above and its cursor is spent, and the rights addon is
		// free to iterate whatever it is handed.
		$oInstanceSet = DBObjectSet::FromObject($oObject);

		// Validate fields before applying any changes
		$aIssues = [];
		$aValidatedValues = [];
		foreach ($fields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
				$aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$class}'.";
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
			throw new ToolCallException("Failed to update due to fields : ".implode(', ', $aIssues));
		}

		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try
			{
				$oObject->Set($sAttCode, $realValue);
			}
			catch (\Exception $e)
			{
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Failed to set attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to update due to setting fields : " .implode(', ', $aIssues));
		}

		// iTop's own pre-write check, run before anything is written rather
		// than discovered by DBUpdate() throwing from inside the ORM.
		WritePlan::Check($oObject, "{$class}::{$id}");

		// After the check and before the write: this is the only point where
		// the pending values are still pending, and it is what tells the user
		// that "set status to closed" also cleared three other attributes.
		$aChanges = WritePlan::Changes($oObject, $class);

		if ($simulate) {
			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $id)
				+ [
					'simulated' => true,
					'valid'     => true,
					'changes'   => $aChanges,
				]);
		}

		// Said before the write, because the change record is built by the
		// write itself and reads what was last said.
		ChangeTracking::Explain($comment);

		try {
			$oObject->DBUpdate();

			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $id)
				+ [
					'simulated' => false,
					'valid'     => true,
					'changes'   => $aChanges,
				]);
		} catch (\Exception $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to update {$class}::{$id}", $e));
		}
	}
}
