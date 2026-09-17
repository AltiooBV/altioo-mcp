<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Abstract\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use DBObject;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Set the same attributes on a list of objects.
 *
 * The bulk case that comes up constantly - reassign these twelve tickets,
 * retire these thirty servers - and the one a model otherwise does by calling
 * core_object_update twelve times.
 *
 * @since 1.0.0
 */
class ObjectBulkUpdate extends AbstractBulkTool
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
		return 'Update Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Set the same attributes on several iTop objects of one class at once. '
			.'The dry run reports what would change and which objects this user may not touch. '
			.'Each object is checked on its own, so a call can partly succeed; the response reports every object separately.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Update iTop objects in bulk',
			false,  // readOnlyHint
			false,  // destructiveHint — modifies, does not destroy
			true,   // idempotentHint — the same values set twice land the same way
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return self::reportSchema([
			'changes'    => [
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'Attribute code => the value this write set, or would set.',
			],
			'overridden' => WritePlan::OverriddenSchemaProperty(),
		]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => self::targetSchemaProperties('apply the change') + [
				'fields' => [
					'type'                 => 'object',
					'description'          => 'Key/value pairs applied to every listed object. Keys are attribute codes. Call core_class_schema for the attribute codes of the class, their types and which ones are mandatory.',
					'additionalProperties' => true,
				],
			],
			'required' => ['class', 'ids', 'fields'],
		];
	}

	/**
	 * @param string             $class    The class every listed object belongs to
	 * @param array<int, mixed>  $ids      Ids of the objects to update
	 * @param array<string, mixed> $fields  Attribute values applied to all of them
	 * @param bool               $simulate When true (default), nothing is written
	 * @param string|null        $comment  Why the batch is being applied, recorded in the history of every object in it
	 *
	 * @return mixed A per-object report
	 *
	 * @throws ToolCallException When the class, the ids or the bulk right rule out the whole call.
	 */
	public static function execute(
		string  $class,
		array   $ids,
		array   $fields,
		bool    $simulate = true,
		?string $comment = null,
	): mixed
	{
		if (empty($fields)) {
			throw new ToolCallException('No fields provided for update.');
		}

		$aIds = self::checkIds($ids);
		self::checkBulkAllowed($class, UR_ACTION_BULK_MODIFY, UR_ACTION_MODIFY, 'modify');

		// The class-level gate, before a single object is read: an attribute
		// this caller may not write on the class is refused for the whole call
		// rather than a hundred times over.
		self::checkAttributesWritable($class, $fields);

		// Once for the batch, before the loop: forty objects changed by one
		// call are one decision, and they share the one change record.
		ChangeTracking::Explain($comment);

		$aOutcomes = [];
		foreach ($aIds as $iRow => $iId) {
			$mObject = self::objectFor($class, $iId, UR_ACTION_MODIFY, 'modify');
			if (!$mObject instanceof DBObject) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, $mObject);
				continue;
			}

			// Per row, because checkBulkAllowed() saw only the class: whether a
			// row is the caller's own access is a property of the row.
			$sAccessRefusal = AccessGrants::RefusalFor($class, $iId, $fields);
			if ($sAccessRefusal !== null) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, $sAccessRefusal);
				continue;
			}

			// Per object: the attribute rights of a User are not the attribute
			// rights of another User, and the class-level gate above cannot
			// tell them apart. The object goes in as a set of one, which is how
			// iTop is asked about one object.
			[$aValues, $aIssues] = self::validatedValues($class, $fields, DBObjectSet::FromObject($mObject));
			if (!empty($aIssues)) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, implode(' ', $aIssues));
				continue;
			}

			try {
				foreach ($aValues as $sAttCode => $value) {
					$mObject->Set($sAttCode, $value);
				}

				// Before the check, because the check is what moves them:
				// CheckToWrite() runs DoComputeValues(), and a class that
				// derives an attribute from others overwrites whatever was
				// just Set() into it. Per object, because a value can survive
				// on one row and be recomputed away on the next.
				$aRequested = WritePlan::Requested($mObject, $class, array_keys($aValues));

				// Per object, and before the write: the same values can be
				// valid on one object and not on the next - a state that
				// forbids the transition, a DoCheckToWrite() that reads other
				// attributes - and without this the answer arrives as an
				// exception from the ORM half way through the batch.
				WritePlan::Check($mObject, "{$class}::{$iId}");

				$aOverridden = WritePlan::Overridden($mObject, $class, $aRequested);
				WritePlan::CheckRequested($mObject, $aRequested, $aOverridden, "{$class}::{$iId}");

				$aOutcome = self::outcome($iId, $iRow, true, $simulate ? 'Would be updated.' : 'Updated.');
				$aOutcome['changes']    = WritePlan::Changes($mObject, $class);
				$aOutcome['overridden'] = $aOverridden;

				if (!$simulate) {
					$mObject->DBUpdate();
				}

				$aOutcomes[] = $aOutcome;
			} catch (ToolCallException $e) {
				// One object that cannot take the change does not cancel the
				// other thirty-nine; it is reported as its own failure.
				$aOutcomes[] = self::outcome($iId, $iRow, false, $e->getMessage());
			} catch (\Throwable $e) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, MCPHelper::OpaqueFailure("{$class}::{$iId} could not be updated", $e));
			}
		}

		// An entry that failed before anything was set still reports changes,
		// empty: the schema promises it on every entry.
		return ToolOutput::Structured(self::report($class, $simulate, $aOutcomes, ['changes' => [], 'overridden' => []]));
	}
}
