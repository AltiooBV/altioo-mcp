<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Resources\CurrentUser as CurrentUserResource;
use Altioo\iTop\Extension\MCP\Core\Tools\CurrentUser as CurrentUserTool;
use Altioo\iTop\Extension\MCP\Service\Read\InstanceTime;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/** Review 2026-11-23, C10: core_current_user names the instance's time zone and the time now, with its offset. */
class InstanceTimeTest extends TestCase
{
	private string $sZone;

	protected function setUp(): void
	{
		$this->sZone = date_default_timezone_get();
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->sZone);
	}

	public function testItNamesTheZoneIItopSetsAndTheInstantWithItsOffset(): void
	{
		date_default_timezone_set('Europe/Brussels');
		$a = InstanceTime::Now(strtotime('2026-11-25T09:20:00Z'));

		$this->assertSame('Europe/Brussels', $a['timezone']);
		$this->assertSame('2026-11-25T10:20:00+01:00', $a['now'], 'winter: +01:00');

		$this->assertSame('2026-07-25T11:20:00+02:00', InstanceTime::Now(strtotime('2026-07-25T09:20:00Z'))['now'], 'summer: +02:00');
	}

	public function testAUtcInstanceSaysSo(): void
	{
		date_default_timezone_set('UTC');

		$this->assertSame(['timezone' => 'UTC', 'now' => '2026-11-25T09:20:00+00:00'], InstanceTime::Now(strtotime('2026-11-25T09:20:00Z')));
	}

	public function testTheNowRoundTripsThroughTheFilterThatConvertsAnOffset(): void
	{
		date_default_timezone_set('Europe/Brussels');
		$iThen = strtotime('2026-11-25T09:20:00Z');

		$this->assertSame($iThen, strtotime(InstanceTime::Now($iThen)['now']), 'what it says is the instant it is');
	}

	public function testBothSurfacesSayItInTheSameWordsAndTheReaderAnswersIt(): void
	{
		$this->assertStringContainsString('time zone and the time now', CurrentUserTool::RETURNS);
		$this->assertStringContainsString('time zone and the time now', CurrentUserTool::PORTAL_RETURNS);
		$this->assertStringContainsString(CurrentUserTool::RETURNS, (string)(new CurrentUserResource())->getDescription());

		$sSource = (string)file_get_contents(dirname(__DIR__, 3).'/src/Service/Read/CurrentUserReader.php');
		$this->assertStringContainsString('...InstanceTime::Now()', $sSource, 'the one payload both surfaces use');
	}
}
