<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Hook;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Service\Audit\AuditRetention;
use AltiooEventMCPService;
use DBObjectSearch;
use DBObjectSet;
use iBackgroundProcess;
use MetaModel;
use Throwable;

/**
 * Hourly, from iTop's cron: deletes audit rows past `mcp_audit_retention_days`,
 * and keeps the rows of an anonymised person in step with iTop's history.
 *
 * **Retention.** iTop has no retention of its own for its event log, and the
 * README used to tell operators to set it "as for iTop's other event classes",
 * which are not purged either. 0, the default, keeps every row. A positive
 * number deletes rows older than that many days, oldest first, through
 * `DBDelete()` so that both `priv_event` and the module's table lose them. It
 * stops at the time cron allows and resumes the next hour, so a backlog is
 * worked off over several runs.
 *
 * **Anonymisation** (doc/adr/0009 and the README). iTop keeps a change's
 * `userinfo` when a user is deleted, and Combodo's anonymizer rewrites it by
 * `user_id` when it anonymises a person. Neither touches this module's rows,
 * so this does what the anonymizer does for a change: a row whose `user_id`
 * leads to an anonymised person and whose `userinfo` is not the account's
 * current login gets the login, and loses `request_params`,
 * `change_comment` and `refusal_text`, which are free text and may name people. `changed_objects`
 * and `read_objects` are left: `Class::id` is what iTop keeps on other objects'
 * history too. Runs whether retention is on or not, and a row is found again
 * only while its `userinfo` differs, so a row is done once. Lag is up to one
 * run.
 *
 * Not done, on purpose, and said in the README: rows of a user deleted before
 * their person was anonymised (the key was reset), and rows from before
 * `user_id` existed. Both keep the login until retention removes them.
 *
 * @since 1.3.0
 */
final class AuditHousekeeping implements iBackgroundProcess
{
	/** Rows fetched per query, so that a large backlog is never held in memory. */
	private const BATCH = 200;

	public function GetPeriodicity()
	{
		return 3600;
	}

	/**
	 * @param int $iUnixTimeLimit When cron wants this run to stop.
	 */
	public function Process($iUnixTimeLimit)
	{
		MCPLog::EnableForModule();

		$iLimit = (int)$iUnixTimeLimit;
		$iDays = Settings::GetAuditRetentionDays();
		$iDeleted = $iDays > 0 ? $this->Purge(AuditRetention::CutoffFor(time(), $iDays), $iLimit) : 0;
		$iReconciled = $this->Reconcile($iLimit);

		$sSummary = sprintf('%d audit rows deleted (retention %s), %d rows of anonymised people brought in step', $iDeleted, $iDays > 0 ? $iDays.' days' : 'off', $iReconciled);
		// Only when it did something: an hourly line saying nothing happened is noise.
		if ($iDeleted > 0 || $iReconciled > 0) {
			MCPLog::Ok($sSummary);
		}

		return $sSummary;
	}

	private function Purge(string $sBefore, int $iUnixTimeLimit): int
	{
		$iCount = 0;
		while (time() < $iUnixTimeLimit) {
			$oSearch = DBObjectSearch::FromOQL('SELECT AltiooEventMCPService WHERE date < :before');
			$oSearch->AllowAllData();
			$oSet = new DBObjectSet($oSearch, ['date' => true], ['before' => $sBefore]);
			$oSet->SetLimit(self::BATCH);
			$iInBatch = 0;
			try {
				while (time() < $iUnixTimeLimit && ($oRow = $oSet->Fetch()) instanceof AltiooEventMCPService) {
					$oRow->DBDelete();
					$iInBatch++;
				}
			} catch (Throwable $e) {
				MCPHelper::LogError('The audit retention could not delete a row: '.$e->getMessage());

				return $iCount + $iInBatch;
			}
			$iCount += $iInBatch;
			if ($iInBatch < self::BATCH) {
				break;
			}
		}

		return $iCount;
	}

	private function Reconcile(int $iUnixTimeLimit): int
	{
		// The attribute is the Personal data anonymizer's. Without it there is
		// no such thing as an anonymised person to follow.
		if (!MetaModel::IsValidClass('Person') || !MetaModel::IsValidAttCode('Person', 'anonymized')) {
			return 0;
		}

		$iCount = 0;
		while (time() < $iUnixTimeLimit) {
			$oSearch = DBObjectSearch::FromOQL(
				'SELECT e, u FROM AltiooEventMCPService AS e JOIN User AS u ON e.user_id = u.id JOIN Person AS p ON u.contactid = p.id '
				.'WHERE p.anonymized = 1 AND e.userinfo != u.login'
			);
			$oSearch->AllowAllData();
			$oSet = new DBObjectSet($oSearch);
			$oSet->SetLimit(self::BATCH);
			$iInBatch = 0;
			try {
				while (time() < $iUnixTimeLimit && ($aRow = $oSet->FetchAssoc())) {
					$oRow = $aRow['e'];
					$sLogin = (string)$aRow['u']->Get('login');
					if (!AuditRetention::NeedsLogin((string)$oRow->Get('userinfo'), $sLogin)) {
						continue;
					}
					$oRow->Set('userinfo', $sLogin);
					foreach (AuditRetention::CLEARED_ON_ANONYMISATION as $sColumn) {
						$oRow->Set($sColumn, '');
					}
					$oRow->DBUpdate();
					$iInBatch++;
				}
			} catch (Throwable $e) {
				MCPHelper::LogError('The audit clean-up for anonymised people could not update a row: '.$e->getMessage());

				return $iCount + $iInBatch;
			}
			$iCount += $iInBatch;
			if ($iInBatch < self::BATCH) {
				break;
			}
		}

		return $iCount;
	}
}
