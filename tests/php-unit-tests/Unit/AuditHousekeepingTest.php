<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\AuditRetention;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The decisions behind the hourly audit clean-up, without iTop.
 *
 * What it does to rows runs against a database in AuditHousekeepingTest of the
 * integration suite; here are the parts that decide whether it may.
 */
class AuditHousekeepingTest extends TestCase
{
	public function testZeroKeepsEverythingAndPositiveIntegersAreDays(): void
	{
		$this->assertSame(0, MCPHelper::RetentionDaysFrom(0));
		$this->assertSame(30, MCPHelper::RetentionDaysFrom(30));
		$this->assertSame(3650, MCPHelper::RetentionDaysFrom(3650));
	}

	/**
	 * A purge cannot be undone, so a typo must not become one: anything that is
	 * not a non-negative integer reads as "keep".
	 *
	 * @dataProvider malformedProvider
	 */
	public function testAMalformedValueKeepsEverything(mixed $mValue): void
	{
		$this->assertSame(0, MCPHelper::RetentionDaysFrom($mValue));
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'negative'       => [-1],
			'a string'       => ['30'],
			'a word'         => ['forever'],
			'a float'        => [30.5],
			'a boolean'      => [true],
			'null'           => [null],
			'an array'       => [[30]],
		];
	}

	public function testTheCutoffIsThatManyDaysBack(): void
	{
		$iNow = (int)strtotime('2026-11-05 12:00:00 UTC');
		$sDefault = date_default_timezone_get();
		date_default_timezone_set('UTC');
		try {
			$this->assertSame('2026-10-06 12:00:00', AuditRetention::CutoffFor($iNow, 30));
			$this->assertSame('2026-11-05 12:00:00', AuditRetention::CutoffFor($iNow, 0));
		} finally {
			date_default_timezone_set($sDefault);
		}
	}

	public function testARowNeedsTheLoginOnlyWhenItDiffers(): void
	{
		$this->assertTrue(AuditRetention::NeedsLogin('jane.doe@example.com', 'Anonymous-42-4'));
		$this->assertFalse(AuditRetention::NeedsLogin('Anonymous-42-4', 'Anonymous-42-4'));
		$this->assertFalse(AuditRetention::NeedsLogin('jane.doe@example.com', ''), 'with no login to write, the row is left as it is');
	}

	/**
	 * The class needs iTop's iBackgroundProcess to load, so its shape is read
	 * from the source; AuditHousekeepingTest of the integration suite runs it,
	 * and tools/ci/checks/module-smoke.php asks iTop's own discovery for it.
	 */
	public function testItIsAnHourlyITopBackgroundProcess(): void
	{
		$sSource = (string)file_get_contents(dirname(__DIR__, 3).'/src/Service/AuditHousekeeping.php');

		$this->assertMatchesRegularExpression('/final class AuditHousekeeping implements iBackgroundProcess/', $sSource);
		$this->assertMatchesRegularExpression('/function GetPeriodicity\(\)\s*\{\s*return 3600;/', $sSource);
		$this->assertStringContainsString('AllowAllData()', $sSource, 'cron runs as nobody; the search must not depend on a user');
	}

	/**
	 * Free text a caller wrote or was told can name the person: the refusal a
	 * tool gave quotes what it refused. Anonymising the person clears all of
	 * it, and the housekeeping clears whatever this list says.
	 */
	public function testAnonymisingClearsEveryFreeTextColumn(): void
	{
		$this->assertEqualsCanonicalizing(['request_params', 'change_comment', 'refusal_text'], AuditRetention::CLEARED_ON_ANONYMISATION);

		$sSource = (string)file_get_contents(dirname(__DIR__, 3).'/src/Service/AuditHousekeeping.php');
		$this->assertStringContainsString('foreach (AuditRetention::CLEARED_ON_ANONYMISATION as $sColumn)', $sSource);
	}

	public function testEveryClearedColumnIsOneTheClassHas(): void
	{
		$sXml = (string)file_get_contents(dirname(__DIR__, 3).'/datamodel.altioo-mcp.xml');
		foreach (AuditRetention::CLEARED_ON_ANONYMISATION as $sColumn) {
			$this->assertMatchesRegularExpression('#<field id="'.$sColumn.'" xsi:type="Attribute(Text|String)">#', $sXml, $sColumn);
		}
	}

	public function testRetentionIsOffByDefaultInTheDatamodelAndInTheCode(): void
	{
		$sXml = (string)file_get_contents(dirname(__DIR__, 3).'/datamodel.altioo-mcp.xml');

		$this->assertMatchesRegularExpression('#<mcp_audit_retention_days type="integer">0</mcp_audit_retention_days>#', $sXml);
		$this->assertSame('mcp_audit_retention_days', MCPHelper::MODULE_SETTING_AUDIT_RETENTION_DAYS);
	}
}
