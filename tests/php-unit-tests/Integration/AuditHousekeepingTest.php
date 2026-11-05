<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\AuditHousekeeping;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AltiooEventMCPService;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The hourly audit clean-up, against a live datamodel.
 *
 * Retention deletes through DBDelete(), so both priv_event and the module's
 * table must lose the row, and only rows older than the setting. Every test
 * runs inside the case's transaction.
 */
class AuditHousekeepingTest extends ItopDataTestCaseAlias
{
	private function row(string $sDate, string $sUserinfo = 'housekeeping-test'): int
	{
		$oRow = new AltiooEventMCPService();
		$oRow->Set('date', $sDate);
		$oRow->Set('userinfo', $sUserinfo);
		$oRow->Set('message', 'housekeeping test');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'housekeeping-test');
		$oRow->Set('request_params', '{"secret":"x"}');

		return (int)$oRow->DBInsertNoReload();
	}

	private function exists(int $iId): bool
	{
		return MetaModel::GetObject('AltiooEventMCPService', $iId, false, true) !== null;
	}

	private function retention(mixed $mValue): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_AUDIT_RETENTION_DAYS, $mValue);
	}

	public function testRowsOlderThanTheSettingAreDeletedAndNewerOnesKept(): void
	{
		$iOld = $this->row(date('Y-m-d H:i:s', time() - 40 * 86400));
		$iRecent = $this->row(date('Y-m-d H:i:s', time() - 5 * 86400));
		$this->retention(30);

		(new AuditHousekeeping())->Process(time() + 60);

		$this->assertFalse($this->exists($iOld), 'a row past retention is deleted');
		$this->assertTrue($this->exists($iRecent), 'a row inside it is kept');
		// Event's own table lost it too: DBDelete() and not a DELETE on one table.
		$oSearch = new \DBObjectSearch('Event');
		$oSearch->AddCondition('id', $iOld, '=');
		$this->assertSame(0, (new \DBObjectSet($oSearch))->Count());
	}

	public function testZeroDeletesNothing(): void
	{
		$iOld = $this->row(date('Y-m-d H:i:s', time() - 4000 * 86400));
		$this->retention(0);

		(new AuditHousekeeping())->Process(time() + 60);

		$this->assertTrue($this->exists($iOld));
	}

	/**
	 * @dataProvider malformedProvider
	 */
	public function testAMalformedValueDeletesNothing(mixed $mValue): void
	{
		$iOld = $this->row(date('Y-m-d H:i:s', time() - 4000 * 86400));
		$this->retention($mValue);

		(new AuditHousekeeping())->Process(time() + 60);

		$this->assertTrue($this->exists($iOld), 'a typo must not become a purge');
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedProvider(): array
	{
		return ['negative' => [-5], 'a string' => ['30'], 'a word' => ['soon'], 'an array' => [[30]]];
	}

	public function testItStopsAtTheTimeCronAllows(): void
	{
		$iOld = $this->row(date('Y-m-d H:i:s', time() - 40 * 86400));
		$this->retention(30);

		(new AuditHousekeeping())->Process(time() - 1);

		$this->assertTrue($this->exists($iOld), 'with no time left it deletes nothing and resumes next hour');
	}

	public function testABacklogLargerThanABatchIsWorkedOff(): void
	{
		$this->retention(30);
		$aIds = [];
		for ($i = 0; $i < 450; $i++) {
			$aIds[] = $this->row(date('Y-m-d H:i:s', time() - 40 * 86400 - $i * 3600));
		}

		$sSummary = (new AuditHousekeeping())->Process(time() + 120);

		$this->assertStringStartsWith('450 audit rows deleted', $sSummary);
		$this->assertFalse($this->exists($aIds[0]));
		$this->assertFalse($this->exists($aIds[449]));
	}

	public function testWithoutTheAnonymizerThereIsNothingToFollow(): void
	{
		if (MetaModel::IsValidAttCode('Person', 'anonymized')) {
			$this->markTestSkipped('the Personal data anonymizer is installed here');
		}
		$iRow = $this->row(date('Y-m-d H:i:s'), 'someone@example.com');
		$this->retention(0);

		$sSummary = (new AuditHousekeeping())->Process(time() + 60);

		$this->assertStringContainsString('0 rows of anonymised people', $sSummary);
		$this->assertSame('someone@example.com', MetaModel::GetObject('AltiooEventMCPService', $iRow)->Get('userinfo'));
	}

	public function testItIsFoundByITopsOwnDiscovery(): void
	{
		$aProcesses = \Combodo\iTop\Service\InterfaceDiscovery\InterfaceDiscovery::GetInstance()->FindItopClasses(\iBackgroundProcess::class);

		$this->assertContains(AuditHousekeeping::class, $aProcesses, 'cron would never run it');
	}
}
