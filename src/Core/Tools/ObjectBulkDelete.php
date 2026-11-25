<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrants;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\WriteCap;
use Altioo\iTop\Extension\MCP\Service\Write\DryRun;
use Altioo\iTop\Extension\MCP\Service\Write\DeletionCascade;
use Altioo\iTop\Extension\MCP\Service\Write\WriteOutcome;
use Altioo\iTop\Extension\MCP\Service\Write\WritePlan;
use DBObject;
use DBObjectSet;
use MetaModel;
use UserRights;
use DeletionPlan;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

/**
 * Delete a list of objects of one class.
 *
 * The most dangerous tool in the base extension, and the reason its dry run is
 * not merely the default but the only way to find out what a call would do:
 * iTop deletion cascades, so forty ids can take a great deal more than forty
 * objects with them.
 *
 * @internal
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
			.'The dry run reports the combined deletion plan. '
			.'Each object is checked on its own, so a call can partly succeed; the response reports every object separately. '
			.'The cascade of each object is checked against this user\'s rights, not just the object itself, so one id can be refused for what its deletion would reach while the others go through.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'Delete iTop objects in bulk',
			readOnlyHint: false,
			destructiveHint: true, // permanent, and cascades
			idempotentHint: false, // the second call finds nothing to delete
			openWorldHint: false,
		);
	}

	public function getOutputSchema(): ?array
	{
		return self::reportSchema(['deletionPlan' => DeletionCascade::DeletionPlanSchema()]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => self::targetSchemaProperties('delete for real') + [
				'obsolete_ok' => WriteOutcome::ObsoleteOkBriefSchemaProperty('delete'),
			],
			'required'   => ['class', 'ids'],
		];
	}

	/**
	 * @param string            $class    The class every listed object belongs to
	 * @param array<int, mixed> $ids      Ids of the objects to delete
	 * @param bool              $simulate When true (default), nothing is deleted
	 * @param string|null       $comment  Why the batch is being deleted, recorded on the audit row
	 * @param bool              $obsolete_ok Delete obsolete objects even though this account hides them: otherwise their rows fail, as core_object_delete refuses
	 *
	 * @return mixed A per-object report, each carrying what its deletion would take with it
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When the class, the ids or the bulk right rule out the whole call.
	 */
	public static function execute(
		string  $class,
		array   $ids,
		bool    $simulate = true,
		?string $comment = null,
		bool    $obsolete_ok = false,
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = DryRun::Simulated($simulate);

		$aIds = self::checkIds($ids);
		self::checkBulkAllowed($class, UR_ACTION_BULK_DELETE, UR_ACTION_DELETE, 'delete');

		// The operator's cap on committed writes per credential (ADR 0019): asked here, in this
		// tool's own source, once the call is known to commit and before it writes.
		$sCapRefusal = $simulate ? null : WriteCap::RefusalFor(count($aIds));
		if ($sCapRefusal !== null) {
			throw ToolRefusal::Because($sCapRefusal);
		}

		// Once for the batch, before the loop: the objects deleted by one call
		// are one decision, and they share the one change record.
		ChangeTracking::Explain($comment);

		$aOutcomes = [];
		// What the deletions made so far take with them, by hierarchy and id => the entry whose cascade it is in.
		$aCascade = [];
		foreach ($aIds as $iRow => $iId) {
			$sVia = DeletionCascade::CascadeOf($class, $iId, $aCascade);
			if ($sVia !== null && !$simulate) {
				// Deleted already, as part of the cascade of an earlier entry of this very call. Looking it up now
				// would answer "not found" and read as a failure, where the dry run said it would be deleted (AC-2).
				$aOutcomes[] = self::outcome($iId, $iRow, true, "Deleted: it went with the cascade of {$sVia}, an earlier entry of this call.");
				continue;
			}

			// An attachment is judged by its host, per row, instead of by the delete right on the object (ADR 0026); the
			// bulk right on the class and checkBulkAllowed()'s class-level delete right are asked above, unchanged.
			$mObject = self::objectFor($class, $iId, self::isAttachment($class) ? UR_ACTION_READ : UR_ACTION_DELETE, 'delete');
			if (!$mObject instanceof DBObject) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, $mObject);
				continue;
			}

			// An object this account cannot see in its own searches is probably being acted on from a stale id, as
			// core_object_delete says: this row fails, the others go on (review 2026-11-23, S3).
			$sObsoleteRefusal = WritePlan::HiddenObsoleteRefusal($mObject, $class, $obsolete_ok, 'delete', true);
			if ($sObsoleteRefusal !== null) {
				$aOutcomes[] = self::outcome($iId, $iRow, false, $sObsoleteRefusal);
				continue;
			}

			$aOutcome = self::deleteOne($mObject, $class, $iId, $iRow, $simulate, $aCascade);
			if ($sVia !== null && $aOutcome['status'] === 'ok') {
				$aOutcome['message'] = "Would be deleted. It is also in the cascade of {$sVia}, an earlier entry of this call, so a real run deletes it with that one.";
			}
			$aOutcomes[] = $aOutcome;
		}

		// An entry that never got as far as a plan still reports one, empty:
		// the schema promises deletionPlan on every entry.
		return ToolOutput::StructuredWrite(self::report($class, $simulate, $aOutcomes, [
			'deletionPlan' => DeletionCascade::SerializeDeletionPlan(null),
		]));
	}

	private static function isAttachment(string $sClass): bool
	{
		return MetaModel::IsValidClass('Attachment') && MetaModel::IsValidClass($sClass) && MetaModel::IsParentClass('Attachment', $sClass);
	}

	/**
	 * @param array<string, string> $aCascade Filled with what this deletion takes with it: hierarchy and id => this entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function deleteOne(DBObject $oObject, string $sClass, int $iId, int $iRow, bool $bSimulate, array &$aCascade): array
	{
		// Per row, because checkBulkAllowed() saw only the class: whether a
		// row is the caller's own access is a property of the row.
		$sAccessRefusal = AccessGrants::RefusalFor($sClass, $iId);
		if ($sAccessRefusal !== null) {
			return self::outcome($iId, $iRow, false, $sAccessRefusal);
		}

		// An attachment is removed from its host's edit form in the console, so what the console asks of the host is
		// asked here: the host exists and is readable and served, the account may modify it (this object, not only the
		// class), and the attachments module does not freeze it for the back office (closed tickets by default). The
		// cascade check above no longer stops these incidentally for a non-administrator (review 2026-11-23, B8).
		// An unreadable or excluded host is answered as one that is not there, so it names nothing.
		$oAttachmentHost = null;
		if (self::isAttachment($sClass)) {
			$sHostRefusal = null;
			$sHostClass = (string)$oObject->Get('item_class');
			$iHostId = (int)$oObject->Get('item_id');
			if ($sHostClass === '' || $iHostId < 1) {
				$sHostRefusal = 'This attachment is attached to no saved object (a draft upload), so it is not deleted here.';
			} elseif (!MetaModel::IsValidClass($sHostClass) || ExclusionPolicy::ExcludesClass($sHostClass) || !UserRights::IsActionAllowed($sHostClass, UR_ACTION_READ)) {
				$sHostRefusal = 'The object this attachment belongs to was not found.';
			} else {
				$oHostSet = new DBObjectSet(ObjectQuery::ById($sHostClass, $iHostId));
				$oAttachmentHost = $oHostSet->Count() === 0 ? null : $oHostSet->Fetch();
				$oHostSet->Rewind();
				if ($oAttachmentHost === null || ExclusionPolicy::ExcludesClass(get_class($oAttachmentHost))) {
					$oAttachmentHost = null;
					$sHostRefusal = 'The object this attachment belongs to was not found.';
				} elseif (UserRights::IsActionAllowed(get_class($oAttachmentHost), UR_ACTION_MODIFY, $oHostSet) !== UR_ALLOWED_YES) {
					$sHostRefusal = 'Access denied: cannot edit the object this attachment belongs to, so cannot remove its attachments.';
				} elseif ($oAttachmentHost->IsReadOnly()
					|| (class_exists('AttachmentPlugIn') && method_exists('AttachmentPlugIn', 'IsReadonlyState')
						&& \AttachmentPlugIn::IsReadonlyState($oAttachmentHost, $oAttachmentHost->GetState(), \AttachmentPlugIn::ENUM_GUI_BACKOFFICE))) {
					$sHostRefusal = 'The object this attachment belongs to is read-only in its current state, so its attachments cannot be removed.';
				}
			}
			if ($sHostRefusal !== null) {
				return self::outcome($iId, $iRow, false, $sHostRefusal);
			}
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
		// each object on its own. See DeletionCascade::CheckDeletionRights().
		try {
			DeletionCascade::CheckDeletionRights($oPlan, "{$sClass}::{$iId}", $sClass, $iId, $oAttachmentHost !== null);
		} catch (ToolCallException $e) {
			return self::outcome($iId, $iRow, false, $e->getMessage());
		}

		if (!$bSimulate) {
			$sRemovedFile = '';
			if ($oAttachmentHost !== null) {
				try {
					$sRemovedFile = DeletionCascade::AttachmentRemoving($oAttachmentHost, $oObject);
				} catch (\Throwable $e) {
					// Another extension's listener vetoed the removal, as it can in the console's form. Nothing was deleted.
					return self::outcome($iId, $iRow, false, ErrorReport::OpaqueFailure("The attachment {$sClass}::{$iId} was not removed: an extension refused it", $e));
				}
			}
			try {
				$oPlan = new DeletionPlan();
				$oObject->DBDelete($oPlan);
				if ($oAttachmentHost !== null) {
					DeletionCascade::AttachmentRemoved($oAttachmentHost, $sRemovedFile);
				}
			} catch (\Throwable $e) {
				// CheckToDelete() above already reported everything the caller
				// could act on, with the plan's own wording.
				return self::outcome($iId, $iRow, false, ErrorReport::OpaqueFailure("{$sClass}::{$iId} could not be deleted", $e));
			}
		}

		// What this deletion takes with it, so that a later entry of the same call that is among them is
		// reported as what it is (the plan of a dry run lists the same objects as the real one).
		DeletionCascade::NoteCascade($oPlan, $sClass, $iId, $aCascade);

		$aOutcome = self::outcome($iId, $iRow, true, $bSimulate ? 'Would be deleted.' : 'Deleted.');
		$aOutcome['deletionPlan'] = DeletionCascade::SerializeDeletionPlan($oPlan, $sClass, $iId);

		return $aOutcome;
	}
}
