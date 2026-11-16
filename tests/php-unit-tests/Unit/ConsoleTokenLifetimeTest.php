<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\Gate\ConsoleTokenLifetimeGate;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\SettingNames;
use Altioo\iTop\Extension\MCP\Helper\SettingParser;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Altioo\iTop\Extension\MCP\Test\Support\RequestPipelineSource;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * The console token bound (ADR 0020): off by default, the portal bound's arithmetic above 0,
 * and a malformed value refusing rather than reading as "no bound".
 */
class ConsoleTokenLifetimeTest extends TestCase
{
	public function testItIsNamedLikeThePortalOneAndOffByDefault(): void
	{
		$this->assertSame('mcp_token_max_days', SettingNames::MODULE_SETTING_TOKEN_MAX_DAYS);
		$this->assertSame('mcp_portal_token_max_days', SettingNames::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS);
		$this->assertSame(0, SettingNames::DEFAULT_TOKEN_MAX_DAYS);
	}

	public function testIntegersParse(): void
	{
		$this->assertSame(0, SettingParser::TokenMaxDaysFrom(0));
		$this->assertSame(90, SettingParser::TokenMaxDaysFrom(90));
	}

	/** @dataProvider malformedProvider */
	public function testAMalformedSettingIsNotReadAsOff(mixed $mValue): void
	{
		$this->assertNull(SettingParser::TokenMaxDaysFrom($mValue));
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'string'   => ['90'],
			'negative' => [-1],
			'float'    => [2.5],
			'bool'     => [true],
			'array'    => [[5]],
			'null'     => [null],
		];
	}

	/** The same arithmetic as the portal's, since the gate calls the same function. */
	public function testTheBoundIsTheSameCalculation(): void
	{
		$iNow = (int)strtotime('2026-11-16 12:00:00');

		$this->assertTrue(PortalMode::TokenIsBounded('2027-02-14 12:00:00', $iNow, 90), 'exactly 90 days');
		$this->assertFalse(PortalMode::TokenIsBounded('2027-02-14 12:00:01', $iNow, 90), 'one second over');
		$this->assertFalse(PortalMode::TokenIsBounded(null, $iNow, 90), 'never expires, or an application token');
	}

	public function testTheGateKeepsItsDecisionInItsOwnSource(): void
	{
		$oGate = SourceCalls::OfClass(ConsoleTokenLifetimeGate::class);

		$this->assertTrue($oGate->HasCall(Settings::class.'::GetTokenMaxDays', 'Check'));
		$this->assertTrue($oGate->HasCall(PortalMode::class.'::TokenIsBounded', 'Check'));
		$this->assertTrue($oGate->HasCall(PortalMode::class.'::Current', 'Check'), 'a portal request is held to its own bound');
		$this->assertTrue($oGate->HasCall(TokenScopes::class.'::LoginWasAToken', 'Check'), 'a login that was not a token is not this gate\'s');
	}

	public function testTheGateRunsWhileTheTokenIsStillAtHand(): void
	{
		$aMarkers = ['[[ConsoleTokenLifetimeGate]]', RequestPipelineSource::Spec('ForgetAuthToken')];
		$this->assertTrue(RequestPipelineSource::Has($aMarkers[0]), 'the console token bound is no longer a gate');
		$this->assertTrue(RequestPipelineSource::InOrder($aMarkers), 'the bound is asked after the token is dropped, when its expiry can no longer be read');
		$this->assertTrue(RequestPipelineSource::InOrder([
			'[[LoginGate]]',
			$aMarkers[0],
		]), 'the bound is asked before the login, when there is no token to read');
	}
}
