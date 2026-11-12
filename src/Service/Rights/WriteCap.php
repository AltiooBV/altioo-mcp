<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use CMDBChange;
use DBObjectSearch;
use DBObjectSet;
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
	 * @since 2.0.0
	 */
	public static function RefusalFor(int $iObjects): ?string
	{
		$iCap = Settings::GetMaxWritesPerHour();
		if ($iCap === 0) {
			return null;
		}

		$bTrailCounts = Settings::AuditCountsWrites();
		$aRows = ($iCap !== null && $bTrailCounts) ? self::RowsInWindow() : [];

		$sRefusal = self::Decide($iCap, $bTrailCounts, $aRows, self::$iReserved, $iObjects, time());
		if ($sRefusal === null) {
			self::$iReserved += max(0, $iObjects);
		} else {
			ErrorReport::Log('A write was refused by the write cap: '.$sRefusal);
		}

		return $sRefusal;
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
	 *
	 * @internal
	 */
	public static function Decide(?int $iCap, bool $bTrailCounts, ?array $aRows, int $iReserved, int $iAsked, int $iNow): ?string
	{
		if ($iCap === 0) {
			return null;
		}
		$sName = Settings::MODULE_SETTING_MAX_WRITES_PER_HOUR;
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
