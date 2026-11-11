<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

use DBObject;
use DBObjectSet;
use DeletionPlan;
use Mcp\Exception\ToolCallException;
use Throwable;
use UserRights;

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
	 * @param DeletionPlan $oPlan  A plan already computed by CheckToDelete().
	 * @param string       $sWhat  What is being deleted, e.g. "UserRequest::12", named in the refusal.
	 *
	 * @throws ToolCallException When the cascade reaches something this caller may not touch.
	 *
	 * @since 1.0.0
	 */
	public static function CheckDeletionRights(DeletionPlan $oPlan, string $sWhat): void
	{
		// A delete that would reach objects of a class this channel does not serve is not made here, and not
		// reported with them left out: a plan that shows less than what happens is how a delete goes wrong. It
		// does not say which class (doc/adr/0012).
		foreach ([$oPlan->ListDeletes(), $oPlan->ListUpdates()] as $aClasses) {
			foreach (array_keys($aClasses) as $sReached) {
				/** @psalm-suppress InvalidCast */
				if (ExclusionPolicy::ExcludesClass((string)$sReached)) {
					throw new ToolCallException("{$sWhat} cannot be deleted here: it reaches related objects this channel does not serve. Do it in the iTop console.");
				}
			}
		}

		$aRefused  = [];
		$bUnreadable = false;

		foreach ($oPlan->ListDeletes() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf($aEntries, 'to_delete'),
				UR_ACTION_DELETE,
				'deleted',
				$aRefused,
				$bUnreadable
			);
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf($aEntries, 'to_reset'),
				UR_ACTION_MODIFY,
				'modified',
				$aRefused,
				$bUnreadable
			);
		}

		if ($bUnreadable) {
			$aRefused[] = 'it also reaches related objects this user may not read.';
		}

		if (empty($aRefused)) {
			return;
		}

		throw new ToolCallException(sprintf(
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
	 * What a deletion would take with it, as the two lists both delete tools
	 * report.
	 *
	 * One implementation, read straight after {@see CheckDeletionRights()}: a
	 * rights rule applied to one copy of this and not the other is the bug a
	 * per-tool copy invites.
	 *
	 * Nothing here filters. It does not have to: by the time a plan is
	 * serialised, CheckDeletionRights() has refused every plan holding an
	 * object this caller may not read, so the class and id of everything left
	 * are things they could have looked up themselves. That is the other half
	 * of why the check is a refusal rather than a mask - a plan shown to a
	 * person before they approve it has to be complete, and the only way for it
	 * to be both complete and safe is for the incomplete case not to exist.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function SerializeDeletionPlan(?DeletionPlan $oPlan): array
	{
		if ($oPlan === null) {
			return ['deleted' => [], 'updated' => []];
		}

		$aDeleted = [];
		$aUpdated = [];

		foreach ($oPlan->ListDeletes() as $sClass => $aEntries) {
			foreach (array_keys($aEntries) as $iId) {
				$aDeleted[] = WriteOutcome::Identity($sClass, $iId);
			}
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aEntries) {
			foreach (array_keys($aEntries) as $iId) {
				$aUpdated[] = WriteOutcome::Identity($sClass, $iId);
			}
		}

		return ['deleted' => $aDeleted, 'updated' => $aUpdated];
	}
}
