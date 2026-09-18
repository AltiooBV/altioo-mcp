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
use DBObject;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;

/**
 * Create several objects of one class in one call.
 *
 * Unlike the other two bulk tools there are no ids to check, so the whole
 * question is the class right and the attribute rights - and the dry run,
 * which here is the only way to find out that the thirtieth row is missing a
 * mandatory attribute before the first twenty-nine exist.
 *
 * @since 1.0.0
 */
class ObjectBulkCreate extends AbstractBulkTool
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
		return 'Create Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Create several iTop objects of one class at once, each with its own attribute values. '
			.'The dry run validates every row without creating anything. '
			.'Rows are independent, so a call can partly succeed; the response reports every row separately, with the id of each object that was created.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Create iTop objects in bulk',
			false,  // readOnlyHint
			false,  // destructiveHint — additive
			false,  // idempotentHint — calling twice creates two sets of rows
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return self::reportSchema([
			'changes'    => [
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'Attribute code => the value this write set, or would set. '
					.'Read before the write, because the write clears it - so an attribute the write itself fills is empty here rather than wrong: a ticket answers "" for ref and friendlyname, which are assigned as the row is inserted. '
					.'These tools report no `after` block per row - a hundred rows would be a hundred reads - so take the id this entry reports and call core_object_get for what the object ended up with.',
			],
			'overridden' => WritePlan::OverriddenSchemaProperty(),
			'applied'    => [
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'The attributes this row supplied, as the object holds them after the write - so a value a computed attribute changed is the value you see. Unlike the single-object tools, this is not re-read from the database: a hundred rows would be a hundred queries.',
			],
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
				'objects' => [
					'type'        => 'array',
					'description' => 'One entry per object to create, each a key/value map of attribute codes. Call core_class_schema for the attribute codes of the class and which ones are mandatory.',
					'items'       => ['type' => MCPHelper::MAP_TYPE, 'additionalProperties' => true],
					'minItems'    => 1,
					'maxItems'    => self::MAX_OBJECTS,
				],
				'simulate' => [
					'type'        => 'boolean',
					'description' => 'true (the default) validates every entry without creating anything. Show the result to the user, then call again with simulate=false to create them.',
					'default'     => true,
				],
				'comment'  => ChangeTracking::CommentSchemaProperty('these objects are being created'),
			],
			'required' => ['class', 'objects'],
		];
	}

	/**
	 * @param string                          $class    The class to instantiate
	 * @param array<int, array<string, mixed>> $objects One attribute map per object
	 * @param bool                            $simulate When true (default), nothing is created
	 * @param string|null                     $comment  Why the batch is being created, recorded in the history of every object in it
	 *
	 * @return mixed A per-row report
	 *
	 * @throws ToolCallException When the class or the bulk right rules out the whole call.
	 */
	public static function execute(
		string  $class,
		array   $objects,
		bool    $simulate = true,
		?string $comment = null,
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = WritePlan::Simulated($simulate);

		if (empty($objects)) {
			throw new ToolCallException('No objects given.');
		}
		if (count($objects) > self::MAX_OBJECTS) {
			throw new ToolCallException(sprintf(
				'This call would create %d objects, and %d is the most one call may. Work in batches of %d.',
				count($objects),
				self::MAX_OBJECTS,
				self::MAX_OBJECTS
			));
		}

		// Creating in bulk is modifying in bulk as far as UserRights is
		// concerned: there is no UR_ACTION_BULK_CREATE, so the bulk gate is
		// the modify one and the single gate is create.
		self::checkBulkAllowed($class, UR_ACTION_BULK_MODIFY, UR_ACTION_CREATE, 'create');

		// Once for the batch, before the loop: the objects created by one call
		// are one decision, and they share the one change record.
		ChangeTracking::Explain($comment);

		$aOutcomes = [];
		foreach (array_values($objects) as $iRow => $aFields) {
			$aOutcomes[] = self::createOne($class, $iRow, is_array($aFields) ? $aFields : [], $simulate);
		}

		// Through report() like the other two, rather than a hand-rolled copy
		// of it: the defaults it applies are what make every entry carry the
		// keys the schema promises.
		return ToolOutput::Structured(self::report($class, $simulate, $aOutcomes, ['changes' => WritePlan::Map([]), 'overridden' => WritePlan::Map([]), 'applied' => WritePlan::Map([]), 'defaulted' => []]));
	}

	/**
	 * @param array<string, mixed> $aFields
	 *
	 * @return array<string, mixed>
	 */
	private static function createOne(string $sClass, int $iRow, array $aFields, bool $bSimulate): array
	{
		// Rows are reported by position: on a creation there is no id yet to
		// name them by, and a caller that sent thirty rows needs to know which
		// one failed. The id is reported all the same, as null until there is
		// one, so an entry has the same keys whatever became of it.
		if (empty($aFields)) {
			return self::outcome(null, $iRow, false, 'No fields given for this object.');
		}

		// Per row, because checkBulkAllowed() saw only the class: on a create
		// the owner is in the values, so whether this row is the caller's own
		// access cannot be known any earlier than here.
		$sAccessRefusal = AccessGrants::RefusalFor($sClass, null, $aFields);
		if ($sAccessRefusal !== null) {
			return self::outcome(null, $iRow, false, $sAccessRefusal);
		}

		[$aValues, $aIssues] = self::validatedValues($sClass, $aFields);
		if (!empty($aIssues)) {
			return self::outcome(null, $iRow, false, implode(' ', $aIssues));
		}

		// Declared before the try, because the catch reads them: a throw from
		// DBInsert() can land after the row was committed, and the entry that
		// then reports the object has to carry the same keys as one that
		// reported the dry run.
		$oObject     = null;
		$aChanges    = [];
		$aOverridden = [];

		try {
			$oObject = MetaModel::NewObject($sClass);
			foreach ($aValues as $sAttCode => $value) {
				$oObject->Set($sAttCode, $value);
			}

			// Before the check, because the check is what moves them:
			// CheckToWrite() runs DoComputeValues(), and a class that derives
			// an attribute from others overwrites whatever was just Set() into
			// it.
			$aRequested = WritePlan::Requested($oObject, $sClass, array_keys($aValues));

			// The check that catches a missing mandatory attribute before the
			// row is inserted, and so the whole reason a dry run here is worth
			// running.
			WritePlan::Check($oObject, "Row {$iRow}");

			$aOverridden = WritePlan::Overridden($oObject, $sClass, $aRequested);
			WritePlan::CheckRequested($oObject, $aRequested, $aOverridden, "Row {$iRow}");

			// Read before the insert on both paths: DBInsert() clears the
			// pending changes, so asking afterwards reports nothing and the
			// real call would answer with an empty `changes` where the dry run
			// answered with the object.
			$aChanges = WritePlan::Changes($oObject, $sClass);

			if ($bSimulate) {
				return self::outcome(null, $iRow, true, 'Would be created.')
					+ [
						'changes'    => WritePlan::Map($aChanges),
						'overridden' => WritePlan::Map($aOverridden),
						'applied'    => WritePlan::Map(self::suppliedValues($oObject, $sClass, array_keys($aValues))),
						'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValues)),
					];
			}

			$iId = $oObject->DBInsert();

			return self::outcome($iId, $iRow, true, 'Created.')
				+ WritePlan::Identity($sClass, $iId)
				+ [
					'changes'    => WritePlan::Map($aChanges),
					'overridden' => WritePlan::Map($aOverridden),
					'applied'    => WritePlan::Map(self::suppliedValues($oObject, $sClass, array_keys($aValues))),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValues)),
				];
		} catch (ToolCallException $e) {
			// One row that cannot be created does not cancel the others.
			return self::outcome(null, $iRow, false, $e->getMessage());
		} catch (\Throwable $e) {
			// A throw here does not mean this row wrote nothing. DBInsert()
			// commits in DBInsertNoReload() and only then reloads the object,
			// so anything raised by the second half - an after-write listener,
			// a reload of external values, a TypeError in this module's own
			// reporting - arrives with the row already in the database.
			//
			// Observed: a bulk create of two duplicate lnkContactToTicket rows
			// answered "succeeded: 0, failed: 2" with the first row committed.
			// Which of those threw is in that instance's log and not here; the
			// uniqueness rule is not it, since DoCheckUniqueness() runs inside
			// DoCheckToWrite() and so refuses before the insert - which is
			// exactly what the second row got, cleanly and by name.
			//
			// Reported as the success it is, with the failure attached rather
			// than substituted for it, exactly as core_object_create does:
			// "succeeded: 0" for a row that exists is the answer a caller
			// either retries, creating a second object, or passes on to a user
			// as a change that did not happen.
			$iCommittedId = WritePlan::CommittedId($oObject);
			if ($iCommittedId !== null) {
				return self::outcome($iCommittedId, $iRow, true, "Created, but the call failed after the write.")
					+ WritePlan::Identity($sClass, $iCommittedId)
					+ [
						'changes'    => WritePlan::Map($aChanges),
						'overridden' => WritePlan::Map($aOverridden),
						'warning'    => MCPHelper::OpaqueFailure(
							"Row {$iRow} was created with id {$iCommittedId}, but the call failed after the write",
							$e
						),
					];
			}

			// The ToolCallException branch above carries this module's own
			// refusals, which are what the caller fixes the row with. This one
			// carries the ORM's, which it does not.
			return self::outcome(null, $iRow, false, MCPHelper::OpaqueFailure("Row {$iRow} could not be created", $e));
		}
	}

	/**
	 * What this row supplied, as the object holds it.
	 *
	 * The single-object tools answer this by re-reading the row, which costs
	 * one query and tells the caller what a trigger did. A hundred rows is a
	 * hundred queries for a report nobody asked for row by row, so this reads
	 * the object in hand instead: after DBInsert() that object carries what was
	 * written, including whatever CheckToWrite() computed on the way, and stops
	 * short only of what an AfterInsert hook changed behind it.
	 *
	 * Through the serializer, so the per-attribute read rights, the clipping
	 * and the masking apply exactly as they do on a read.
	 *
	 * @param array<int, string> $aAttCodes The attributes this row supplied.
	 *
	 * @return array<string, mixed>
	 */
	private static function suppliedValues(DBObject $oObject, string $sClass, array $aAttCodes): array
	{
		if ($aAttCodes === []) {
			return [];
		}

		try {
			return array_intersect_key(
				ObjectSerializer::Serialize($oObject, $sClass, $aAttCodes),
				array_flip($aAttCodes)
			);
		} catch (\Throwable $e) {
			// A row that cannot be described is still a row that was written.
			return [];
		}
	}
}
