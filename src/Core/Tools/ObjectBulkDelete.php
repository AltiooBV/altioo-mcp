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
use DeletionPlan;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Delete a list of objects of one class.
 *
 * The most dangerous tool in the base extension, and the reason its dry run is
 * not merely the default but the only way to find out what a call would do:
 * iTop deletion cascades, so forty ids can take a great deal more than forty
 * objects with them.
 *
 * @since 1.0.0
 */
class ObjectBulkDelete extends AbstractBulkTool
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
		return 'Delete Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Delete several iTop objects of one class at once. Related objects may also be deleted or modified according to iTop\'s cascading deletion rules, so the real reach of the call can be much larger than the list of ids. '
			.'Runs as a dry run by default: call it with simulate=true to obtain the combined deletion plan, show that plan to the user, and only then call it again with simulate=false. '
			.'Each object is checked on its own, so a call can partly succeed; the response reports every object separately. '
			.'The cascade of each object is checked against this user\'s rights, not just the object itself, so one id can be refused for what its deletion would reach while the others go through.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Delete iTop objects in bulk',
			false,  // readOnlyHint
			true,   // destructiveHint — permanent, and cascades
			false,  // idempotentHint — the second call finds nothing to delete
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return self::reportSchema(['deletionPlan' => WritePlan::DeletionPlanSchema()]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => self::targetSchemaProperties('delete for real'),
			'required'   => ['class', 'ids'],
		];
	}

	/**
	 * @param string            $class    The class every listed object belongs to
	 * @param array<int, mixed> $ids      Ids of the objects to delete
	 * @param bool              $simulate When true (default), nothing is deleted
	 * @param string|null       $comment  Why the batch is being deleted, recorded in the history of everything the deletions touch
	 *
	 * @return mixed A per-object report, each carrying what its deletion would take with it
	 *
	 * @throws ToolCallException When the class, the ids or the bulk right rule out the whole call.
	 */
	public static function execute(
		string  $class,
		array   $ids,
		bool    $simulate = true,
		?string $comment = null,
	): mixed
	{
		$aIds = self::checkIds($ids);
		self::checkBulkAllowed($class, UR_ACTION_BULK_DELETE, UR_ACTION_DELETE, 'delete');

		// Once for the batch, before the loop: the objects deleted by one call
		// are one decision, and they share the one change record.
		ChangeTracking::Explain($comment);

		$aOutcomes = [];
		foreach ($aIds as $iRow => $iId) {
			$mObject = self::objectFor($class, $iId, UR_ACTION_DELETE, 'delete');
			if (!$mObject instanceof DBObject) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, $mObject);
				continue;
			}

			$aOutcomes[] = self::deleteOne($mObject, $class, $iId, $iRow, $simulate);
		}

		// An entry that never got as far as a plan still reports one, empty:
		// the schema promises deletionPlan on every entry.
		return ToolOutput::Structured(self::report($class, $simulate, $aOutcomes, [
			'deletionPlan' => WritePlan::SerializeDeletionPlan(null),
		]));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deleteOne(DBObject $oObject, string $sClass, int $iId, int $iRow, bool $bSimulate): array
	{
		// Per row, because checkBulkAllowed() saw only the class: whether a
		// row is the caller's own access is a property of the row.
		$sAccessRefusal = AccessGrants::RefusalFor($sClass, $iId);
		if ($sAccessRefusal !== null) {
			return self::outcome($iId, $iRow, false, $sAccessRefusal);
		}

		// CheckToDelete() returns a boolean and fills the plan by reference;
		// the reasons live on the plan.
		$oPlan = new DeletionPlan();
		if (!$oObject->CheckToDelete($oPlan)) {
			$aIssues = $oPlan->GetIssues();

			return self::outcome($iId, $iRow, false, empty($aIssues)
				? 'Cannot be deleted; some related objects must be deleted or updated explicitly first.'
				: 'Cannot be deleted: '.implode(', ', $aIssues));
		}

		// Per object rather than for the batch: the cascade of one id can reach
		// classes the cascade of the next one does not, and a bulk call reports
		// each object on its own. See WritePlan::CheckDeletionRights().
		try {
			WritePlan::CheckDeletionRights($oPlan, "{$sClass}::{$iId}");
		} catch (ToolCallException $e) {
			return self::outcome($iId, $iRow, false, $e->getMessage());
		}

		if (!$bSimulate) {
			try {
				$oPlan = new DeletionPlan();
				$oObject->DBDelete($oPlan);
			} catch (\Throwable $e) {
				// CheckToDelete() above already reported everything the caller
				// could act on, with the plan's own wording.
				return self::outcome($iId, $iRow, false, MCPHelper::OpaqueFailure("{$sClass}::{$iId} could not be deleted", $e));
			}
		}

		$aOutcome = self::outcome($iId, $iRow, true, $bSimulate ? 'Would be deleted.' : 'Deleted.');
		$aOutcome['deletionPlan'] = WritePlan::SerializeDeletionPlan($oPlan);

		return $aOutcome;
	}
}
