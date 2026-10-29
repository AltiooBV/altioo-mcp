<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Core\Resources\Instructions as InstructionsResource;
use Altioo\iTop\Extension\MCP\Core\Tools\Instructions as InstructionsTool;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalDescribe;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResourceTemplate;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackTool;
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
 * console is served to them. Only this module's elements that enforce the
 * portal's rules themselves are, and these tests hold it there.
 */
class PortalModeTest extends TestCase
{
	protected function tearDown(): void
	{
		PortalMode::Forget();
	}

	/**
	 * @param array<int, string> $aDisabled
	 */
	private function isHidden(object $oElement, string $sIdentifier, array $aDisabled = [], ?AccessPolicy $oPolicy = null): bool
	{
		return (new ReflectionMethod(MCPService::class, 'isHidden'))
			->invoke(null, $sIdentifier, $oElement, $aDisabled, $oPolicy ?? AccessPolicy::Unrestricted());
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
	 * Every kind of element, under the widest policy there is: none written
	 * for the console is served in portal mode.
	 */
	public function testNoConsoleElementIsServedInPortalMode(): void
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
	 * The one tool written for the portal is served there, and only there: a
	 * console user has the schema tools, and an answer about a portal they
	 * are not in would describe someone else's rights.
	 */
	public function testThePortalDescriptionIsServedInPortalModeOnly(): void
	{
		$oDescribe = new PortalDescribe();

		$this->assertTrue($this->isHidden($oDescribe, 'core_portal_describe'), 'not to a console user');

		PortalMode::Remember('itop-portal');

		$this->assertFalse($this->isHidden($oDescribe, 'core_portal_describe'), 'to a portal user');
	}

	/**
	 * A `2026-07-28` client has no initialize, so the instructions tool is
	 * the only way it learns what portal mode does not serve. Both copies are
	 * served there, and describe the portal rather than the console's OQL.
	 */
	public function testTheInstructionsAreServedInPortalModeAndSaySo(): void
	{
		$oTool = new InstructionsTool();
		$oResource = new InstructionsResource();
		$sConsoleDescription = (string)$oTool->getDescription();

		PortalMode::Remember('itop-portal');

		$this->assertFalse($this->isHidden($oTool, 'core_instructions'));
		$this->assertFalse($this->isHidden($oResource, 'itop://core/instructions'));
		$this->assertStringContainsString('OQL', $sConsoleDescription);
		$this->assertStringNotContainsString('OQL', (string)$oTool->getDescription());
		$this->assertStringNotContainsString('OQL', (string)$oResource->getDescription());
		$this->assertStringContainsString('portal', (string)$oTool->getDescription());
	}

	/**
	 * A pack cannot declare itself portal-aware yet. PortalPolicy is not on
	 * the surface it may build on, so the declaration would be a console tool
	 * reaching the portal - the one thing portal mode exists to prevent.
	 */
	public function testAPackElementDeclaringItselfPortalAwareIsNotServed(): void
	{
		PortalMode::Remember('itop-portal');

		$this->assertFalse(PortalMode::Serves(new PortalAwarePackTool()));
		$this->assertTrue($this->isHidden(new PortalAwarePackTool(), 'test_portal_aware_pack_tool'));
		$this->assertTrue(PortalMode::Serves(new PortalDescribe()));
	}

	/**
	 * Portal mode widens nothing: what the operator turned off and what the
	 * token's scopes leave out stay off for a portal user too.
	 */
	public function testTheUsualFiltersStillNarrowWhatPortalModeServes(): void
	{
		PortalMode::Remember('itop-portal');
		$oDescribe = new PortalDescribe();

		$this->assertTrue($this->isHidden($oDescribe, 'core_portal_describe', ['core_portal_describe']), 'by identifier');
		$this->assertTrue($this->isHidden($oDescribe, 'core_portal_describe', [PortalDescribe::class]), 'by class');
		$this->assertTrue(
			$this->isHidden($oDescribe, 'core_portal_describe', [], AccessPolicy::Of([], ['objects'])),
			'by a toolset list that leaves its toolset out'
		);
		$this->assertTrue(
			$this->isHidden($oDescribe, 'core_portal_describe', [], AccessPolicy::Nothing()),
			'by a policy serving nothing'
		);
	}

	/**
	 * iTop stores a form field's read_only as "readonly" and reads it back
	 * as "read_only", so the portal shows the field writable. The operator
	 * wrote read-only, and that is the narrower reading.
	 */
	public function testAFormFieldWrittenReadOnlyIsReadOnly(): void
	{
		$aForm = PortalPolicy::WithReadOnlyHonoured([
			'type'   => 'custom_list',
			'fields' => [
				'title'       => ['readonly' => true],
				'description' => ['readonly' => false],
				'impact'      => [],
			],
		]);

		$this->assertTrue($aForm['fields']['title']['read_only']);
		$this->assertArrayNotHasKey('read_only', $aForm['fields']['description']);
		$this->assertArrayNotHasKey('read_only', $aForm['fields']['impact']);
	}

	/** A zlist form has no field options to honour, and is left as it is. */
	public function testAZlistFormIsLeftAlone(): void
	{
		$aForm = ['type' => 'zlist', 'fields' => 'details'];

		$this->assertSame($aForm, PortalPolicy::WithReadOnlyHonoured($aForm));
	}

	/**
	 * A brick on a class reaches its subclasses, and a class no brick reaches
	 * is not offered - whatever scope the user holds on it
	 * (doc/adr/0001-portal-mode-bricks-bound-discovery.md).
	 */
	public function testABrickReachesItsClassAndItsSubclassesOnly(): void
	{
		$aReach = [
			'Ticket'             => ['ongoing-tickets', 'all-tickets'],
			'ServiceSubcategory' => ['services'],
			'UserRequest'        => ['services'],
		];

		$this->assertSame(
			['ongoing-tickets', 'all-tickets', 'services'],
			PortalPolicy::BricksReaching($aReach, ['cmdbAbstractObject', 'Ticket', 'UserRequest'])
		);
		$this->assertSame(['ongoing-tickets', 'all-tickets'], PortalPolicy::BricksReaching($aReach, ['Ticket', 'Incident']));
		$this->assertSame([], PortalPolicy::BricksReaching($aReach, ['cmdbAbstractObject', 'Organization']));
	}

	/**
	 * iTop's own three data bricks are read, by name, so this holds without
	 * the portal module loaded; a class that is none of them, nor a subclass
	 * of one, reaches nothing (doc/adr/0002). A subclass read as its parent
	 * is held by the integration suite, where the parent exists.
	 */
	public function testTheStockBrickTypesAreReadAndNothingElse(): void
	{
		$this->assertSame(PortalPolicy::BRICK_MANAGE, PortalPolicy::BrickKind('Combodo\\iTop\\Portal\\Brick\\ManageBrick'));
		$this->assertSame(PortalPolicy::BRICK_BROWSE, PortalPolicy::BrickKind('\\Combodo\\iTop\\Portal\\Brick\\BrowseBrick'));
		$this->assertSame(PortalPolicy::BRICK_CREATE, PortalPolicy::BrickKind('Combodo\\iTop\\Portal\\Brick\\CreateBrick'));

		$this->assertNull(PortalPolicy::BrickKind('Acme\\Portal\\Brick\\MyTicketsBrick'), 'a class that extends none of them');
		$this->assertNull(PortalPolicy::BrickKind('Combodo\\iTop\\Portal\\Brick\\FilterBrick'), 'a brick that holds no data of its own');
		$this->assertNull(PortalPolicy::BrickKind('Combodo\\iTop\\Portal\\Brick\\UserProfileBrick'));
	}

	/**
	 * A child class inherits its parent's scope, and a portal keeps end users
	 * out of the agents' subclasses by pinning finalclass in it: the stock
	 * Ticket scope allows UserRequest and Incident, so a Change can never be
	 * in it. Such a class is hidden, as the portal hides it.
	 */
	public function testAFinalclassConditionThatExcludesEveryConcreteClassHidesIt(): void
	{
		$aPinned = ['UserRequest', 'Incident'];

		$this->assertTrue(PortalPolicy::CanHold($aPinned, [], ['UserRequest']));
		$this->assertFalse(PortalPolicy::CanHold($aPinned, [], ['Change']));
		$this->assertTrue(PortalPolicy::CanHold($aPinned, [], ['Change', 'Incident']), 'one concrete class the scope allows is enough');
		$this->assertFalse(PortalPolicy::CanHold(null, ['Change', 'Problem'], ['Change']), 'excluded by NOT IN');
		$this->assertTrue(PortalPolicy::CanHold(null, [], ['Change']), 'no condition, no conclusion');
		$this->assertFalse(PortalPolicy::CanHold(null, [], []), 'a class with no concrete class holds nothing');
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
		$this->assertStringContainsString('core_portal_describe', $sText);
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
