<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use DBObject;
use DeletionPlan;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;

/**
 * Delete a list of objects of one class.
 *
 * The most dangerous tool in the base extension, and the reason its dry run is
 * not merely the default but the only way to find out what a call would do:
 * iTop deletion cascades, so forty ids can take a great deal more than forty
 * objects with them.
 */
class ObjectBulkDelete extends AbstractBulkTool
{
	public function getTitle(): ?string
	{
		return 'Delete Objects in Bulk';
	}

	public function getDescription(): ?string
	{
		return 'Delete several iTop objects of one class at once. Related objects may also be deleted or modified according to iTop\'s cascading deletion rules, so the real reach of the call can be much larger than the list of ids. '
			.'Runs as a dry run by default: call it with simulate=true to obtain the combined deletion plan, show that plan to the user, and only then call it again with simulate=false. '
			.'Each object is checked on its own, so a call can partly succeed; the response reports every object separately.';
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
	 *
	 * @return mixed A per-object report, each carrying what its deletion would take with it
	 *
	 * @throws ToolCallException When the class, the ids or the bulk right rule out the whole call.
	 */
	public static function execute(
		string $class,
		array  $ids,
		bool   $simulate = true,
	): mixed {
		$aIds = self::checkIds($ids);
		self::checkBulkAllowed($class, UR_ACTION_BULK_DELETE, UR_ACTION_DELETE, 'delete');

		$aOutcomes = [];
		foreach ($aIds as $iId) {
			$mObject = self::objectFor($class, $iId, UR_ACTION_DELETE, 'delete');
			if (!$mObject instanceof DBObject) {
				$aOutcomes[] = self::outcome($iId, false, $mObject);
				continue;
			}

			$aOutcomes[] = self::deleteOne($mObject, $class, $iId, $simulate);
		}

		return ToolOutput::Json(self::report($class, $simulate, $aOutcomes));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function deleteOne(DBObject $oObject, string $sClass, int $iId, bool $bSimulate): array
	{
		// CheckToDelete() returns a boolean and fills the plan by reference;
		// the reasons live on the plan.
		$oPlan = new DeletionPlan();
		if (!$oObject->CheckToDelete($oPlan)) {
			$aIssues = $oPlan->GetIssues();

			return self::outcome($iId, false, empty($aIssues)
				? 'Cannot be deleted; some related objects must be deleted or updated explicitly first.'
				: 'Cannot be deleted: '.implode(', ', $aIssues));
		}

		if (!$bSimulate) {
			try {
				$oPlan = new DeletionPlan();
				$oObject->DBDelete($oPlan);
			} catch (\Exception $e) {
				return self::outcome($iId, false, $e->getMessage());
			}
		}

		$aOutcome = self::outcome($iId, true, $bSimulate ? 'Would be deleted.' : '');
		$aOutcome['deletionPlan'] = self::serializeDeletionPlan($oPlan);

		return $aOutcome;
	}

	/**
	 * What goes with an object when it goes.
	 *
	 * @return array<string, mixed>
	 */
	private static function serializeDeletionPlan(DeletionPlan $oPlan): array
	{
		$aDeleted = [];
		$aUpdated = [];

		foreach ($oPlan->ListDeletes() as $sClass => $aObjects) {
			foreach (array_keys($aObjects) as $iId) {
				$aDeleted[] = ['class' => $sClass, MetaModel::DBGetKey($sClass) => $iId];
			}
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aObjects) {
			foreach (array_keys($aObjects) as $iId) {
				$aUpdated[] = ['class' => $sClass, MetaModel::DBGetKey($sClass) => $iId];
			}
		}

		return ['deleted' => $aDeleted, 'updated' => $aUpdated];
	}
}
