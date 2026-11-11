<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Core\Resources\CurrentUser as CurrentUserResource;
use Altioo\iTop\Extension\MCP\Core\Resources\Instructions as InstructionsResource;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\CurrentUser as CurrentUserTool;
use Altioo\iTop\Extension\MCP\Core\Tools\Instructions as InstructionsTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet as ObjectGetTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory as ObjectHistoryTool;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
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

	/**
	 * A connection may name its portal (doc/adr/0007): served when the user
	 * may enter it and mcp_portals serves it, and otherwise none - never the
	 * first by rank in its place.
	 */
	public function testANamedPortalIsServedOnlyWhenAllowedAndServed(): void
	{
		$aAllowed = ['itop-portal', 'partner-portal'];

		$this->assertSame('partner-portal', PortalMode::Choose($aAllowed, ['*'], 'partner-portal'), 'the second by rank, named');
		$this->assertNull(PortalMode::Choose($aAllowed, ['itop-portal'], 'partner-portal'), 'not served: refused, not replaced');
		$this->assertNull(PortalMode::Choose($aAllowed, ['*'], 'other-portal'), 'not allowed: refused, not replaced');
		$this->assertNull(PortalMode::Choose(['backoffice', 'itop-portal'], ['*'], 'backoffice'), 'the console is never a portal');
		$this->assertNull(PortalMode::Choose($aAllowed, ['*'], ''), 'a malformed id matches nothing');
		$this->assertSame('itop-portal', PortalMode::Choose($aAllowed, ['*'], null), 'unnamed: the first by rank, as before');
	}

	/**
	 * The token's audience is checked while the token is still at hand, and
	 * after the login is settled; the portal served is on every audit row.
	 */
	public function testATokenIsHeldToItsUrlAndTheAuditNamesThePortal(): void
	{
		$sHandle = self::bodyOf(MCPController::class, 'handleRequest');
		$iAccounted = strpos($sHandle, 'self::rejectATokenLoginNotAccountedFor()');
		$iAudience = strpos($sHandle, 'self::rejectATokenGrantedForAnotherUrl()');
		$iForget = strpos($sHandle, 'MCPHttp::ForgetAuthToken()');
		$this->assertNotFalse($iAudience, 'a token granted for another portal\'s URL is accepted');
		$this->assertLessThan($iAudience, $iAccounted);
		$this->assertLessThan($iForget, $iAudience, 'the audience is asked after the token is dropped');

		$this->assertStringContainsString('MCPHelper::CalledEndpointUrl()', self::bodyOf(MCPController::class, 'rejectATokenGrantedForAnotherUrl'));
		$this->assertStringContainsString("\$oLog->Set('portal', PortalMode::Current() ?? '')", self::bodyOf(MCPController::class, 'logIfConfigured'));
		$this->assertStringContainsString('MCPHelper::RequestedPortal()', self::bodyOf(MCPController::class, 'admitInPortalMode'));
	}

	/**
	 * "*", the default, serves every portal: who gets in is decided by the
	 * profiles they hold. Still the first by rank, and never the console.
	 */
	public function testEveryPortalIsServedByDefaultInRankOrder(): void
	{
		$this->assertSame('partner-portal', PortalMode::Choose(['partner-portal', 'itop-portal'], [PortalMode::ANY_PORTAL]));
		$this->assertSame('itop-portal', PortalMode::Choose(['backoffice', 'itop-portal'], [PortalMode::ANY_PORTAL]));
		$this->assertNull(PortalMode::Choose([], [PortalMode::ANY_PORTAL]));
	}

	public function testEveryConditionHeldServesTheRequest(): void
	{
		$this->assertNull(PortalMode::RefusalOf('itop-portal', true, true, true, true));
	}

	/**
	 * A portal user can mint their own tokens, with any expiry, once their
	 * profile is in personal_tokens_allowed_profiles. What portal mode accepts
	 * is a token that ends - within the bound, counted from now.
	 */
	public function testOnlyATokenThatEndsWithinTheBoundIsAccepted(): void
	{
		$iNow = (int)strtotime('2026-10-29 12:00:00');

		$this->assertTrue(PortalMode::TokenIsBounded('2026-11-28 12:00:00', $iNow, 90));
		$this->assertTrue(PortalMode::TokenIsBounded('2027-01-27 12:00:00', $iNow, 90), 'exactly 90 days');
		$this->assertFalse(PortalMode::TokenIsBounded('2027-01-27 12:00:01', $iNow, 90), 'one second over');
		$this->assertFalse(PortalMode::TokenIsBounded(null, $iNow, 90), 'never expires');
		$this->assertFalse(PortalMode::TokenIsBounded('', $iNow, 90));
		$this->assertFalse(PortalMode::TokenIsBounded('not a date', $iNow, 90));
		$this->assertTrue(PortalMode::TokenIsBounded(null, $iNow, 0), 'a bound of 0 is lifted, deliberately');
	}

	/**
	 * @dataProvider refusalProvider
	 */
	public function testEachConditionIsRequired(?string $sPortal, bool $bToken, bool $bBounded, bool $bContact, bool $bProfile, string $sRefusal): void
	{
		$this->assertSame($sRefusal, PortalMode::RefusalOf($sPortal, $bToken, $bBounded, $bContact, $bProfile));
	}

	/**
	 * @return array<string, array{0: string|null, 1: bool, 2: bool, 3: bool, 4: bool, 5: string}>
	 */
	public function refusalProvider(): array
	{
		return [
			'no portal'        => [null, true, true, true, true, PortalMode::REFUSED_NO_SERVED_PORTAL],
			'not a token'      => ['itop-portal', false, true, true, true, PortalMode::REFUSED_NOT_A_TOKEN],
			'unbounded token'  => ['itop-portal', true, false, true, true, PortalMode::REFUSED_TOKEN_UNBOUNDED],
			'no contact'       => ['itop-portal', true, true, false, true, PortalMode::REFUSED_NO_CONTACT],
			'no MCP profile'   => ['itop-portal', true, true, true, false, PortalMode::REFUSED_NO_MCP_PROFILE],
			// The portal comes first: a user in no served portal is answered
			// as before portal mode existed, not told what else it lacks.
			'nothing held'     => [null, false, false, false, false, PortalMode::REFUSED_NO_SERVED_PORTAL],
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
	 * The class tools serve both modes: the datamodel on the console, what
	 * the portal offers in portal mode, each saying so in its description and
	 * answering from the portal's description there.
	 */
	public function testTheClassToolsDescribeThePortalInPortalMode(): void
	{
		$aTools = ['core_class_list' => new ClassList(), 'core_class_schema' => new ClassSchema()];
		$aConsole = array_map(static fn ($oTool): string => (string)$oTool->getDescription(), $aTools);

		PortalMode::Remember('itop-portal');

		foreach ($aTools as $sName => $oTool) {
			$this->assertTrue(PortalMode::Serves($oTool), "{$sName} answers from the portal's description and says so");
			$this->assertFalse($this->isHidden($oTool, $sName), "{$sName} to a portal user");
			$this->assertNotSame($aConsole[$sName], (string)$oTool->getDescription(), "{$sName} describes the datamodel to a portal user");
			$this->assertStringContainsString('PortalDescription::', self::bodyOf(get_class($oTool), 'execute'));
		}
	}

	/**
	 * The portal's lists are served there and only there, like the
	 * description, and to a token that may only read.
	 */
	public function testThePortalsListsAreServedInPortalModeOnly(): void
	{
		$oTool = new PortalList();
		$this->assertTrue($this->isHidden($oTool, 'core_portal_list'), 'core_portal_list is not for a console user');
		$this->assertTrue(PortalMode::Serves($oTool), 'core_portal_list enforces the portal\'s rules and says so');

		PortalMode::Remember('itop-portal');

		$this->assertFalse($this->isHidden($oTool, 'core_portal_list'), 'core_portal_list to a portal user');
		$this->assertFalse($this->isHidden($oTool, 'core_portal_list', [], AccessPolicy::FromScopes(['MCP-read'])), 'core_portal_list to a read-only token');
	}

	/** Who the user is, in both modes: it is their own identity and asks the portal for nothing. */
	public function testWhoTheUserIsIsServedInBothModes(): void
	{
		$oTool = new CurrentUserTool();
		$oResource = new CurrentUserResource();
		$sConsoleDescription = (string)$oTool->getDescription();

		$this->assertFalse($this->isHidden($oTool, 'core_current_user'));

		PortalMode::Remember('itop-portal');

		$this->assertFalse($this->isHidden($oTool, 'core_current_user'));
		$this->assertFalse($this->isHidden($oResource, 'itop://core/current-user'));
		$this->assertStringContainsString('core_object_search_by_oql', $sConsoleDescription);
		$this->assertStringNotContainsString('core_object_search', (string)$oTool->getDescription(), 'no console tool is named to a portal user');
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
		$this->assertTrue(PortalMode::Serves(new ClassList()));
	}

	/**
	 * Portal mode widens nothing: what the operator turned off and what the
	 * token's scopes leave out stay off for a portal user too.
	 */
	public function testTheUsualFiltersStillNarrowWhatPortalModeServes(): void
	{
		PortalMode::Remember('itop-portal');
		$oList = new ClassList();

		$this->assertTrue($this->isHidden($oList, 'core_class_list', ['core_class_list']), 'by identifier');
		$this->assertTrue($this->isHidden($oList, 'core_class_list', [ClassList::class]), 'by class');
		$this->assertTrue(
			$this->isHidden($oList, 'core_class_list', [], AccessPolicy::Of([], ['objects'])),
			'by a toolset list that leaves its toolset out'
		);
		$this->assertTrue(
			$this->isHidden($oList, 'core_class_list', [], AccessPolicy::Nothing()),
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

	/** The id becomes a file name that iTop then requires, so nothing else passes for one. */
	public function testOnlyAPlainNameIsAPortalId(): void
	{
		foreach (['itop-portal', 'partner_portal', 'Portal2', str_repeat('a', 64)] as $sId) {
			$this->assertTrue(PortalMode::IsPortalId($sId), $sId);
		}

		$aRefused = [
			'',
			'../../conf/x',
			'..',
			'.',
			'a/b',
			'a\\b',
			"itop-portal\n",
			"itop-portal\0.php",
			'itop-portal.php',
			'<?php phpinfo();?>',
			'a b',
			'a;b',
			'portál',
			str_repeat('a', 65),
		];
		foreach ($aRefused as $sId) {
			$this->assertFalse(PortalMode::IsPortalId($sId), json_encode($sId));
		}
	}

	/**
	 * A request for one portal does not queue behind the compilation of
	 * another: each has a lock of its own, beside the files it compiles.
	 */
	public function testEachPortalCompilesUnderALockOfItsOwn(): void
	{
		$sOne = PortalPolicy::CompileLockPath('/cache/portals/', 'itop-portal');

		$this->assertSame('/cache/portals/itop-portal.compile.lock', $sOne);
		$this->assertNotSame($sOne, PortalPolicy::CompileLockPath('/cache/portals/', 'partner-portal'));
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
	 * A create action names its class, or leaves it to a method the portal
	 * runs on the object the user picks - iTop's own itop-full-itil does that
	 * to the services brick wherever incidents and requests are both
	 * installed. That one is kept, with no class, rather than dropped: it is
	 * how the stock portal opens a ticket there.
	 */
	public function testACreateActionIsKeptWhetherItsClassIsNamedOrChosen(): void
	{
		$aNamed = PortalPolicy::CreateActionOf('services/1/1/1/create_from_this', [
			'type'    => 'create_from_this',
			'factory' => ['type' => 'class', 'value' => 'UserRequest'],
			'rules'   => ['contact-to-userrequest'],
		], 'ServiceSubcategory', 'New request');
		$this->assertSame('UserRequest', $aNamed['class']);
		$this->assertNull($aNamed['factory_method']);
		$this->assertSame(['contact-to-userrequest'], $aNamed['rules']);

		$aChosen = PortalPolicy::CreateActionOf('services/1/1/1/create_from_this', [
			'type'    => 'create_from_this',
			'factory' => ['type' => 'method', 'value' => '\\Ticket::CreateFromServiceSubcategory'],
		], 'ServiceSubcategory', 'New request');
		$this->assertNull($aChosen['class'], 'never guessed');
		$this->assertSame('\\Ticket::CreateFromServiceSubcategory', $aChosen['factory_method']);
		$this->assertSame('ServiceSubcategory', $aChosen['from']);

		$this->assertNull(PortalPolicy::CreateActionOf('x', ['type' => 'view'], 'Service', ''), 'not a create action');
		$this->assertNull(PortalPolicy::CreateActionOf('x', ['type' => 'create_from_this', 'factory' => ['type' => 'method', 'value' => '']], 'Service', ''));
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
		$this->assertStringContainsString('core_class_list', $sText);
	}

	/**
	 * Narrowed as the console's text is: a token that may only read is told
	 * how to read and nothing about writing, which it is not served.
	 */
	public function testThePortalTextIsNarrowedToWhatTheTokenIsServed(): void
	{
		$sEverything = ServerInstructions::PortalText('itop-portal');
		$sReadOnly = ServerInstructions::PortalText('itop-portal', AccessPolicy::FromScopes(['MCP-read']));

		foreach (['core_portal_list', 'core_object_get', 'core_object_create', 'simulate=false'] as $sNeedle) {
			$this->assertStringContainsString($sNeedle, $sEverything);
		}
		$this->assertStringContainsString('core_portal_list', $sReadOnly);
		$this->assertStringNotContainsString('core_object_create', $sReadOnly, 'a reader is told how to write');
		$this->assertStringNotContainsString('simulate', $sReadOnly);
		$this->assertStringNotContainsString('not served through this connection yet', $sEverything, 'the text still says nothing is served');
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

	/**
	 * The update and the stimulus serve both modes too, and in portal mode
	 * ask the mode's refusals - the portal's write scope, form, lifecycle and
	 * reach - besides UserRights. A read-only token is not served them.
	 */
	public function testTheWritesAreServedInBothModesWithThePortalsRefusals(): void
	{
		$aWrites = [
			'core_object_update'         => new \Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate(),
			'core_object_apply_stimulus' => new \Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus(),
			'core_object_create'         => new \Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate(),
		];
		$aNeedles = [
			'core_object_update'         => ['$oAccess->Hides($class, $id)', '$oAccess->RefusalToChange($oObject)', '$oAccess->RefusalToSet($oObject, $fields)', '$oAccess->RefusalsForReach('],
			'core_object_apply_stimulus' => ['$oAccess->Hides($class, $id)', '$oAccess->RefusalToChange($oObject)', '$oAccess->RefusalToApply($oObject, $stimulus)', '$oAccess->RefusalToSet($oObject, $fields, $stimulus)', '$oAccess->RefusalsForReach('],
			'core_object_create'         => ['$oAccess->Prepared(trim($action), $from_id)', '$oAccess->RefusalToSet($oObject, $fields)', '$oAccess->RefusalsForReach('],
		];

		PortalMode::Remember('itop-portal');

		foreach ($aWrites as $sName => $oTool) {
			$this->assertTrue(PortalMode::Serves($oTool), "{$sName} enforces the portal through ObjectAccess and says so");
			$this->assertFalse($this->isHidden($oTool, $sName), "{$sName} to a portal user");
			$this->assertTrue($this->isHidden($oTool, $sName, [], AccessPolicy::FromScopes(['MCP-read'])), "{$sName} writes, and a read-only token may not");
			$this->assertStringNotContainsString('core_class_schema', (string)$oTool->getDescription(), 'a console tool is named to a portal user');

			$sBody = self::bodyOf(get_class($oTool), 'execute');
			foreach ($aNeedles[$sName] as $sNeedle) {
				$this->assertStringContainsString($sNeedle, $sBody, "{$sName} skips {$sNeedle}");
			}
		}
	}

	/**
	 * One read for both modes: served to a console user and a portal one, and
	 * to a read-only token in either. In portal mode it names no console tool.
	 */
	public function testTheReadIsServedInBothModes(): void
	{
		$oTool = new ObjectGetTool();
		$sConsoleDescription = (string)$oTool->getDescription();

		$this->assertFalse($this->isHidden($oTool, 'core_object_get'));

		PortalMode::Remember('itop-portal');

		$this->assertTrue(PortalMode::Serves($oTool), 'core_object_get enforces the portal through ObjectAccess and says so');
		$this->assertFalse($this->isHidden($oTool, 'core_object_get'));
		$this->assertFalse($this->isHidden($oTool, 'core_object_get', [], AccessPolicy::FromScopes(['MCP-read'])));
		$this->assertStringContainsString('core_object_search_by_class', $sConsoleDescription);
		$this->assertStringNotContainsString('core_object_search', (string)$oTool->getDescription(), 'a console search is named to a portal user');
		$this->assertStringContainsString('core_portal_list', (string)$oTool->getDescription());
		$this->assertStringContainsString('ObjectAccess::Current()', self::bodyOf(ObjectGetTool::class, 'execute'));
	}

	/**
	 * One history tool for both modes. In portal mode it says so in the
	 * portal's words, and names no console tool.
	 */
	public function testTheHistoryIsServedInBothModes(): void
	{
		$oTool = new ObjectHistoryTool();
		$sConsoleDescription = (string)$oTool->getDescription();

		$this->assertFalse($this->isHidden($oTool, 'core_object_history'));

		PortalMode::Remember('itop-portal');

		$this->assertFalse($this->isHidden($oTool, 'core_object_history'));
		$this->assertTrue($this->isHidden($oTool, 'core_object_history', ['core_object_history']), 'the instance can still turn it off');
		$this->assertNotSame($sConsoleDescription, (string)$oTool->getDescription(), 'the console\'s description is given to a portal user');
		$this->assertStringContainsString('core_class_list', (string)$oTool->getDescription(), 'a portal user is not told where history is said to be shown');
		$this->assertStringContainsString('core_object_history', ServerInstructions::PortalText('itop-portal'));
	}

	/**
	 * In portal mode the change log is served by the history tool only, and
	 * there under the portal's limits as well as its own: the portal must show
	 * history and the object, its change-log scope bounds the rows, and its
	 * view form the attributes. The portal's list and get refuse it, since a
	 * row read as an object skips the gates on what it is about; and no write
	 * set ever holds it.
	 */
	public function testTheChangeLogIsServedByTheHistoryToolOnlyAndNeverWritten(): void
	{
		$this->assertMatchesRegularExpression(
			'/^[^{]*\{\s*if \(ObjectHistory::IsReserved\(\$sClass\)\) \{\s*return null;\s*\}/',
			self::bodyOf(PortalPolicy::class, 'WriteSet'),
			'WriteSet() must refuse the change log before anything else'
		);
		// execute() and the count tool both resolve a list through Resolve(), which refuses it.
		$this->assertStringContainsString('self::Resolve(', self::bodyOf(PortalList::class, 'execute'));
		$this->assertStringContainsString('refuseReserved', self::bodyOf(PortalList::class, 'Resolve'));

		$sHistory = self::bodyOf(ObjectHistoryTool::class, 'execute');
		foreach (['ShowsHistory()', '->Readable($class, $id)', '->Bounded(', 'HistoryReader::Within('] as $sNeedle) {
			$this->assertStringContainsString($sNeedle, $sHistory, "the portal-mode history skips {$sNeedle}");
		}
		$this->assertLessThan(
			strpos($sHistory, 'UserRights::IsActionAllowed('),
			strpos($sHistory, 'ShowsHistory()'),
			'the portal answers before UserRights, so its refusals are its own'
		);
	}

	/**
	 * The portal's access only takes away from the console's: each read
	 * asks the console's question before the portal's.
	 */
	public function testThePortalAccessNarrowsTheConsolesAndNeverReplacesIt(): void
	{
		$sReadable = self::bodyOf(\Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess::class, 'Readable');
		$this->assertLessThan(
			strpos($sReadable, '$this->oPolicy->Readable('),
			(int)strpos($sReadable, 'parent::Readable('),
			'the portal is asked instead of UserRights, or before it'
		);
		$this->assertStringContainsString('IsReserved', $sReadable, 'the change log is read as an object in portal mode');

		$oConsole = \Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess::Current();
		$this->assertNotInstanceOf(\Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess::class, $oConsole, 'a console request is given the portal\'s access');
		$this->assertTrue($oConsole->ShowsHistory());
	}

	/**
	 * A tool's schema and description can depend on the mode, and the
	 * registry validates them when it registers the tool: a portal-mode
	 * schema the registry refuses stops every tool from being served there.
	 */
	public function testEveryToolRegistersInPortalMode(): void
	{
		PortalMode::Remember('itop-portal');
		MCPRegistry::Clear();
		try {
			CoreExtensions::RegisterServiceProvider();
			$this->assertNotEmpty(MCPRegistry::GetTools());
		} finally {
			MCPRegistry::Clear();
		}
	}

	/**
	 * ADR 0006: a capability is one tool in both modes. A portal copy of a
	 * console tool is the duplication it ended, so the only tool of the
	 * portal's own is its lists, which have no console counterpart.
	 */
	public function testThePortalsListsAreTheOnlyPortalOnlyTool(): void
	{
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
		try {
			$aNames = array_keys(MCPRegistry::GetTools());
		} finally {
			MCPRegistry::Clear();
		}

		$this->assertSame(
			['core_portal_list'],
			array_values(array_filter($aNames, static fn (string $sName): bool => str_starts_with($sName, 'core_portal_'))),
			'a core_portal_* copy of a console tool was registered (doc/adr/0006)'
		);
	}

	/**
	 * ADR 0006: every tool served in portal mode that reads or writes objects
	 * or describes them asks the portal - through the mode-aware access, the
	 * portal's description, or the portal's policy - and never answers from
	 * UserRights alone, which grants "Portal user" read on every class.
	 * DocumentAccess::Fetch() is the one helper that asks it on a tool's behalf.
	 */
	public function testEveryPortalAwareObjectToolAsksThePortal(): void
	{
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
		try {
			$aTools = MCPRegistry::GetTools();
		} finally {
			MCPRegistry::Clear();
		}

		$iChecked = 0;
		foreach ($aTools as $sName => $oTool) {
			if (!PortalMode::Serves($oTool) || $oTool->getToolset() === 'server') {
				continue;
			}

			$sSource = '';
			for ($oClass = new \ReflectionClass($oTool); $oClass !== false; $oClass = $oClass->getParentClass()) {
				$sSource .= (string)file_get_contents((string)$oClass->getFileName());
			}
			$this->assertMatchesRegularExpression(
				'/ObjectAccess::Current\(\)|PortalDescription::|PortalPolicy::ForCurrentRequest\(\)|PortalList::Resolve\(|DocumentAccess::Fetch\(/',
				$sSource,
				"{$sName} is served in portal mode without asking the portal"
			);
			$iChecked++;
		}

		$this->assertGreaterThan(5, $iChecked, 'the scan found too few portal-aware object tools to be working');
	}

	/**
	 * ADR 0011: adding a file is a write, so a read-only token neither sees nor may call it in portal mode.
	 */
	public function testAReadOnlyTokenCannotAttachInPortalMode(): void
	{
		$oTool = new \Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach();
		PortalMode::Remember('itop-portal');

		$this->assertTrue(PortalMode::Serves($oTool));
		$this->assertFalse($this->isHidden($oTool, 'core_object_attach'), 'core_object_attach to a portal user with a write token');
		$this->assertTrue($this->isHidden($oTool, 'core_object_attach', [], AccessPolicy::FromScopes(['MCP-read'])), 'core_object_attach writes, and a read-only token may not');
	}

	private static function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}
}
