<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Service\Rights\ClassRights;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;
use DBObject;
use DBObjectSet;
use MetaModel;
use UserRights;

/**
 * What this caller may do to one particular object: the rights of the four write gates and the
 * stimuli the object accepts now.
 *
 * Both answers need the object in hand, which is why a read is where they are settled, and both
 * are read as a snapshot of the rights layer, never as a promise that the write will succeed.
 * It was the second half of `ObjectSerializer` in 1.x.
 *
 * @api
 * @since 2.0.0
 */
final class ObjectGrades
{
	/**
	 * What this caller may do to this particular object, as the gates answer
	 * it with the object in hand.
	 *
	 * The class-level block core_class_schema reports says whether the gate
	 * opens at all; where it answers 'depends', it is the addon asking to be
	 * shown the object before it decides. A read already has the object, so
	 * this is where that question can actually be settled - and settling it is
	 * the difference between an agent planning a write it can make and one
	 * discovering the refusal by making it.
	 *
	 * Only the class-level 'depends' costs anything. 'yes' and 'no' are
	 * answers the addon gave without reference to any object, so they are
	 * carried straight through rather than asked again per row.
	 *
	 * Read as a gate and as a snapshot, which is what it is: it clears the
	 * rights layer and nothing beyond it. A lifecycle state, the datamodel's
	 * own DoCheckToWrite(), a read-only database, or another user getting
	 * there first can each still refuse the write this reports as available.
	 *
	 * @param array<string, string>|null $aClassGrades ClassRights::RightsOf() for the class, read once per class per page.
	 * @param DBObjectSet|null           $oInstanceSet Reused across gates of one object; created here on first need.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public static function RightsOn(DBObject $oObject, string $sClass, ?array $aClassGrades, ?DBObjectSet &$oInstanceSet): array
	{
		$aClassGrades ??= ClassRights::RightsOf($sClass);
		$aActions = [
			'modify'     => UR_ACTION_MODIFY,
			'bulkModify' => UR_ACTION_BULK_MODIFY,
			'delete'     => UR_ACTION_DELETE,
			'bulkDelete' => UR_ACTION_BULK_DELETE,
		];

		$aGrades = [];
		foreach (GateGrades::OBJECT_RIGHTS_KEYS as $sKey) {
			$sClassGrade = $aClassGrades[$sKey] ?? 'depends';
			if ($sClassGrade !== 'depends') {
				$aGrades[$sKey] = $sClassGrade;
				continue;
			}

			$iKey = (int)$oObject->GetKey();
			if ($iKey < 1) {
				// Not in the database to be asked about, so the question cannot
				// be resolved and the answer stays what the class said.
				$aGrades[$sKey] = 'depends';
				continue;
			}

			$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));
			$iAllowed = UserRights::IsActionAllowed($sClass, $aActions[$sKey], $oInstanceSet);
			$aGrades[$sKey] = match ((int)$iAllowed) {
				UR_ALLOWED_NO => 'no',
				UR_ALLOWED_YES => 'yes',
				default => 'depends',
			};
		}

		return $aGrades;
	}

	/**
	 * The stimuli this object will actually accept right now, and whether this
	 * caller may apply them.
	 *
	 * core_class_schema reports the whole lifecycle graph: every state and
	 * every transition out of it. What that cannot say is which of them apply
	 * to the object in front of you, because that depends on the state it is
	 * in - so a model reading the graph has to find the state attribute, match
	 * it against the states, and hope it picked the right attribute. A read
	 * has the object, so it can simply say.
	 *
	 * Two gates decide 'allowed', and the stricter wins, because
	 * core_object_apply_stimulus checks both: UR_ACTION_MODIFY on the object,
	 * and the stimulus itself through UserRights::IsStimulusAllowed(). A
	 * transition offered here with 'yes' is one whose rights are settled; it
	 * is still not a promise, since the datamodel's own DoCheckToWrite() and
	 * whatever the transition requires of mandatory attributes are checked
	 * when the write is attempted and not before.
	 *
	 * Null for a class with no lifecycle, which is most of the CMDB - an empty
	 * list would read as "this ticket is stuck", which is a different claim.
	 *
	 * @param array<string, string>|null $aObjectRights {@see RightsOn()} for this object, when it has already been read.
	 * @param DBObjectSet|null           $oInstanceSet  Reused across gates of one object; created here on first need.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	public static function StimuliOn(DBObject $oObject, string $sClass, ?array $aObjectRights, ?DBObjectSet &$oInstanceSet): ?array
	{
		if (!MetaModel::HasLifecycle($sClass)) {
			return null;
		}

		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($sStateAttCode === '' || ExclusionPolicy::ExcludesAttribute($sClass, $sStateAttCode)) {
			return null; // no lifecycle on this channel when its state is excluded (doc/adr/0012)
		}

		$sState = (string)$oObject->Get($sStateAttCode);
		$sModify = $aObjectRights['modify'] ?? self::RightsOn($oObject, $sClass, null, $oInstanceSet)['modify'];

		$aStimuli = MetaModel::EnumStimuli($sClass);
		$aAvailable = [];
		foreach (MetaModel::EnumTransitions($sClass, $sState) as $sStimulusCode => $aTransitionDef) {
			// Both halves, not just the stricter one. Every entry here is a
			// transition the datamodel declares out of the current state, so a
			// 'no' is always a rights answer - but which of the two rights is
			// the difference between "nobody may drive this transition by hand"
			// and "this account may not modify this object at all", and a
			// caller told only 'no' reads the first as the second and reports
			// the object as stuck. ev_timeout is the case in hand: the
			// lifecycle offers it, no profile grants it, and the object is
			// otherwise perfectly writable.
			$sStimulus = self::stimulusGrade($oObject, $sClass, $sStimulusCode, $oInstanceSet);
			$aAvailable[] = [
				'stimulus'     => $sStimulusCode,
				'label'        => isset($aStimuli[$sStimulusCode]) ? $aStimuli[$sStimulusCode]->GetLabel() : $sStimulusCode,
				'target_state' => $aTransitionDef['target_state'] ?? null,
				'allowed'      => self::stricterGrade($sModify, $sStimulus),
				'gates'        => [
					'modify'   => $sModify,
					'stimulus' => $sStimulus,
				],
			];
		}

		return [
			'state_attribute' => $sStateAttCode,
			'state'           => $sState,
			'available'       => $aAvailable,
		];
	}

	/**
	 * One stimulus gate, graded like every other.
	 *
	 * IsStimulusAllowed() is declared without a return type and the shipped
	 * addon answers with a bool, so the cast reads both: true and false land
	 * on UR_ALLOWED_YES and UR_ALLOWED_NO, which are 1 and 0.
	 */
	private static function stimulusGrade(DBObject $oObject, string $sClass, string $sStimulusCode, ?DBObjectSet &$oInstanceSet): string
	{
		$iKey = (int)$oObject->GetKey();
		if ($iKey > 0) {
			$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));
		}

		$mAllowed = UserRights::IsStimulusAllowed($sClass, $sStimulusCode, $iKey > 0 ? $oInstanceSet : null);

		return match ((int)$mAllowed) {
			UR_ALLOWED_NO => 'no',
			UR_ALLOWED_YES => 'yes',
			default => 'depends',
		};
	}

	/** 'no' ends the call on its own; 'depends' leaves the pair unsettled. */
	private static function stricterGrade(string $sLeft, string $sRight): string
	{
		if ($sLeft === 'no' || $sRight === 'no') {
			return 'no';
		}

		return ($sLeft === 'depends' || $sRight === 'depends') ? 'depends' : 'yes';
	}
}
