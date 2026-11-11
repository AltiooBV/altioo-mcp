<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantFamilies;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantRefusals;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;

use UserRights;

/**
 * A class's rights, as this endpoint answers them and not only as UserRights does.
 *
 * The eight gates graded for the calling user ({@see GateGrades}), narrowed by
 * the barrier on classes that grant access, by the classes an operator listed
 * and by the change log's reservation, with `restricted` saying in words which
 * of them applies. The block exists so a model can pick a call that will
 * succeed instead of discovering the refusal by making it.
 *
 * It lives in Read and not in Rights, with the rest of the grading, because the
 * narrowing asks {@see ObjectHistory::IsReserved()} and Rights may not import Read.
 *
 * @internal
 * @since 2.0.0
 */
final class ClassRights
{
	/**
	 * What this caller may do to objects of $sClass, as the class-level gate
	 * answers it.
	 *
	 * Every write tool asks this same gate before it looks at any object -
	 * ObjectUpdate and ObjectCreate refuse on it, and the bulk tools refuse on
	 * the UR_ACTION_BULK_* half - so reporting it up front is what lets a model
	 * pick a call that can succeed instead of discovering the refusal by making
	 * it. iTop's console answers the same question by rendering a button or
	 * not; a client with no buttons has only this.
	 *
	 * Read as a gate, never as an outcome. 'yes' means the call gets past the
	 * class check and no further: the object can still refuse it through a
	 * silo, a lifecycle state or the datamodel's own DoCheckToWrite(), and an
	 * abstract class or a read-only database refuses whatever the rights say -
	 * isAbstract sits in the same payload for the first of those. 'no' is the
	 * firmer half, and the useful one: the tools raise on it before an object
	 * is ever fetched, so no object exists that could get past it.
	 *
	 * bulkCreate is the one key here iTop does not answer: there is no
	 * UR_ACTION_BULK_CREATE, so ObjectBulkCreate gates on UR_ACTION_CREATE and
	 * UR_ACTION_BULK_MODIFY together, and this reports the stricter of the two.
	 * It is derived rather than read because the alternative is a model working
	 * the conjunction out for itself, and nothing in 'create' or 'bulkModify'
	 * says they are the pair that decides it - the other two bulk tools are
	 * guessable from their names and this one is not, so a model looking for
	 * permission to create in bulk finds no key for it and concludes the call
	 * is ungated, or absent.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public static function RightsOf(string $sClass): array
	{
		$sCreate = GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE));
		$sBulkModify = GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_MODIFY));

		$aRights = [
			'read'       => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_READ)),
			'bulkRead'   => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ)),
			'create'     => $sCreate,
			'bulkCreate' => GateGrades::stricter($sCreate, $sBulkModify),
			'modify'     => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY)),
			'bulkModify' => $sBulkModify,
			'delete'     => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_DELETE)),
			'bulkDelete' => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE)),
		];

		return self::narrowedByTheBarrier($aRights, $sClass);
	}

	/**
	 * The gates as this endpoint answers them, not only as UserRights does.
	 *
	 * The block exists so that a model can pick a call that will succeed
	 * instead of discovering the refusal by making it - and on the classes
	 * that decide what this endpoint may do, it was doing the opposite. iTop
	 * says an administrator may modify UserToken, so the block said
	 * modify: "yes", and the write was then refused by a barrier the block
	 * never mentioned. A caller could learn that its own escalation was
	 * blocked only by attempting one.
	 *
	 * So the barrier is asked here as well. It has three answers and the block
	 * already has three grades to say them with:
	 *
	 * - refused outright - mcp_allow_access_administration is off, and no
	 *   object of the class can be written whatever iTop says: "no", which is
	 *   documented as final and is exactly that.
	 * - allowed for other people's access only - the setting is on, and
	 *   whether this particular row reaches the caller's own credential is a
	 *   question about the row: "depends", which is what that grade means.
	 * - not a granting class at all: untouched.
	 *
	 * `restricted` says which of those it is in words, because "no" alone
	 * sends a caller to ask an administrator for a right that no profile can
	 * grant - the fix is a module setting or the console, and naming it is the
	 * difference between a refusal and a dead end.
	 *
	 * Reads are never narrowed: listing a token to see when it expires is
	 * useful and discloses nothing, which is why the barrier does not touch
	 * them either.
	 *
	 * @param array<string, string> $aRights
	 *
	 * @return array<string, mixed>
	 */
	private static function narrowedByTheBarrier(array $aRights, string $sClass): array
	{
		$aGates = ['create', 'bulkCreate', 'modify', 'bulkModify', 'delete', 'bulkDelete'];

		try {
			// The change log first, and it is the one refusal that takes the
			// reads down with it. Every tool on this endpoint refuses these
			// classes outright - reads included, since core_object_history is
			// the way in - and the block said 'yes' with restricted: null,
			// which is the shape of a class nothing guards. A reviewer reading
			// that concludes the audit log is writable; a model reading it
			// makes the call and learns otherwise. Neither is the block's job.
			if (ObjectHistory::IsReserved($sClass)) {
				foreach (array_keys($aRights) as $sGate) {
					if ($sGate !== 'restricted') {
						$aRights[$sGate] = 'no';
					}
				}

				return $aRights + ['restricted' => sprintf(ObjectHistory::RESERVED_REFUSAL, $sClass)];
			}

			$bListed = AccessGrantFamilies::IsOperatorListed($sClass, Settings::GetEscalationClasses());
			$bBarred = AccessGrantFamilies::IsBarred($sClass);
			if (!$bBarred && !$bListed) {
				return $aRights + ['restricted' => null];
			}

			$bGranting              = AccessGrantFamilies::IsGranting($sClass);
			$bDelegating            = AccessGrantFamilies::IsDelegating($sClass);
			$bAutomation            = AccessGrantFamilies::IsAutomation($sClass);
			$bRecording             = AccessGrantFamilies::IsRecording($sClass);
			$bDetection             = AccessGrantFamilies::IsDetection($sClass);
			$bPersonal              = AccessGrantFamilies::IsPersonal($sClass);
			$bAdministrationAllowed = Settings::AllowsAccessAdministration();
			$bEscalationAllowed     = Settings::AllowsPrivilegeEscalation();
		} catch (\Throwable $e) {
			// A question about the barrier must not cost the block. Reporting
			// iTop's own answer is what this did before the barrier existed.
			return $aRights + ['restricted' => null];
		}

		// Before everything else, and 'no' with no setting named: this one has
		// no switch, and a caller told "unless X is on" goes and asks for X.
		if ($bRecording) {
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = 'no';
			}

			$aRights['restricted'] = sprintf(AccessGrantRefusals::RECORDING_REFUSAL, $sClass);

			return $aRights;
		}

		// Listed by the operator: refused while the setting is off, as
		// RefusalGiven() does, ahead of the built-in families. With it on, a
		// class nothing else here knows is an ordinary write, and one that is
		// also built in is graded below as it would be anyway.
		if ($bListed) {
			if (!$bEscalationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}

				return $aRights + ['restricted' => sprintf(AccessGrantRefusals::OPERATOR_ESCALATION_REFUSAL, $sClass)];
			}

			if (!$bBarred) {
				return $aRights + ['restricted' => sprintf(
					'%s is listed in mcp_privilege_escalation_classes, and mcp_allow_privilege_escalation is on, so it may be written here.',
					$sClass
				)];
			}
		}

		if ($bDetection && !$bGranting) {
			$aRights['restricted'] = sprintf(AccessGrantRefusals::DETECTION_REFUSAL, $sClass);

			if (!$bEscalationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}
			}

			return $aRights;
		}

		if ($bPersonal && !$bGranting) {
			// 'depends' on every write: whether the row is the caller's own is
			// a question about the row, which is exactly what the grade says.
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = GateGrades::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = $bAdministrationAllowed
				? sprintf(
					'%s holds one person\'s own settings. mcp_allow_access_administration is on, so another account\'s row may be written here; '
					.'with it off, only your own is. Reading is unaffected.',
					$sClass
				)
				: sprintf(
					'%s holds one person\'s own settings. A write is allowed on your own row and refused on anyone else\'s, whatever your profile says - '
					.'iTop\'s own API for these only ever touches the account it is called by, and so does this endpoint. '
					.'Turn on mcp_allow_access_administration to administer other people\'s, or use the iTop console. Reading is unaffected.',
					$sClass
				);

			return $aRights;
		}

		if ($bAutomation && !$bGranting) {
			$aRights['restricted'] = $bEscalationAllowed
				? sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, '
					.'on changes made by anyone. mcp_allow_privilege_escalation is on, so it may be written here.',
					$sClass
				)
				: sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, on changes made by '
					.'anyone, and outside this endpoint entirely. Nothing here can send mail or call a URL directly, so staging one cannot be graded '
					.'against what you may do: it is refused at all, whatever your profile says. Turn on mcp_allow_privilege_escalation, or use '
					.'the iTop console. Reading is unaffected.',
					$sClass
				);

			if (!$bEscalationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}
			}

			return $aRights;
		}

		// The delegating half next, and only where the granting half has
		// nothing to say: a class that is both is refused by the stricter of
		// the two, and that is the one below.
		if ($bDelegating && !$bGranting) {
			// Always 'depends', never 'no': this half settles nothing from the
			// class alone. Whether the write is allowed is a question about the
			// row - which class the definition points at, and what this caller
			// may do to that class - and 'depends' is the grade that says
			// exactly that. The setting is not consulted, because this half
			// does not read it.
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = GateGrades::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = sprintf(
				'%s defines work iTop\'s synchronisation engine carries out later, and the engine checks no rights on what it writes. '
				.'So a definition may be written here only for a class you could write yourself - create, modify and delete, in bulk - '
				.'and never for one that decides who may reach this endpoint, which no setting permits, because the engine also writes '
				.'without the check that keeps an administering call away from your own access. Name the target (scope_class, or '
				.'sync_source_id on a row hanging off a source) so the call can be graded. Reading is unaffected.',
				$sClass
			);

			return $aRights;
		}

		foreach ($aGates as $sGate) {
			$aRights[$sGate] = $bAdministrationAllowed
				? GateGrades::stricter($aRights[$sGate], 'depends')
				: 'no';
		}

		$aRights['restricted'] = $bAdministrationAllowed
			? sprintf(
				'%s decides what this endpoint may do, so a write is refused when it reaches the access you are '
				.'connected with - your own account, your own tokens, a profile link naming you. Everything else is allowed '
				.'because mcp_allow_access_administration is on.',
				$sClass
			)
			: sprintf(
				'%s decides what this endpoint may do, so it cannot be written here at all, whatever your profile says. '
				.'Turn on mcp_allow_access_administration to administer other people\'s access, or use the iTop console. '
				.'Reading is unaffected.',
				$sClass
			);

		return $aRights;
	}
}
