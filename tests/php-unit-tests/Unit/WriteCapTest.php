<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\SettingParser;
use Altioo\iTop\Extension\MCP\Service\Audit\ChangeSummary;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\WriteCap;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * The opt-in write cap (ADR 0019), without iTop: what it decides given a count, what a malformed
 * setting means, and that every writing tool asks it in its own source.
 */
class WriteCapTest extends TestCase
{
	private const NOW = 1_800_000_000;

	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	public function testOffNeverRefuses(): void
	{
		$this->assertNull(WriteCap::Decide(0, false, null, 0, 1000, self::NOW));
	}

	public function testBelowTheCapIsAllowed(): void
	{
		$aRows = [[self::NOW - 600, 1]];
		$this->assertNull(WriteCap::Decide(2, true, $aRows, 0, 1, self::NOW));
	}

	public function testTheThirdWriteWithACapOfTwoIsRefusedAndSaysWhenItFrees(): void
	{
		$aRows = [[self::NOW - 600, 1], [self::NOW - 300, 1]];
		$sRefusal = WriteCap::Decide(2, true, $aRows, 0, 1, self::NOW);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('mcp_max_writes_per_hour', $sRefusal);
		$this->assertStringContainsString('cap of 2', $sRefusal);
		// The oldest row leaves the window 3600 s after it was made: 3000 s from now.
		$this->assertStringContainsString('in about 50 minutes', $sRefusal);
		$this->assertStringNotContainsString("\n", $sRefusal);
	}

	public function testABulkCallIsRefusedWholeNeverPartly(): void
	{
		$aRows = [[self::NOW - 100, 3]];
		$sRefusal = WriteCap::Decide(5, true, $aRows, 0, 3, self::NOW);

		$this->assertNotNull($sRefusal, '3 used + 3 asked is over 5: nothing of the call is written');
		$this->assertNull(WriteCap::Decide(5, true, $aRows, 0, 2, self::NOW), 'exactly the cap is allowed');
	}

	public function testACallLargerThanTheCapCanNeverFitAndSaysSo(): void
	{
		$sRefusal = WriteCap::Decide(10, true, [], 0, 11, self::NOW);

		$this->assertStringContainsString('11 objects', (string)$sRefusal);
		$this->assertStringNotContainsString('frees up', (string)$sRefusal);
	}

	public function testWhatThisRequestAlreadyReservedCounts(): void
	{
		$this->assertNotNull(WriteCap::Decide(2, true, [], 2, 1, self::NOW));
	}

	public function testTheRefusalNamesNoOneElsesCount(): void
	{
		$sRefusal = (string)WriteCap::Decide(2, true, [[self::NOW - 10, 2]], 0, 1, self::NOW);

		$this->assertStringNotContainsString('token', strtolower($sRefusal));
		$this->assertStringNotContainsString('user', strtolower($sRefusal));
	}

	/** @dataProvider malformedProvider */
	public function testAMalformedSettingIsNotReadAsOff(mixed $mValue): void
	{
		$this->assertNull(SettingParser::MaxWritesPerHourFrom($mValue));
		$this->assertStringContainsString('not a non-negative integer', (string)WriteCap::Decide(null, true, [], 0, 1, self::NOW));
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'string'   => ['10'],
			'negative' => [-1],
			'float'    => [2.5],
			'bool'     => [true],
			'array'    => [[5]],
			'null'     => [null],
		];
	}

	public function testIntegersParse(): void
	{
		$this->assertSame(0, SettingParser::MaxWritesPerHourFrom(0));
		$this->assertSame(50, SettingParser::MaxWritesPerHourFrom(50));
	}

	public function testAnAuditTrailThatCannotBeReadFailsClosed(): void
	{
		$this->assertStringContainsString('cannot count writes', (string)WriteCap::Decide(5, false, [], 0, 1, self::NOW));
		$this->assertStringContainsString('cannot count writes', (string)WriteCap::Decide(5, true, null, 0, 1, self::NOW));
	}

	public function testAChangeCountsItsDistinctObjectsNotItsAttributes(): void
	{
		$this->assertSame(2, ChangeSummary::CountObjects([['Person', '1'], ['Person', '1'], ['Person', '2']]));
		$this->assertSame(0, ChangeSummary::CountObjects([]));
	}

	/**
	 * Every tool that writes asks the cap in its own source (ADR 0014), discovered from the
	 * registry so a write tool added later is held to it the day it is registered. The one
	 * exemption is named: a display preference of the caller's own, which makes no change and
	 * touches no object.
	 */
	public function testEveryWritingToolAsksTheCap(): void
	{
		$iChecked = 0;
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aHints = $oTool->getAnnotations()->jsonSerialize();
			$sCapability = AccessPolicy::CapabilityOf(
				array_key_exists('readOnlyHint', $aHints) ? (bool)$aHints['readOnlyHint'] : null,
				array_key_exists('destructiveHint', $aHints) ? (bool)$aHints['destructiveHint'] : null
			);
			if ($sCapability === AccessPolicy::CAPABILITY_READ || $sName === 'core_set_obsolete_data') {
				continue;
			}
			$iChecked++;
			$oSource = SourceCalls::OfClass($oTool);
			$this->assertTrue(
				$oSource->HasCall(WriteCap::class.'::RefusalFor'),
				"{$sName} writes and never asks WriteCap::RefusalFor"
			);
			$this->assertTrue(
				$oSource->CallsInOrder([WriteCap::class.'::RefusalFor', ChangeTracking::class.'::Explain']),
				"{$sName} asks the cap after it has begun the change"
			);
		}
		$this->assertGreaterThanOrEqual(7, $iChecked);
	}

	public function testTheExemptToolIsStillAWritingToolWeNamedOnPurpose(): void
	{
		$this->assertArrayHasKey('core_set_obsolete_data', MCPRegistry::GetTools());
	}
}
