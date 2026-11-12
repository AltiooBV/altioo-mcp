<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\McpEvals\Expectations;
use Altioo\McpEvals\McpClient;
use Altioo\McpEvals\Scenario;
use PHPUnit\Framework\TestCase;

/**
 * The model-run harness's parts that need no model and no network
 * (tools/evals, doc/adr/0008): the scenario files parse, and the expectations
 * pass and fail on calls given by hand.
 *
 * tools/ is not in the release archive, so on an installed copy this skips.
 */
class EvalScenariosTest extends TestCase
{
	private static string $sDir;

	public static function setUpBeforeClass(): void
	{
		self::$sDir = dirname(__DIR__, 3).'/tools/evals';
		if (!is_dir(self::$sDir.'/src')) {
			self::markTestSkipped('tools/evals is not part of the release archive.');
		}
		foreach (['Scenario', 'Expectations', 'McpClient'] as $sClass) {
			require_once self::$sDir.'/src/'.$sClass.'.php';
		}
	}

	/** @return array<int, Scenario> */
	private function scenarios(): array
	{
		$aFiles = glob(self::$sDir.'/scenarios/*.json') ?: [];
		$this->assertNotSame([], $aFiles, 'no scenario to check');

		return array_map(static fn (string $s): Scenario => Scenario::FromFile($s), $aFiles);
	}

	public function testEveryCommittedScenarioParses(): void
	{
		$this->assertGreaterThanOrEqual(3, count($this->scenarios()));
	}

	/** A scenario that names a tool the server does not serve could never be satisfied. */
	public function testScenariosNameToolsThatExist(): void
	{
		$aServed = [];
		foreach (glob(dirname(__DIR__).'/Integration/Snapshots/surface-*.json') ?: [] as $sSurface) {
			$aServed = array_merge($aServed, array_column(json_decode((string)file_get_contents($sSurface), true)['surface']['tools'], 'name'));
		}
		$this->assertNotSame([], $aServed);

		foreach ($this->scenarios() as $oScenario) {
			$aNamed = array_merge(
				$oScenario->aData['allowedTools'] ?? [],
				$oScenario->aData['firstCallIn'] ?? [],
				array_column($oScenario->aData['mustCall'] ?? [], 'tool'),
				array_column($oScenario->aData['forbid'] ?? [], 'tool'),
			);
			foreach ($aNamed as $sTool) {
				$this->assertContains($sTool, $aServed, "{$oScenario->id()} names {$sTool}, which no surface serves");
			}
		}
	}

	public function testAMalformedScenarioIsRefusedWithEveryReason(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/prompt.*token.*maxCalls/s');
		Scenario::FromArray(['description' => 'x', 'prerequisites' => 'x'], 'broken');
	}

	private function scenario(array $aExtra): Scenario
	{
		return Scenario::FromArray($aExtra + [
			'description' => 'x', 'prerequisites' => 'x', 'prompt' => 'x', 'token' => 'write', 'maxCalls' => 4,
		], 'case');
	}

	private function call(string $sTool, array $aArguments = []): array
	{
		return ['tool' => $sTool, 'arguments' => $aArguments];
	}

	public function testACommitWithoutItsDryRunFails(): void
	{
		$oScenario = $this->scenario(['before' => [[
			'commit' => ['tool' => 'u', 'arguments' => ['simulate' => false]],
			'after'  => ['tool' => 'u', 'arguments' => ['simulate' => true]],
		]]]);

		$this->assertSame([], Expectations::Check($oScenario, [$this->call('u', ['simulate' => true]), $this->call('u', ['simulate' => false])]));
		$this->assertCount(1, Expectations::Check($oScenario, [$this->call('u', ['simulate' => false])]));
		$this->assertCount(1, Expectations::Check($oScenario, [$this->call('u', ['simulate' => false]), $this->call('u', ['simulate' => true])]), 'a dry run after the commit does not count');
	}

	public function testBudgetAllowedSetForbiddenAndMissingCallsAreEachReported(): void
	{
		$oScenario = $this->scenario([
			'maxCalls'     => 2,
			'allowedTools' => ['a', 'b'],
			'mustCall'     => [['tool' => 'b']],
			'forbid'       => [['tool' => 'a', 'arguments' => ['x' => 1]]],
			'firstCallIn'  => ['b'],
		]);

		$this->assertSame([], Expectations::Check($oScenario, [$this->call('b'), $this->call('a', ['x' => 2])]));

		$aFailures = implode("\n", Expectations::Check($oScenario, [$this->call('a', ['x' => 1]), $this->call('c'), $this->call('a')]));
		foreach (['step budget', 'allowed set', 'forbidden call #1', 'never called', 'first call'] as $sNeedle) {
			$this->assertStringContainsString($sNeedle, $aFailures);
		}
	}

	public function testAnEventStreamAnswerIsDecoded(): void
	{
		$this->assertSame(['id' => 1], McpClient::DecodeBody("event: message\ndata: {\"id\":1}\n\n"));
		$this->assertSame(['id' => 2], McpClient::DecodeBody('{"id":2}'));
	}
}
