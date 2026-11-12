<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use MetaModel;
use Throwable;
use UserRights;

/**
 * Grades a write that stages work for something else to carry out.
 *
 * A synchronisation definition, or a trigger with an action behind it, is a
 * write performed later by a process that consults nobody's rights. So the
 * rights are asked here, once, about the class the definition points at: which
 * class that is ({@see self::DelegatedTarget()}), and whether the caller could
 * have done the same thing itself by calling a tool
 * ({@see self::CouldNotDoItDirectly()}) - on the class, on each of the five
 * actions a data source performs, and on each attribute. Refuses whenever it
 * cannot ask. {@see AccessGrants} decides when this is consulted.
 *
 * @internal
 * @since 2.0.0
 */
final class DelegatedWrites
{
	/**
	 * The class a synchronisation definition would write into, or null when
	 * this row does not settle one.
	 *
	 * Three shapes to cover. A data source names its target in scope_class,
	 * and on an update the caller may be changing it or may be leaving it
	 * alone - so the values being written are asked first and the stored row
	 * second, the same order {@see CallersOwnAccess::ReachesTheCaller()} uses and for the same
	 * reason. Everything else in the family - the attribute mappings, the
	 * staged replicas - hangs off a data source, so the question is passed up
	 * to it.
	 *
	 * Null means "this row does not say", not "nothing". The outright refusal
	 * above already covers the instance that never opted in; this only ever
	 * adds a refusal where the answer is known and is a granting class.
	 *
	 * @param array<string, mixed> $aFields
	 */
	public static function DelegatedTarget(string $sClass, ?int $iId, array $aFields): ?string
	{
		// Asked of the values first, and without a datamodel: a caller naming
		// its own target has settled the question, and needing MetaModel to
		// read a key out of an array is how this rule came to not apply in the
		// one suite that runs without one.
		foreach ([AccessGrantRoots::SCOPE_ATTRIBUTE => null, AccessGrantRoots::TARGET_ATTRIBUTE => null, AccessGrantRoots::SOURCE_ATTRIBUTE => 'source'] as $sAttCode => $sKind) {
			if (!array_key_exists($sAttCode, $aFields)) {
				continue;
			}

			if ($sKind === null) {
				$sScope = trim((string) $aFields[$sAttCode]);

				return $sScope === '' ? null : $sScope;
			}

			return self::ScopeOfSource((int) $aFields[$sAttCode]);
		}

		if (!class_exists('MetaModel')) {
			return null;
		}

		try {
			if (!MetaModel::IsValidClass($sClass)) {
				return null;
			}

			$oRow = ($iId !== null && $iId > 0) ? MetaModel::GetObject($sClass, $iId, false, true) : null;
			if ($oRow === null) {
				return null;
			}

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::TARGET_ATTRIBUTE)) {
				$sTarget = trim((string) $oRow->Get(AccessGrantRoots::TARGET_ATTRIBUTE));

				return $sTarget === '' ? null : $sTarget;
			}

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::SCOPE_ATTRIBUTE)) {
				$sScope = trim((string) $oRow->Get(AccessGrantRoots::SCOPE_ATTRIBUTE));

				return $sScope === '' ? null : $sScope;
			}

			// A mapping or a replica: the target is whatever its data source
			// says, so ask that row the question instead.
			if (!MetaModel::IsValidAttCode($sClass, AccessGrantRoots::SOURCE_ATTRIBUTE)) {
				return null;
			}

			return self::ScopeOfSource((int) $oRow->Get(AccessGrantRoots::SOURCE_ATTRIBUTE));
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Whether the caller could have written the target class itself.
	 *
	 * This is what is left of the barrier once the escalation is shut, and it
	 * is the whole of it: the engine runs as a trusted internal process and
	 * consults no UserRights at all, so a definition is a write with the
	 * rights check removed. Removing a check the caller would have passed is
	 * a scheduling decision. Removing one it would have failed is the bypass.
	 *
	 * All five actions, because a data source does all of them. It creates the
	 * objects it finds, modifies the ones it matches, and - depending on the
	 * delete_policy it carries, which is an attribute the same caller writes -
	 * deletes the ones that have gone. In bulk, by construction. Asking only
	 * about the one the caller happens to be doing today would be asking about
	 * the wrong call.
	 *
	 * Refuses whenever it cannot ask. No UserRights, no constants, a class the
	 * rights model will not answer for: every one of those is a refusal, for
	 * the reason the self guard gives - the cost of being wrong this way is a
	 * refusal an administrator can satisfy, and the cost the other way is the
	 * bypass this exists to close.
	 *
	 * What it does not cover, and cannot: per-attribute rights. iTop fills a
	 * new source's mapping in itself, every attribute at update:true, without
	 * any call reaching this endpoint - so there is no write here to refuse.
	 * A caller who may modify a class but not one of its attributes can
	 * therefore have that attribute overwritten by the engine. The honest
	 * scope of this rule is the class, and {@see SECURITY.md} says so.
	 */
	public static function CouldNotDoItDirectly(string $sClass, string $sTarget, bool $bWouldWrite): ?string
	{
		// First, the barriers themselves. A mechanism pointed at a class this
		// endpoint will not write is the plainest form of the thing being
		// refused: the audit trail, the rights model, another mechanism. Asked
		// as IsBarred() rather than IsGranting() so that every rule here covers
		// its own indirect route without being listed again - a source pointed
		// at Event, or at AsyncTask, is refused by the rule that refuses Event
		// and AsyncTask.
		if (AccessGrantFamilies::IsBarred($sTarget)) {
			return sprintf(AccessGrantRefusals::DELEGATED_TARGET_REFUSAL, $sClass, $sTarget);
		}

		if (!class_exists('UserRights') || !class_exists('MetaModel')) {
			return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
		}

		try {
			// Maintenance mode, named rather than described. access_mode
			// withholds writing from everyone (ACCESS_READONLY) or from
			// everyone but administrators (ACCESS_ADMIN_WRITE), and
			// UserRights::IsActionAllowed() answers UR_ALLOWED_NO for every
			// writing action while it holds. Without this the refusal below
			// would enumerate create, modify and delete as though the caller's
			// profile were the problem, and send an operator to grant rights
			// that would change nothing.
			//
			// It matters here specifically because this barrier runs before a
			// tool reaches its own read-only check - the tools ask this first,
			// so on a mechanism class this is the message the caller gets.
			if ($bWouldWrite && MetaModel::DBIsReadOnly()) {
				return sprintf(
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'This instance is in read-only mode (access_mode), so nothing may be written here at all just now - which has nothing to do with your rights on it.',
					$sTarget
				);
			}

			if (!MetaModel::IsValidClass($sTarget)) {
				return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, "'{$sTarget}' is not a class this instance knows.", $sTarget);
			}

			// Reading first, and for every mechanism, because every one of them
			// is a way to get data out. A trigger hands the object it fired on
			// to an action, and ActionEmail's body takes $this->attribute$
			// placeholders; a data source mirrors the rows it matches into its
			// replicas. So a class this caller may not read is a class it may
			// not arrange to have read out on its behalf - the same sentence as
			// the write rule, and the half that keeps a mechanism from becoming
			// an export of what the read tools would have masked or refused.
			$aActions = [
				'read'         => 'UR_ACTION_READ',
				'read in bulk' => 'UR_ACTION_BULK_READ',
			];

			// And the writing actions, only for a mechanism that writes: a
			// data source creates what it finds, modifies what it matches and,
			// under its own delete_policy, deletes what has gone, in bulk by
			// construction. A trigger writes nothing of its own, so requiring
			// write on the class it watches would refuse an ordinary
			// notification for no reason the rule gives.
			if ($bWouldWrite) {
				$aActions += [
					'create'         => 'UR_ACTION_CREATE',
					'modify'         => 'UR_ACTION_MODIFY',
					'delete'         => 'UR_ACTION_DELETE',
					'modify in bulk' => 'UR_ACTION_BULK_MODIFY',
					'delete in bulk' => 'UR_ACTION_BULK_DELETE',
				];
			}

			$aRefused = [];
			foreach ($aActions as $sWhat => $sConstant) {
				if (!defined($sConstant)) {
					return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
				}
				if (!UserRights::IsActionAllowed($sTarget, constant($sConstant))) {
					$aRefused[] = $sWhat;
				}
			}

			if ($aRefused !== []) {
				return sprintf(
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'You may not '.implode(', ', $aRefused)." objects of '{$sTarget}' through this endpoint, and the engine would.",
					$sTarget
				);
			}

			// And the attributes, which is where the rule bites hardest and
			// where it was missing. A data source is not a write of the fields
			// somebody chose: SynchroDataSource fills its own mapping in on
			// creation, one SynchroAttribute per attribute of the class, and
			// the attribute that decides whether the engine writes each one -
			// `update` - is declared with default_value true and Set(1) on
			// every row it builds (synchrodatasource.class.inc.php). Creating
			// the source therefore hands the engine every attribute of the
			// class, so the caller has to hold every attribute of the class - a
			// per-attribute grant it does not hold is one this would have got
			// round.
			//
			// Keyed on `update` and deliberately not on `update_policy`, which
			// is a three-valued enum - master_locked, master_unlocked,
			// write_if_empty - defaulting to the first. It decides what happens
			// to the attribute around the write (whether the console may still
			// edit it, whether the engine only fills a blank), not whether
			// there is one. All three write, so none of them is the question
			// this rule is asking.
			$aUnreadable = self::AttributesTheCallerMayNot($sTarget, ['UR_ACTION_READ']);
			if ($aUnreadable !== []) {
				return sprintf(
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'A read here masks '.self::few($aUnreadable).", and nothing masks them once this carries the object out.",
					$sTarget
				);
			}

			if ($bWouldWrite) {
				// Both actions, because the engine does both. UR_ACTION_CREATE
				// and UR_ACTION_MODIFY are separate codes in iTop (7 and 2) and
				// an addon is free to grade them differently, so an attribute
				// the caller may not set when the object is first written is
				// one the engine would set for it - CreateObjectFromReplica()
				// is a create, and asking only about modify graded the wrong
				// half of what a data source does.
				$aUnwritable = self::AttributesTheCallerMayNot($sTarget, ['UR_ACTION_MODIFY', 'UR_ACTION_CREATE']);
				if ($aUnwritable !== []) {
					return sprintf(
						AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
						$sClass,
						$sTarget,
						'A source fills its own mapping in with every attribute of the class, and it both creates and updates - you may not set '.self::few($aUnwritable).' here.',
						$sTarget
					);
				}
			}
		} catch (Throwable) {
			return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
		}

		return null;
	}

	/**
	 * The writable attributes of $sClass this caller is refused.
	 *
	 * Only the outright refusals count. IsActionAllowedOnAttribute() is
	 * tri-state and an addon that grades per object answers DEPENDS, which is
	 * a question about a row - and a data source has no row to ask about, so
	 * treating DEPENDS as a refusal would refuse every source on any instance
	 * whose addon grades that way. UR_ALLOWED_NO is the answer that does not
	 * depend on anything.
	 *
	 * @return array<int, string>
	 */
	private static function AttributesTheCallerMayNot(string $sClass, array $aActionConstants): array
	{
		if (!defined('UR_ALLOWED_NO')) {
			return [];
		}

		$aCodes = [];
		foreach ($aActionConstants as $sConstant) {
			if (!defined($sConstant)) {
				return [];
			}
			$aCodes[] = constant($sConstant);
		}

		// Skipped only where the datamodel says the attribute can never be
		// written by anyone - a computed value, an external field. That is a
		// property of the definition and holds on both paths. Per-state flags
		// are not asked, and cannot be: GetInitialStateAttributeFlags() and
		// GetAttributeFlags() answer about an object, and a definition staged
		// for later has no object to ask about.
		$bWriting = !in_array('UR_ACTION_READ', $aActionConstants, true);
		$aRefused = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if ($bWriting && !$oAttDef->IsWritable()) {
				continue;
			}
			foreach ($aCodes as $iAction) {
				if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, $iAction) === UR_ALLOWED_NO) {
					$aRefused[] = $sAttCode;
					continue 2;
				}
			}
		}

		sort($aRefused);

		return $aRefused;
	}

	/**
	 * A few of them by name, and a count for the rest.
	 *
	 * @param array<int, string> $aAttCodes
	 */
	private static function few(array $aAttCodes): string
	{
		$aShown = array_slice($aAttCodes, 0, 5);

		return implode(', ', $aShown)
			.(count($aAttCodes) > count($aShown) ? ' and '.(count($aAttCodes) - count($aShown)).' more' : '');
	}

	/**
	 * The class a data source is pointed at, read from the source itself.
	 *
	 * Null whenever it cannot be established, which the caller refuses on: an
	 * id that names no source, a datamodel that will not answer, a source with
	 * no scope set.
	 */
	private static function ScopeOfSource(int $iSourceId): ?string
	{
		if ($iSourceId < 1 || !class_exists('MetaModel')) {
			return null;
		}

		try {
			$oSource = MetaModel::GetObject('SynchroDataSource', $iSourceId, false, true);
			if ($oSource === null) {
				return null;
			}

			$sScope = trim((string) $oSource->Get(AccessGrantRoots::SCOPE_ATTRIBUTE));

			return $sScope === '' ? null : $sScope;
		} catch (Throwable) {
			return null;
		}
	}
}
