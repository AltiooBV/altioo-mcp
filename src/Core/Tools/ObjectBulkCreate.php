<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
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
 */
class ObjectBulkCreate extends AbstractBulkTool
{
	public function getTitle(): ?string
	{
		return 'Create Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Create several iTop objects of one class at once, each with its own attribute values. '
			.'Runs as a dry run by default: call it with simulate=true to have every row validated without creating anything, show the result to the user, then call again with simulate=false. '
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
					'items'       => ['type' => 'object', 'additionalProperties' => true],
					'minItems'    => 1,
					'maxItems'    => self::MAX_OBJECTS,
				],
				'simulate' => [
					'type'        => 'boolean',
					'description' => 'true (the default) validates every entry without creating anything. Show the result to the user, then call again with simulate=false to create them.',
					'default'     => true,
				],
			],
			'required' => ['class', 'objects'],
		];
	}

	/**
	 * @param string                          $class    The class to instantiate
	 * @param array<int, array<string, mixed>> $objects One attribute map per object
	 * @param bool                            $simulate When true (default), nothing is created
	 *
	 * @return mixed A per-row report
	 *
	 * @throws ToolCallException When the class or the bulk right rules out the whole call.
	 */
	public static function execute(
		string $class,
		array  $objects,
		bool   $simulate = true,
	): mixed {
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

		$aOutcomes = [];
		foreach (array_values($objects) as $iRow => $aFields) {
			$aOutcomes[] = self::createOne($class, $iRow, is_array($aFields) ? $aFields : [], $simulate);
		}

		$iOk = count(array_filter($aOutcomes, static fn (array $a): bool => $a['status'] === 'ok'));

		return ToolOutput::Json([
			'class'     => $class,
			'simulated' => $simulate,
			'total'     => count($aOutcomes),
			'succeeded' => $iOk,
			'failed'    => count($aOutcomes) - $iOk,
			'objects'   => $aOutcomes,
		]);
	}

	/**
	 * @param array<string, mixed> $aFields
	 *
	 * @return array<string, mixed>
	 */
	private static function createOne(string $sClass, int $iRow, array $aFields, bool $bSimulate): array
	{
		// Rows are reported by position: there is no id yet to name them by,
		// and a caller that sent thirty rows needs to know which one failed.
		$aOutcome = ['row' => $iRow, 'status' => 'error'];

		if (empty($aFields)) {
			$aOutcome['message'] = 'No fields given for this object.';

			return $aOutcome;
		}

		[$aValues, $aIssues] = self::validatedValues($sClass, $aFields);
		if (!empty($aIssues)) {
			$aOutcome['message'] = implode(' ', $aIssues);

			return $aOutcome;
		}

		try {
			$oObject = MetaModel::NewObject($sClass);
			foreach ($aValues as $sAttCode => $value) {
				$oObject->Set($sAttCode, $value);
			}

			// CheckToWrite() returns [ok, issues]. It is what catches a missing
			// mandatory attribute before the row is inserted, and so the whole
			// reason a dry run here is worth running.
			[$bOk, $aWriteIssues] = $oObject->CheckToWrite();
			if (!$bOk) {
				$aOutcome['message'] = empty($aWriteIssues)
					? 'This object cannot be created as described.'
					: implode(' ', $aWriteIssues);

				return $aOutcome;
			}

			if ($bSimulate) {
				return ['row' => $iRow, 'status' => 'ok', 'message' => 'Would be created.'];
			}

			$iId = $oObject->DBInsert();

			return ['row' => $iRow, 'status' => 'ok', MetaModel::DBGetKey($sClass) => $iId];
		} catch (\Exception $e) {
			$aOutcome['message'] = $e->getMessage();

			return $aOutcome;
		}
	}
}
