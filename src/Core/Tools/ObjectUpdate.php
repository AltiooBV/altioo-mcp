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
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
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
			.'The dry run reports exactly which attributes would change. '
			.'Read `overridden` on the answer as carefully as `changes`: a derived attribute is recomputed from the ones it derives from on every write, so supplying it directly changes nothing and is reported there rather than in `changes`.';
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
			'after' => WritePlan::AfterSchemaProperty(),
			'changes'    => WritePlan::ChangesSchemaProperty('Only the attributes this update actually modifies.'),
			'overridden' => WritePlan::OverriddenSchemaProperty(),
			'defaulted'  => WritePlan::DefaultedSchemaProperty(),
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
					'type'                 => MCPHelper::MAP_TYPE,
					'description'          => 'Key/value pairs to update. Keys are attribute codes. Call core_class_schema for the attribute codes of the class, their types and which ones are mandatory.',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('apply the change'),
				'obsolete_ok' => WritePlan::ObsoleteOkSchemaProperty('update'),
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
	 * @param bool $obsolete_ok Act on an obsolete object even though this account hides them
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
		bool    $obsolete_ok = false,
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = WritePlan::Simulated($simulate);

		if ($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}
		if (empty($fields)) {
			throw new ToolCallException('No fields provided for update.');
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}
		$sAccessRefusal = AccessGrants::RefusalFor($class, $id, $fields);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
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
		if (!UserRights::IsActionAllowed($class, UR_ACTION_MODIFY, $oSet)) {
			throw new ToolCallException("Access denied: cannot update objects of class '{$class}'.");
		}

		// Get the object
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$class}::{$id} is in read-only mode, cannot update object.");
		}

		// Before anything is validated: an object this account cannot see in
		// its own searches is one it is probably acting on from a stale id.
		WritePlan::RefuseHiddenObsolete($oObject, $sFinalClass, $obsolete_ok, 'update');

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
			// Asked before the ORM sees it: an id pointing at nothing is a
			// mistake the caller can correct, and iTop answers it with an
			// exception this module can only report opaquely.
			$sBadTarget = WritePlan::RefusalForExternalKey($class, $sAttCode, $value);
			if ($sBadTarget !== null) {
				$aIssues[$sAttCode] = $sBadTarget;
				continue;
			}

			try {
				// The SDK hands us arrays for nested JSON objects; RestUtils
				// branches on stdClass. See RestValue.
				$aValidatedValues[$sAttCode] = RestUtils::MakeValue($class, $sAttCode, RestValue::FromDecodedJson($value));
				// iTop's set attributes throw away an element they do not
				// recognise without saying so - see WritePlan. A value the
				// caller sent and the object will not hold is a refusal here,
				// the way a scalar enum already refuses one.
				$sDropped = WritePlan::RefusalForDroppedSetValues($class, $sAttCode, $value, $aValidatedValues[$sAttCode]);
				if ($sDropped !== null) {
					unset($aValidatedValues[$sAttCode]);
					$aIssues[$sAttCode] = $sDropped;
					continue;
				}
			} catch (\Throwable $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Invalid value for attribute '{$sAttCode}'", $e)
					.DatamodelReader::ValueHint($class, $sAttCode, $value);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to update due to fields : ".implode(', ', $aIssues));
		}

		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try {
				$oObject->Set($sAttCode, $realValue);
			} catch (\Throwable $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Failed to set attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to update due to setting fields : ".implode(', ', $aIssues));
		}

		// Before the check, because the check is what moves them: CheckToWrite()
		// runs DoComputeValues(), and a class that derives an attribute from
		// others overwrites whatever was just Set() into it. This is the last
		// moment the object holds what the caller asked for.
		$aRequested = WritePlan::Requested($oObject, $class, array_keys($aValidatedValues));

		// iTop's own pre-write check, run before anything is written rather
		// than discovered by DBUpdate() throwing from inside the ORM.
		WritePlan::Check($oObject, "{$class}::{$id}");

		// What the check threw away, and - for those attributes only - whether
		// what the caller sent was even a legal value. DoCheckToWrite() never
		// asked, because by the time it looked the value was gone.
		$aOverridden = WritePlan::Overridden($oObject, $class, $aRequested);
		WritePlan::CheckRequested($oObject, $aRequested, $aOverridden, "{$class}::{$id}");

		// After the check and before the write: this is the only point where
		// the pending values are still pending, and it is what tells the user
		// that "set status to closed" also cleared three other attributes.
		$aChanges = WritePlan::Changes($oObject, $class);

		if ($simulate) {
			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $id)
				+ [
					'simulated'  => true,
					'valid'      => true,
					'after'        => WritePlan::After($oObject, $class, array_keys($aValidatedValues), $simulate),
					'changes'    => WritePlan::Map($aChanges),
					'overridden' => WritePlan::Map($aOverridden),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValidatedValues)),
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
					'simulated'  => false,
					'valid'      => true,
					'after'        => WritePlan::After($oObject, $class, array_keys($aValidatedValues), $simulate),
					'changes'    => WritePlan::Map($aChanges),
					'overridden' => WritePlan::Map($aOverridden),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValidatedValues)),
				]);
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to update {$class}::{$id}", $e));
		}
	}
}
