<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use Altioo\iTop\Extension\MCP\Api\ToolRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use CMDBChange;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use Throwable;

/**
 * The opt-in cap on committed writes per credential (ADR 0019).
 *
 * A write tool asks {@see RefusalFor()} in its own source, before it writes and never for a dry run,
 * as it asks `AccessGrants::RefusalFor` (ADR 0014); the contract tests read that call. A pack's
 * write tool asks the same, with the number of objects it is about to write.
 *
 * **What it counts.** `AltiooEventMCPService.written_objects`, the objects each call's own change
 * touched, summed over the last hour for the credential asking: the personal token, else the
 * application token, else (a console session or Basic) the user's own rows that carry no token.
 * The count is written by the controller from the change iTop recorded, so a tool that forgets to
 * ask is still counted, and the next call is refused.
 *
 * **What it fails closed on.** A malformed setting, an audit trail that does not record successful
 * calls (`log_mcp_service` off, `log_mcp_level` error, `tools/call` not audited), a credential
 * that cannot be named, and a count that cannot be read: every write is refused and the reason
 * logged. Reads are served: the cap restricts writes only.
 *
 * Known limits, stated in the ADR: calls running at the same moment can each pass before either
 * is recorded, so the overshoot is bounded by the concurrency; a row lost to a failed audit write
 * is a call not counted.
 *
 * @api
 * @since 2.0.0
 */
final class WriteCap
{
	/** Objects this request has been allowed to write so far, which no audit row holds yet. */
	private static int $iReserved = 0;

	/**
	 * Why this call may not commit $iObjects writes now, or null when it may.
	 *
	 * Ask it only for a call that is about to commit: a dry run does not ask and does not count.
	 * A call that is allowed is reserved for the rest of the request, so a request carrying
	 * several calls cannot spend the same room twice.
	 *
	 * @param int $iObjects How many objects the call is about to create, change, delete, move through
	 *                      a stimulus or attach to. A bulk call asks once for all of them.
	 *
	 * @param bool $bUpperBound The count is an upper bound, not what the write will record (a bulk update that sends link-set entries:
	 *                          the objects are not loaded to tell which entries they already hold). A refusal then says so, and that
	 *                          splitting the call helps.
	 *
	 * @since 2.0.0
	 */
	public static function RefusalFor(int $iObjects, bool $bUpperBound = false): ?string
	{
		$iCap = Settings::GetMaxWritesPerHour();
		if ($iCap === 0) {
			return null;
		}

		$bTrailCounts = Settings::AuditCountsWrites();
		$aRows = ($iCap !== null && $bTrailCounts) ? self::RowsInWindow() : [];

		$iNow = time();
		$sRefusal = self::Decide($iCap, $bTrailCounts, $aRows, self::$iReserved, $iObjects, $iNow);
		if ($sRefusal === null) {
			self::$iReserved += max(0, $iObjects);

			return null;
		}

		// The operator's log names the setting; a portal user is not told the console's vocabulary.
		ErrorReport::Log('A write was refused by the write cap: '.$sRefusal);

		$sAnswered = PortalMode::Current() === null
			? $sRefusal
			: self::Decide($iCap, $bTrailCounts, $aRows, self::$iReserved, $iObjects, $iNow, true);

		return $bUpperBound ? $sAnswered.' '.self::UPPER_BOUND_NOTE : $sAnswered;
	}

	/** Said after a refusal whose count is an upper bound (a bulk update that names a link set). */
	public const UPPER_BOUND_NOTE = 'That count is an upper bound: for every object it counts each link-set entry the call sends and each row the named link sets hold now, including entries an object already holds. Splitting the call into smaller ones helps.';

	/**
	 * The link rows a write is about to add, change or remove, for the attributes it set, to be added to the objects a tool asks for.
	 *
	 * The cap counts objects (ADR 0019): "objects, not calls", and a link row is an object - a ticket created with
	 * 19 `contacts_list` entries writes 20 of them, and a tool that asked for 1 let the call through and only the next one
	 * was refused (review 2026-11-18, HA-8). Asked once the values are Set() and before the write: the object's own link set
	 * holds what the write would add, change and remove, which is what iTop will record, and a complete list that repeats
	 * rows already there counts none of them.
	 *
	 * @param array<int, string|int> $aAttCodes The attributes this call set.
	 *
	 * @since 2.1.0
	 */
	public static function LinkRowsOf(DBObject $oObject, array $aAttCodes): int
	{
		$iRows = 0;
		foreach ($aAttCodes as $mAttCode) {
			$sAttCode = (string)$mAttCode;
			try {
				if (!MetaModel::IsValidAttCode(get_class($oObject), $sAttCode) || !MetaModel::GetAttributeDef(get_class($oObject), $sAttCode)->IsLinkSet()) {
					continue;
				}
				$mSet = $oObject->Get($sAttCode);
				if (!$mSet instanceof \ormLinkSet) {
					continue;
				}
				if ($oObject->IsNew()) {
					$iRows += $mSet->Count();
					continue;
				}
				$oAttDef = MetaModel::GetAttributeDef(get_class($oObject), $sAttCode);
				$sAdditionalKey = ($oAttDef->IsIndirect() && !$oAttDef->DuplicatesAllowed()) ? $oAttDef->GetExtKeyToRemote() : null;
				$oOriginal = $mSet->GetOriginalSet() ?? DBObjectSet::FromScratch($mSet->GetClass());
				// The comparison ormLinkSet and EditPolicy make: what the write would add, change and remove.
				$aDiff = (new \DBObjectSetComparator($oOriginal, $mSet, [$oAttDef->GetExtKeyToMe()], $sAdditionalKey))->GetDifferences();
				$iRows += count($aDiff['added']) + count($aDiff['removed']);
				foreach ($aDiff['modified'] as $oLink) {
					// iTop lists a row the caller repeated as modified and writes nothing for it: only a row that differs counts.
					$iRows += self::differsFromStored($oLink) ? 1 : 0;
				}
			} catch (Throwable $e) {
				// What cannot be counted is counted as the whole set: over, never under.
				try {
					$mSet = $oObject->Get($sAttCode);
					$iRows += $mSet instanceof \ormLinkSet ? $mSet->Count() : 0;
				} catch (Throwable $e2) {
					$iRows += 1;
				}
			}
		}

		return $iRows;
	}

	private static function differsFromStored(DBObject $oLink): bool
	{
		try {
			$oStored = MetaModel::GetObject(get_class($oLink), (int)$oLink->GetKey(), false, true);
			if ($oStored === null) {
				return true;
			}
			foreach ($oLink->ListChanges() as $sAttCode => $mValue) {
				if (!is_scalar($mValue) && $mValue !== null) {
					return true;
				}
				if ((string)$oStored->Get((string)$sAttCode) !== (string)$mValue) {
					return true;
				}
			}

			return false;
		} catch (Throwable $e) {
			return true;
		}
	}

	/**
	 * The link-set entries a caller sent for a class, before any object exists to ask: every entry of every link set in
	 * the fields. Exact for a creation. For a write to objects that already hold rows it is the additions' side of an
	 * upper bound: an entry the object has already is counted too, and the rows a write removes are not counted here at
	 * all, so a bulk update adds {@see LinkRowsHeldBy()}.
	 *
	 * @param array<array-key, mixed> $aFields
	 *
	 * @since 2.1.0
	 */
	public static function LinkEntriesIn(string $sClass, array $aFields): int
	{
		$iEntries = 0;
		foreach ($aFields as $mAttCode => $mValue) {
			try {
				if (is_array($mValue) && MetaModel::IsValidAttCode($sClass, (string)$mAttCode) && MetaModel::GetAttributeDef($sClass, (string)$mAttCode)->IsLinkSet()) {
					$iEntries += count($mValue);
				}
			} catch (Throwable $e) {
				$iEntries += is_array($mValue) ? count($mValue) : 0;
			}
		}

		return $iEntries;
	}

	/**
	 * The link rows the objects of a bulk update hold now in each link set the call names: what the call can remove, which
	 * {@see LinkEntriesIn()} does not see (`"contacts_list": []` sends no entry and removes every row; review 2026-11-20, N-4).
	 * One counting query per link set over all the ids, never an object loaded (ADR 0019). With the cap off it counts nothing,
	 * and a link set it cannot count refuses the call, as every other count the cap cannot read does.
	 *
	 * @param array<array-key, mixed> $aIds    The ids of the objects, as the tool validated them.
	 * @param array<array-key, mixed> $aFields The attributes the call sets.
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When a link set cannot be counted.
	 * @since 2.1.0
	 */
	public static function LinkRowsHeldBy(string $sClass, array $aIds, array $aFields): int
	{
		if ($aIds === [] || Settings::GetMaxWritesPerHour() === 0) {
			return 0;
		}

		$iRows = 0;
		foreach (array_keys($aFields) as $mAttCode) {
			try {
				if (!MetaModel::IsValidAttCode($sClass, (string)$mAttCode)) {
					continue;
				}
				$oAttDef = MetaModel::GetAttributeDef($sClass, (string)$mAttCode);
				if (!$oAttDef->IsLinkSet()) {
					continue;
				}
				foreach (array_chunk(array_map('intval', array_values($aIds)), 500) as $aChunk) {
					$oSearch = new DBObjectSearch($oAttDef->GetLinkedClass());
					$oSearch->AddCondition($oAttDef->GetExtKeyToMe(), $aChunk, 'IN');
					$iRows += (new DBObjectSet($oSearch))->Count();
				}
			} catch (Throwable $e) {
				ErrorReport::Log('The write cap could not count the link rows a bulk update can remove, so the call is refused ('.get_class($e).').');

				throw ToolRefusal::Because('Writes are refused: the link rows this call could remove cannot be counted now. Reads are not affected. Ask your administrator.');
			}
		}

		return $iRows;
	}

	/**
	 * The decision, given everything it needs: pure, for the unit suite.
	 *
	 * @param int|null                               $iCap         The setting; null when malformed, 0 for off.
	 * @param bool                                   $bTrailCounts Whether the audit trail records successful calls.
	 * @param array<int, array{0: int, 1: int}>|null $aRows        `[time of the call, objects it wrote]`, oldest first,
	 *                                                             for the credential and the window; null when unreadable.
	 * @param int                                    $iReserved    What this request has already been allowed.
	 * @param int                                    $iAsked       What this call is about to write.
	 * @param int                                    $iNow         The time, in seconds.
	 * @param bool                                   $bPortal      Word the refusal for a portal user, without the setting's name.
	 *
	 * @internal
	 */
	public static function Decide(?int $iCap, bool $bTrailCounts, ?array $aRows, int $iReserved, int $iAsked, int $iNow, bool $bPortal = false): ?string
	{
		if ($iCap === 0) {
			return null;
		}
		$sName = Settings::MODULE_SETTING_MAX_WRITES_PER_HOUR;
		if ($bPortal && ($iCap === null || !$bTrailCounts || $aRows === null)) {
			return 'Writes are refused on this instance for now. Reading is not affected. Ask your administrator.';
		}
		if ($iCap === null) {
			return "Writes are refused: '$sName' is not a non-negative integer. Reads are not affected. Ask your administrator to correct it.";
		}
		if (!$bTrailCounts || $aRows === null) {
			return "Writes are refused: '$sName' is set and this instance cannot count writes now. Reads are not affected. Ask your administrator.";
		}
		if ($iAsked <= 0) {
			return null;
		}
		if ($iAsked > $iCap) {
			if ($bPortal) {
				return sprintf(
					'This call would write %d objects, more than the %d per hour your access allows. Write fewer, or ask your administrator.',
					$iAsked,
					$iCap
				);
			}

			return sprintf(
				'This call would write %d objects, more than the cap of %d per hour on this credential (%s). Write fewer, or ask your administrator.',
				$iAsked,
				$iCap,
				$sName
			);
		}

		$iUsed = $iReserved;
		foreach ($aRows as [, $iWritten]) {
			$iUsed += $iWritten;
		}
		$iOver = $iUsed + $iAsked - $iCap;
		if ($iOver <= 0) {
			return null;
		}

		// When enough of the oldest writes leave the window for this call to fit.
		$iFreesAt = $iNow + Settings::WRITE_CAP_WINDOW_SECONDS;
		$iReleased = 0;
		foreach ($aRows as [$iTime, $iWritten]) {
			$iReleased += $iWritten;
			if ($iReleased >= $iOver) {
				$iFreesAt = $iTime + Settings::WRITE_CAP_WINDOW_SECONDS;
				break;
			}
		}
		$iMinutes = max(1, (int)ceil(($iFreesAt - $iNow) / 60));

		if ($bPortal) {
			return sprintf(
				'You have reached the limit of %d objects written per hour; this call would write %d more. Room frees up in about %d minute%s. Dry runs are not affected. Ask your administrator if you need more.',
				$iCap,
				$iAsked,
				$iMinutes,
				$iMinutes === 1 ? '' : 's'
			);
		}

		return sprintf(
			'This credential has reached its write cap of %d objects per hour (%s); this call would write %d more. Room frees up in about %d minute%s. Dry runs are not affected.',
			$iCap,
			$sName,
			$iAsked,
			$iMinutes,
			$iMinutes === 1 ? '' : 's'
		);
	}

	/**
	 * What the credential asking wrote in the last hour, oldest first, or null when it cannot be told.
	 *
	 * @return array<int, array{0: int, 1: int}>|null
	 */
	private static function RowsInWindow(): ?array
	{
		try {
			$aIdentity = TokenScopes::RememberedIdentity();
			if ($aIdentity !== null && in_array($aIdentity['class'], ['PersonalToken', 'UserToken'], true)) {
				$sField = $aIdentity['class'] === 'PersonalToken' ? 'personal_token_id' : 'user_token_id';
				$sWhere = "$sField = :key";
				$iKey = $aIdentity['key'];
			} else {
				$iKey = (int)CMDBChange::GetCurrentUserId();
				$sWhere = 'user_id = :key AND personal_token_id = 0 AND user_token_id = 0';
			}
			if ($iKey <= 0) {
				return null;
			}

			$oSearch = DBObjectSearch::FromOQL_AllData(
				"SELECT AltiooEventMCPService WHERE $sWhere AND written_objects > 0 AND date >= :since"
			);
			$oSet = new DBObjectSet(
				$oSearch,
				['date' => true],
				['key' => $iKey, 'since' => date('Y-m-d H:i:s', time() - Settings::WRITE_CAP_WINDOW_SECONDS)]
			);
			$aRows = [];
			while ($oRow = $oSet->Fetch()) {
				$aRows[] = [(int)strtotime((string)$oRow->Get('date')), (int)$oRow->Get('written_objects')];
			}

			return $aRows;
		} catch (Throwable $e) {
			ErrorReport::Log('The write cap could not read the audit rows, so writes are refused ('.get_class($e).').');

			return null;
		}
	}

	/**
	 * Drops what the request reserved; a process serves one request, a test suite many.
	 *
	 * @internal
	 */
	public static function Forget(): void
	{
		self::$iReserved = 0;
	}
}
