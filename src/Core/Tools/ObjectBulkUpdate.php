<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use DBObject;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Set the same attributes on a list of objects.
 *
 * The bulk case that comes up constantly - reassign these twelve tickets,
 * retire these thirty servers - and the one a model otherwise does by calling
 * core_object_update twelve times.
 */
class ObjectBulkUpdate extends AbstractBulkTool
{
	public function getTitle(): ?string
	{
		return 'Update Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Set the same attributes on several iTop objects of one class at once. '
			.'Runs as a dry run by default: call it with simulate=true to see what would change and which objects the current user may not touch, show that to the user, then call again with simulate=false. '
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
	 *
	 * @return mixed A per-object report
	 *
	 * @throws ToolCallException When the class, the ids or the bulk right rule out the whole call.
	 */
	public static function execute(
		string $class,
		array  $ids,
		array  $fields,
		bool   $simulate = true,
	): mixed {
		if (empty($fields)) {
			throw new ToolCallException('No fields provided for update.');
		}

		$aIds = self::checkIds($ids);
		self::checkBulkAllowed($class, UR_ACTION_BULK_MODIFY, UR_ACTION_MODIFY, 'modify');

		$aOutcomes = [];
		foreach ($aIds as $iId) {
			$mObject = self::objectFor($class, $iId, UR_ACTION_MODIFY, 'modify');
			if (!$mObject instanceof DBObject) {
				$aOutcomes[] = self::outcome($iId, false, $mObject);
				continue;
			}

			// Per object: the attribute rights of a User are not the attribute
			// rights of another User.
			[$aValues, $aIssues] = self::validatedValues($class, $fields);
			if (!empty($aIssues)) {
				$aOutcomes[] = self::outcome($iId, false, implode(' ', $aIssues));
				continue;
			}

			try {
				foreach ($aValues as $sAttCode => $value) {
					$mObject->Set($sAttCode, $value);
				}

				if (!$simulate) {
					$mObject->DBUpdate();
				}

				$aOutcomes[] = self::outcome($iId, true, $simulate ? 'Would be updated.' : '');
			} catch (\Exception $e) {
				$aOutcomes[] = self::outcome($iId, false, $e->getMessage());
			}
		}

		return ToolOutput::Json(self::report($class, $simulate, $aOutcomes));
	}
}
