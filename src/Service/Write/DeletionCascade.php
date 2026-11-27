<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\ObjectId;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DeletionPlan;
use MetaModel;
use Throwable;
use UserRights;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

/**
 * What a deletion would take with it: the cascade checked against the caller's rights, and the plan described to the caller.
 *
 * @api
 * @since 2.0.0
 */
final class DeletionCascade
{
	/**
	 * What a deletion would take with it, as a schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function DeletionPlanSchema(): array
	{
		$aObjectRef = [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'class' => ['type' => 'string'],
				'id'    => ['type' => 'integer'],
			],
		];

		return [
			'type'        => 'object',
			'description' => 'What iTop\'s cascading rules would do besides deleting the object itself.',
			'properties'  => [
				'deleted' => [
					'type'        => 'array',
					'items'       => $aObjectRef,
					'description' => 'Related objects deleted along with it.',
				],
				'updated' => [
					'type'        => 'array',
					'items'       => $aObjectRef,
					'description' => 'Related objects left in place but modified, e.g. an external key reset.',
				],
			],
			'required'    => ['deleted', 'updated'],
		];
	}

	/**
	 * Refuses a deletion whose cascade reaches objects this caller may not
	 * read, delete or modify.
	 *
	 * iTop does not do this, and the omission is deliberate on its side:
	 * MakeDeletionPlan() walks the references with
	 * GetReferencingObjectsForDeletion() in its allow-all-data mode, so the
	 * plan is complete whoever asked for it, and the console checks the delete right
	 * on the object the user clicked and on nothing the cascade drags along
	 * (cmdbabstract.class.inc.php). That is defensible there. A person clicked
	 * a button, saw the impact analysis iTop renders, and confirmed it.
	 *
	 * It is not defensible here. The caller is a language model acting on an
	 * instruction, often without a person reading the plan before the second
	 * call, and cascade is precisely the path by which "delete this one ticket"
	 * reaches classes an operator withheld on purpose. A right that can be
	 * routed around by deleting something else is not a right, and nothing in
	 * the audit trail would show it happening: the row says the tool deleted
	 * the ticket it was asked to delete.
	 *
	 * So this endpoint is stricter than the console, and knowingly: a deletion
	 * the console would perform can be refused here. That is the intended
	 * trade. An operator who wants the cascade to go through grants the rights
	 * on the classes it reaches, which is the same thing said out loud.
	 *
	 * Three questions per class in the plan, and the order matters. A class the
	 * caller cannot read at all is never named - the refusal says only that
	 * something unreadable is in the way, so a caller cannot map out the
	 * datamodel by deleting things and reading the error. A class it can read
	 * is named, because that is what makes the refusal actionable. The instance
	 * set is passed on the second and third, so an addon that grades rights per
	 * object gets to answer about these objects rather than about the class.
	 *
	 * One exemption, narrow on purpose (review 2026-11-23, B8; decided by Guy Couronne, 2026-11-24): deleting an
	 * Attachment also deletes the CMDBChangeOp rows that record it ("attachment added"), which iTop declares
	 * DEL_SILENT because they are its own bookkeeping (it resets their key to the attachment, which is how the plan
	 * lists them), and no non-administrator may read them. Those rows are not judged when, and only when, the
	 * object being deleted is an Attachment, the row is a change operation, every key the plan resets on it is a
	 * DEL_SILENT key to Attachment, and that key holds this very attachment. The console does the
	 * same: DeleteObjects() asks the delete right on the object clicked and nothing on the cascade. Every other
	 * row of the plan is judged as before, and the refusal still names a class the user may read.
	 *
	 * @param DeletionPlan $oPlan  A plan already computed by CheckToDelete().
	 * @param string       $sWhat  What is being deleted, e.g. "UserRequest::12", named in the refusal.
	 * @param string|null  $sRootClass The class of the object being deleted, for the one exemption above.
	 * @param int|null     $iRootId    Its id.
	 * @param bool         $bHostChecked The delete tool asked, in its own source, what ADR 0026 asks of an attachment's host and it passed. The Attachment itself, the root of the plan, is then not judged under the delete right (that entry only); everything else in the plan is.
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When the cascade reaches something this caller may not touch.
	 *
	 * @since 1.0.0
	 */
	public static function CheckDeletionRights(DeletionPlan $oPlan, string $sWhat, ?string $sRootClass = null, ?int $iRootId = null, bool $bHostChecked = false): void
	{
		// A delete that would reach objects of a class this channel does not serve is not made here, and not
		// reported with them left out: a plan that shows less than what happens is how a delete goes wrong. It
		// does not say which class (doc/adr/0012).
		foreach ([$oPlan->ListDeletes(), $oPlan->ListUpdates()] as $aClasses) {
			foreach (array_keys($aClasses) as $sReached) {
				/** @psalm-suppress InvalidCast */
				if (ExclusionPolicy::ExcludesClass((string)$sReached)) {
					throw ToolRefusal::Because("{$sWhat} cannot be deleted here: it reaches related objects this channel does not serve. Do it in the iTop console.");
				}
			}
		}

		$aRefused  = [];
		$bUnreadable = false;

		foreach ($oPlan->ListDeletes() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf(self::WithoutItsRoot($sClass, $aEntries, $sRootClass, $iRootId, $bHostChecked), 'to_delete'),
				UR_ACTION_DELETE,
				'deleted',
				$aRefused,
				$bUnreadable
			);
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf(self::WithoutItsOwnBookkeeping($sClass, $aEntries, $sRootClass, $iRootId), 'to_reset'),
				UR_ACTION_MODIFY,
				'modified',
				$aRefused,
				$bUnreadable
			);
		}

		if ($bUnreadable) {
			$aRefused[] = 'It also reaches related objects this user may not read.';
		}

		if (empty($aRefused)) {
			return;
		}

		throw ToolRefusal::Because(sprintf(
			'%s cannot be deleted: iTop\'s cascading rules would reach objects this user is not allowed to touch. %s '
			.'This endpoint checks the whole cascade, not just the object named in the call. '
			.'Ask an administrator for the missing rights, or delete the related objects explicitly first.',
			$sWhat,
			implode(' ', $aRefused)
		));
	}

	/**
	 * Records what is wrong with one class of the cascade, if anything is.
	 *
	 * @param array<int, DBObject> $aObjects
	 * @param array<int, string>   $aRefused     Appended to.
	 * @param bool                 $bUnreadable  Raised when a class cannot be named at all.
	 */
	private static function JudgeCascadedClass(
		string $sClass,
		array $aObjects,
		int $iAction,
		string $sWhatWouldHappen,
		array &$aRefused,
		bool &$bUnreadable
	): void
	{
		if (empty($aObjects)) {
			return;
		}

		// Asked without the set first, and answered without naming the class:
		// a caller who may not read the class has no business learning that it
		// exists, let alone that it points at what they just tried to delete.
		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			$bUnreadable = true;

			return;
		}

		$oSet = self::SetOf($sClass, $aObjects);

		if ($oSet !== null && !self::Grants($sClass, UR_ACTION_READ, $oSet)) {
			// The class is readable and these objects are not, which is an
			// object-level rule the class-level question above cannot see.
			$aRefused[] = sprintf(
				"It would have %s objects of class '%s' that this user may not read.",
				$sWhatWouldHappen,
				$sClass
			);

			return;
		}

		if (!self::Grants($sClass, $iAction, $oSet)) {
			$aRefused[] = sprintf(
				"It would have %s %d object(s) of class '%s', which this user may not %s.",
				$sWhatWouldHappen,
				count($aObjects),
				$sClass,
				$iAction === UR_ACTION_DELETE ? 'delete' : 'modify'
			);
		}
	}

	/**
	 * Whether the caller is allowed this action on these objects.
	 *
	 * IsActionAllowed() is tri-state, and the two states are read the way the
	 * bulk tools already read them: with the objects in hand,
	 * UR_ALLOWED_DEPENDS has been resolved and anything short of a yes is a no;
	 * without them - the set could not be built - only an outright refusal
	 * counts, because "depends on the object" is not an answer that can be
	 * given about objects nobody passed.
	 *
	 * The first half is the strict reading, and it is the one that belongs on
	 * a cascade: a DEPENDS treated as a yes here is a right the caller was
	 * never actually granted.
	 */
	private static function Grants(string $sClass, int $iAction, ?DBObjectSet $oSet): bool
	{
		$iAllowed = UserRights::IsActionAllowed($sClass, $iAction, $oSet);

		return $oSet === null
			? $iAllowed !== UR_ALLOWED_NO
			: $iAllowed === UR_ALLOWED_YES;
	}

	/**
	 * The entries of one class of the plan's deletions, less the attachment the call deletes when its host passed.
	 *
	 * @param array<int, array<string, mixed>> $aEntries
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function WithoutItsRoot(string $sClass, array $aEntries, ?string $sRootClass, ?int $iRootId, bool $bHostChecked): array
	{
		if (!$bHostChecked || $sRootClass === null || $iRootId === null
			|| !MetaModel::IsValidClass('Attachment') || !MetaModel::IsParentClass('Attachment', $sRootClass)
			|| $sClass !== $sRootClass) {
			return $aEntries;
		}

		return array_filter($aEntries, static fn (array $aData): bool => ObjectId::Normalise(($aData['to_delete'] ?? null) instanceof DBObject ? $aData['to_delete']->GetKey() : null) !== $iRootId);
	}

	/**
	 * The entries of one class of the plan's updates, less the bookkeeping rows of an attachment being deleted.
	 *
	 * See {@see CheckDeletionRights()} for what is exempt and why. iTop plans such a row as an update, not a
	 * deletion (its key to the attachment is nullable): it is reset when the attachment goes. Everything is asked of
	 * the plan's own entry: the class of the root, the class of the row, every key the plan resets on it, the
	 * propagation mode the datamodel gives that key, and the attachment the key holds.
	 *
	 * @param array<int, array<string, mixed>> $aEntries
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function WithoutItsOwnBookkeeping(string $sClass, array $aEntries, ?string $sRootClass, ?int $iRootId): array
	{
		if ($sRootClass === null || $iRootId === null
			|| !MetaModel::IsValidClass('Attachment') || !MetaModel::IsParentClass('Attachment', $sRootClass)
			|| !MetaModel::IsParentClass('CMDBChangeOp', $sClass)) {
			return $aEntries;
		}

		return array_filter($aEntries, static function (array $aData) use ($iRootId): bool {
			$oRow = $aData['to_reset'] ?? null;
			$aReset = $aData['attributes'] ?? [];
			if (!$oRow instanceof DBObject || $aReset === []) {
				return true;
			}
			foreach ($aReset as $sAttCode => $oAttDef) {
				if (!$oAttDef instanceof \AttributeExternalKey
					|| !MetaModel::IsParentClass('Attachment', $oAttDef->GetTargetClass())
					|| $oAttDef->GetDeletionPropagationOption() !== DEL_SILENT
					|| !self::StoredKeyIs($oRow, (string)$sAttCode, $iRootId)) {
					return true; // anything else is judged
				}
			}

			return false; // this attachment's own record
		});
	}

	/**
	 * Whether the row, as stored, holds this key. The plan's own object is no witness: iTop has reset the key in it
	 * by the time the plan is read. Asked of the database, with the rights off because the question is only whether
	 * one known row points at one known object, and the answer is a yes or a no that nothing is read from.
	 */
	private static function StoredKeyIs(DBObject $oRow, string $sAttCode, int $iId): bool
	{
		try {
			$oSearch = DBObjectSearch::FromOQL_AllData('SELECT '.get_class($oRow).' WHERE id = :row AND '.$sAttCode.' = :key');

			return (new DBObjectSet($oSearch, [], ['row' => (int)$oRow->GetKey(), 'key' => $iId]))->Count() === 1;
		} catch (Throwable $e) {
			return false; // not provable is not exempt
		}
	}

	/**
	 * The objects behind one class of a plan entry.
	 *
	 * @param array<int, array<string, mixed>> $aEntries
	 *
	 * @return array<int, DBObject>
	 */
	private static function ObjectsOf(array $aEntries, string $sKey): array
	{
		$aObjects = [];

		foreach ($aEntries as $aData) {
			$mObject = $aData[$sKey] ?? null;
			if ($mObject instanceof DBObject) {
				$aObjects[] = $mObject;
			}
		}

		return $aObjects;
	}

	/**
	 * Those objects as a set, so a rights addon that grades per object can.
	 *
	 * Null when the set cannot be built, which makes the caller fall back to
	 * the class-level answer rather than skip the check: a set this module
	 * failed to assemble is not evidence that anything is allowed.
	 *
	 * @param array<int, DBObject> $aObjects
	 */
	private static function SetOf(string $sClass, array $aObjects): ?DBObjectSet
	{
		try {
			return DBObjectSet::FromArray($sClass, $aObjects);
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Remembers what the deletion of one object of a call takes with it, so that a later entry of the same call that is among
	 * those objects can be reported as what it is (review 2026-11-18, AC-2). The objects are named by hierarchy and id, which is
	 * how iTop keys them, so a subclass and its parent are the same object.
	 *
	 * @param array<string, string> $aLedger Hierarchy and id => the entry (`Class::id`) whose deletion took it. Filled.
	 *
	 * @since 2.1.0
	 */
	public static function NoteCascade(DeletionPlan $oPlan, string $sClass, int $iId, array &$aLedger): void
	{
		$sRoot = MetaModel::GetRootClass($sClass);
		foreach ($oPlan->ListDeletes() as $sDeletedClass => $aEntries) {
			foreach (array_keys($aEntries) as $mDeletedId) {
				$iDeletedId = ObjectId::Normalise($mDeletedId);
				$sDeletedRoot = MetaModel::GetRootClass((string)$sDeletedClass);
				if ($iDeletedId === null || ($iDeletedId === $iId && $sDeletedRoot === $sRoot)) {
					continue; // the entry itself
				}
				$aLedger[$sDeletedRoot.'::'.$iDeletedId] ??= "{$sClass}::{$iId}";
			}
		}
	}

	/**
	 * The entry of the call whose deletion took this object along, or null when none did.
	 *
	 * @param array<string, string> $aLedger As {@see NoteCascade()} filled it.
	 *
	 * @since 2.1.0
	 */
	public static function CascadeOf(string $sClass, int $iId, array $aLedger): ?string
	{
		return $aLedger[MetaModel::GetRootClass($sClass).'::'.$iId] ?? null;
	}

	/**
	 * The host hears that one of its attachments is going, as the console's edit form makes it hear, and the file's
	 * name is kept for the history line {@see AttachmentRemoved()} writes. Plumbing only: whether the attachment may
	 * go is decided by the delete tool, in its own source (ADR 0014).
	 *
	 * What a listener throws is **not** swallowed: in iTop's form the event is fired before the delete and an
	 * exception from a listener stops it (EventService::FireEvent() rethrows, at once for an EventException and after
	 * the other listeners for any other), so another extension's veto holds here too. The caller turns it into a
	 * refusal that does not repeat the listener's message. Only reading the file's name may fail quietly.
	 *
	 * @return string The file's name, '' when it cannot be read.
	 * @throws Throwable What a listener of EVENT_REMOVE_ATTACHMENT_FROM_OBJECT threw.
	 * @since 2.2.0
	 */
	public static function AttachmentRemoving(DBObject $oHost, DBObject $oAttachment): string
	{
		try {
			$oBlob = $oAttachment->Get('contents');
			$sName = $oBlob instanceof \ormDocument ? $oBlob->GetFileName() : '';
		} catch (Throwable $e) {
			ErrorReport::Log('Could not read the name of an attachment being removed: '.$e->getMessage());
			$sName = '';
		}

		$aData = ['attachment' => $oAttachment];
		$oHost->FireEvent(EVENT_REMOVE_ATTACHMENT_FROM_OBJECT, $aData);

		return $sName;
	}

	/**
	 * The "attachment removed" line the console leaves in the host's history (AttachmentPlugIn::UpdateAttachments()
	 * writes a CMDBChangeOpAttachmentRemoved with the file's name), which deleting the Attachment alone would not.
	 * A failure to write it is logged and does not turn a deletion that happened into a failure.
	 *
	 * @since 2.2.0
	 */
	public static function AttachmentRemoved(DBObject $oHost, string $sFilename): void
	{
		if (!class_exists('CMDBChangeOpAttachmentRemoved')) {
			return;
		}
		try {
			$oOp = new \CMDBChangeOpAttachmentRemoved();
			$oOp->Set('filename', $sFilename);
			$oOp->Set('objclass', get_class($oHost));
			$oOp->Set('objkey', $oHost->GetKey());
			$oOp->DBInsertNoReload();
		} catch (Throwable $e) {
			ErrorReport::Log('Could not write the "attachment removed" line of '.get_class($oHost).'::'.$oHost->GetKey().': '.$e->getMessage());
		}
	}

	/**
	 * What a deletion would take with it, as the two lists both delete tools
	 * report.
	 *
	 * One implementation, read straight after {@see CheckDeletionRights()}: a
	 * rights rule applied to one copy of this and not the other is the bug a
	 * per-tool copy invites.
	 *
	 * No right is applied here by filtering. It does not have to be: by the time
	 * a plan is serialised, CheckDeletionRights() has refused every plan holding
	 * an object this caller may not read and was not exempt, so the class and id
	 * of everything left are things they could have looked up themselves. That is
	 * the other half of why the check is a refusal rather than a mask - a plan
	 * shown to a person before they approve it has to be complete, and the only
	 * way for it to be both complete and safe is for the incomplete case not to
	 * exist.
	 *
	 * One thing is left out of it: the change log's own rows that iTop resets
	 * without a word, such as the "attachment added" line of an attachment being
	 * removed (review 2026-11-23, retest). They are history kept about the
	 * object, not something that happens to the user's data, they are served by no
	 * read tool, and a person approving the plan cannot act on their ids. Only an
	 * update is left out, and only when every key iTop resets on the row is one it
	 * declares silent; a change-log row that were deleted would be listed.
	 * CheckDeletionRights() has judged the whole plan before this, so leaving a
	 * row out here allows nothing.
	 *
	 * Each entry names its class, since an id alone says nothing: a business process, a solution and a server
	 * can all be number 1 (review 2026-11-23, B7). The object the call is about is left out when it is passed,
	 * as the schema's "besides deleting the object itself" says: iTop's plan lists it too.
	 *
	 * @param string|null $sClass The class of the object the call deletes, to leave it out of the plan.
	 * @param int|null    $iId    Its id.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 * @since 2.2.0 Entries carry their class; the object itself is left out when named, and so are the change log's silent updates.
	 */
	public static function SerializeDeletionPlan(?DeletionPlan $oPlan, ?string $sClass = null, ?int $iId = null): array
	{
		if ($oPlan === null) {
			return ['deleted' => [], 'updated' => []];
		}

		$aDeleted = [];
		$aUpdated = [];
		$sRoot = $sClass === null ? null : MetaModel::GetRootClass($sClass);

		foreach ($oPlan->ListDeletes() as $sReached => $aEntries) {
			foreach (array_keys($aEntries) as $mReachedId) {
				// The same object whether named by a subclass or its parent, as NoteCascade() reads it.
				if ($sRoot !== null && $iId !== null && ObjectId::Normalise($mReachedId) === $iId && MetaModel::GetRootClass((string)$sReached) === $sRoot) {
					continue;
				}
				$aDeleted[] = ['class' => (string)$sReached] + WriteOutcome::Identity((string)$sReached, $mReachedId);
			}
		}

		foreach ($oPlan->ListUpdates() as $sReached => $aEntries) {
			foreach ($aEntries as $mReachedId => $aData) {
				if (self::IsSilentHistory((string)$sReached, (array)$aData)) {
					continue;
				}
				$aUpdated[] = ['class' => (string)$sReached] + WriteOutcome::Identity((string)$sReached, $mReachedId);
			}
		}

		return ['deleted' => $aDeleted, 'updated' => $aUpdated];
	}

	/**
	 * Whether an update of the plan is the change log looking after itself: a row of it, on which every key reset is
	 * one the datamodel declares silent. Asked of the plan's own entry, so a dry run and the real deletion, whose
	 * rows are reset by the time it is read, answer alike.
	 *
	 * @param array<string, mixed> $aData
	 */
	private static function IsSilentHistory(string $sClass, array $aData): bool
	{
		$aReset = $aData['attributes'] ?? [];
		if (!ChangeLogReservation::IsReserved($sClass) || !is_array($aReset) || $aReset === []) {
			return false;
		}
		foreach ($aReset as $oAttDef) {
			if (!$oAttDef instanceof \AttributeExternalKey || $oAttDef->GetDeletionPropagationOption() !== DEL_SILENT) {
				return false;
			}
		}

		return true;
	}
}
