<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResourceTemplate;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Portal mode: who is let in, through which portal, and what they are served.
 *
 * "Portal user" reads every bizmodel class through UserRights, so the whole
 * of what keeps a portal user to their portal is that nothing written for the
 * console is served to them. Until an element enforces the portal's rules
 * itself, portal mode serves nothing, and these tests hold it there.
 */
class PortalModeTest extends TestCase
{
	protected function tearDown(): void
	{
		PortalMode::Forget();
	}

	private function isHidden(object $oElement, string $sIdentifier): bool
	{
		return (new ReflectionMethod(MCPService::class, 'isHidden'))
			->invoke(null, $sIdentifier, $oElement, [], AccessPolicy::Unrestricted());
	}

	/**
	 * iTop lists the portals a user may enter in rank order, and its own
	 * login sends them to the first. The order mcp_portals is written in is
	 * not a preference, so it must not decide.
	 */
	public function testTheFirstAllowedPortalByRankIsChosen(): void
	{
		$this->assertSame(
			'itop-portal',
			PortalMode::Choose(['itop-portal', 'partner-portal'], ['partner-portal', 'itop-portal'])
		);
	}

	public function testAPortalTheUserIsNotAllowedIntoIsNeverChosen(): void
	{
		$this->assertSame('partner-portal', PortalMode::Choose(['partner-portal'], ['itop-portal', 'partner-portal']));
		$this->assertNull(PortalMode::Choose(['itop-portal'], ['partner-portal']));
	}

	/** Empty mcp_portals is portal mode off, whatever the user may enter. */
	public function testNothingIsChosenWhenNoPortalIsServed(): void
	{
		$this->assertNull(PortalMode::Choose(['itop-portal'], []));
	}

	/** Ids are compared exactly: a portal id is a module code, not a label. */
	public function testAPortalIdIsMatchedExactly(): void
	{
		$this->assertNull(PortalMode::Choose(['itop-portal'], ['ITOP-PORTAL', 'itop-portal ']));
	}

	public function testEveryConditionHeldServesTheRequest(): void
	{
		$this->assertNull(PortalMode::RefusalOf('itop-portal', true, true, true));
	}

	/**
	 * @dataProvider refusalProvider
	 */
	public function testEachConditionIsRequired(?string $sPortal, bool $bToken, bool $bContact, bool $bProfile, string $sRefusal): void
	{
		$this->assertSame($sRefusal, PortalMode::RefusalOf($sPortal, $bToken, $bContact, $bProfile));
	}

	/**
	 * @return array<string, array{0: string|null, 1: bool, 2: bool, 3: bool, 4: string}>
	 */
	public function refusalProvider(): array
	{
		return [
			'no portal'        => [null, true, true, true, PortalMode::REFUSED_NO_SERVED_PORTAL],
			'not a token'      => ['itop-portal', false, true, true, PortalMode::REFUSED_NOT_A_TOKEN],
			'no contact'       => ['itop-portal', true, false, true, PortalMode::REFUSED_NO_CONTACT],
			'no MCP profile'   => ['itop-portal', true, true, false, PortalMode::REFUSED_NO_MCP_PROFILE],
			// The portal comes first: a user in no served portal is answered
			// as before portal mode existed, not told what else it lacks.
			'nothing held'     => [null, false, false, false, PortalMode::REFUSED_NO_SERVED_PORTAL],
		];
	}

	/**
	 * Every kind of element, under the widest policy there is: nothing is
	 * served in portal mode, because nothing yet enforces the portal's rules.
	 */
	public function testNothingIsServedInPortalMode(): void
	{
		$aElements = [
			'test_fixture_tool'            => new FixtureTool(),
			'test://fixture'               => new FixtureResource(),
			'test://fixture/{id}'          => new FixtureResourceTemplate(),
			'test_fixture_prompt'          => new FixturePrompt(),
		];

		foreach ($aElements as $sIdentifier => $oElement) {
			$this->assertFalse($this->isHidden($oElement, $sIdentifier), get_class($oElement).' should be served to a console user');
		}

		PortalMode::Remember('itop-portal');

		foreach ($aElements as $sIdentifier => $oElement) {
			$this->assertTrue($this->isHidden($oElement, $sIdentifier), get_class($oElement).' must not be served in portal mode');
		}
	}

	/**
	 * The console's instructions describe the console's tools, and a pack's
	 * describe its own. Neither is served here.
	 */
	public function testThePortalIsToldWhatItIsServed(): void
	{
		PortalMode::Remember('partner-portal');

		$sText = MCPService::InstructionsFor(AccessPolicy::Unrestricted());

		$this->assertSame(ServerInstructions::PortalText('partner-portal'), $sText);
		$this->assertStringContainsString('"partner-portal"', $sText);
		$this->assertStringNotContainsString('OQL', $sText);
	}

	/**
	 * Portal mode starts from the console refusal and nowhere else, so a
	 * console login cannot reach it; and it is decided before the policy is,
	 * and before anything is served.
	 */
	public function testPortalModeIsDecidedFromTheConsoleRefusalOnly(): void
	{
		$oMethod = new ReflectionMethod(MCPController::class, 'handleRequest');
		$aLines = file($oMethod->getFileName());
		$sBody = implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));

		$this->assertMatchesRegularExpression(
			'/if \(\$iRet === LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED\) \{\s*\$iRet = self::admitInPortalMode\(\);\s*\}/',
			$sBody,
			'admitInPortalMode() must run only on EXIT_CODE_PORTALUSERNOTAUTHORIZED'
		);
		$this->assertSame(1, substr_count($sBody, 'admitInPortalMode'), 'admitInPortalMode() is called from one place');

		$iLogin = strpos($sBody, 'DoLogin(');
		$iAdmit = strpos($sBody, 'admitInPortalMode');
		$iPolicy = strpos($sBody, 'AccessPolicyOfCurrentRequest');
		$this->assertLessThan($iAdmit, $iLogin);
		$this->assertLessThan($iPolicy, $iAdmit);
	}
}
