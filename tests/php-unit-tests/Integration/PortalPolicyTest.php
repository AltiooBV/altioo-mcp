<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Altioo\iTop\Extension\MCP\Test\Support\PortalTestBrick;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Portal mode's reading of a portal, against the stock one.
 *
 * Everything PortalPolicy answers comes from iTop's portal code - its bricks,
 * its compiled scopes, its form merge - run outside the portal's kernel, so
 * none of it can be tested without an instance. The stock portal is what
 * every instance with the ITSM modules has, and it is enough to hold the
 * decisions that matter: a class no granted brick reaches is not offered even
 * with a read scope (doc/adr/0001-portal-mode-bricks-bound-discovery.md), a
 * class a brick reaches is not offered without one, and the forms are the
 * portal's.
 */
class PortalPolicyTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';

	/** The contact the logged-in portal user is linked to. */
	private int $iContact;

	private int $iOrg;

	private string $sLogin;

	protected function setUp(): void
	{
		parent::setUp();

		if (!MetaModel::IsValidClass('UserRequest') || !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')) {
			$this->markTestSkipped('Needs the stock portal and the request management module.');
		}
		if (!method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Portal mode needs iTop 3.2.2 or later, and refuses to read a portal before it.');
		}

		$sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('PortalPolicyTest '.$sSuffix)->GetKey();
		$this->iContact = (int)$this->CreatePerson(1, $this->iOrg)->GetKey();
		$this->sLogin = 'portal-policy-'.$sSuffix;
		$this->CreateUser($this->sLogin, self::profileId('Portal user'), 'Portal-Policy-'.$sSuffix.'-1!', $this->iContact);

		UserRights::Login($this->sLogin);
		PortalAccess::Enter(self::PORTAL);
	}

	protected function tearDown(): void
	{
		PortalAccess::Leave();

		parent::tearDown();
	}

	/**
	 * The stock portal's granted bricks: the ticket lists, the services
	 * browse whose last level creates a request, and the FAQ.
	 */
	public function testTheStockBricksReachTicketsServicesAndTheFaq(): void
	{
		$aReach = PortalPolicy::ForCurrentRequest()->Reach();

		foreach (['Ticket', 'ServiceFamily', 'Service', 'ServiceSubcategory', 'UserRequest'] as $sClass) {
			$this->assertArrayHasKey($sClass, $aReach, "a stock brick reaches {$sClass}");
		}
		$this->assertArrayNotHasKey('Organization', $aReach);
		$this->assertArrayNotHasKey('Server', $aReach);
	}

	/**
	 * The portal user's organisation is in scope - the stock portal scopes
	 * Organization to the user's own - and no brick lists organisations, so
	 * it is not offered. This is the decision of ADR 0001, held.
	 */
	public function testAClassWithAReadScopeThatNoBrickReachesIsNotOffered(): void
	{
		$oPolicy = PortalPolicy::ForCurrentRequest();

		$this->assertNotNull($oPolicy->ReadSet('Organization'), 'the stock portal scopes Organization to the user\'s own');
		$this->assertSame([], $oPolicy->ReachedBy('Organization'));
		$this->assertNotContains('Organization', $this->offeredClasses());

		$this->expectException(ToolCallException::class);
		ClassSchema::execute('Organization');
	}

	/**
	 * The other half of the test above, and of ADR 0001: a class no brick
	 * offers is still read by id where the scope covers it, so a ticket's
	 * organisation resolves. Only the user's own: another organisation, and an
	 * id that does not exist, are refused alike, so the answer is no oracle.
	 */
	public function testAnObjectInScopeIsReadByIdWithoutABrickAndNoOtherIs(): void
	{
		UserRights::Logoff();
		$iOther = (int)$this->CreateOrganization('PortalPolicyTest other '.bin2hex(random_bytes(4)))->GetKey();
		UserRights::Login($this->sLogin);

		$oPolicy = PortalPolicy::ForCurrentRequest();
		$this->assertSame([], $oPolicy->ReachedBy('Organization'), 'no brick reaches Organization');

		$oOwn = $oPolicy->Readable('Organization', $this->iOrg);
		$this->assertNotNull($oOwn, 'the user\'s own organisation resolves by scope');
		$this->assertSame($this->iOrg, (int)$oOwn->GetKey());

		$this->assertNull($oPolicy->Readable('Organization', $iOther), 'another organisation is out of scope');
		$this->assertNull($oPolicy->Readable('Organization', $iOther + 100000), 'an absent id answers the same');
	}

	/**
	 * A class a granted brick reaches that the scopes give the user none of -
	 * Change or Problem under the stock ticket bricks, or Incident where its
	 * module is installed without a scope. Found rather than named, because
	 * which modules an instance has decides whether there is one.
	 */
	public function testAClassABrickReachesThatTheScopesRefuseIsNotOffered(): void
	{
		$oPolicy = PortalPolicy::ForCurrentRequest();

		$sRefused = null;
		foreach (array_keys($oPolicy->Reach()) as $sRoot) {
			foreach (MetaModel::EnumChildClasses($sRoot, ENUM_CHILD_CLASSES_ALL) as $sClass) {
				if (!MetaModel::IsAbstract($sClass) && $oPolicy->ReachedBy($sClass) !== [] && $oPolicy->ReadSet($sClass) === null) {
					$sRefused = $sClass;
					break 2;
				}
			}
		}
		if ($sRefused === null) {
			$this->markTestSkipped('Every concrete class the stock bricks reach has a read scope on this instance.');
		}

		$this->assertNotSame([], $oPolicy->ReachedBy($sRefused));
		$this->assertNotContains($sRefused, $this->offeredClasses());
	}

	/**
	 * Change and Problem are Tickets, so the ticket bricks reach their parent,
	 * and they inherit the Ticket scope - which pins finalclass to UserRequest
	 * and Incident. An end user never sees one in the portal, and they are not
	 * offered here; nor is the "ongoing" brick, pinned the same way, a way to
	 * one.
	 */
	public function testTheAgentsTicketClassesAreNotOffered(): void
	{
		$oPolicy = PortalPolicy::ForCurrentRequest();
		$aOffered = $this->offeredClasses();

		$iChecked = 0;
		foreach (['Change', 'Problem'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				continue;
			}
			$iChecked++;
			$this->assertNull($oPolicy->ReadSet($sClass), "{$sClass} inherits a scope that can hold none");
			$this->assertNotContains('ongoing-tickets-for-portal-user', $oPolicy->ReachedBy($sClass));
			$this->assertNotContains($sClass, $aOffered);
		}
		if ($iChecked === 0) {
			$this->markTestSkipped('Neither change nor problem management is installed.');
		}

		$this->assertContains('UserRequest', $aOffered, 'the class the scope does allow is still offered');
		$this->assertContains('ongoing-tickets-for-portal-user', $oPolicy->ReachedBy('UserRequest'));
	}

	/**
	 * A custom brick class extending a stock type is read as that type
	 * (doc/adr/0002): it declares its data the way its parent does.
	 */
	public function testACustomBrickClassIsReadAsTheStockTypeItExtends(): void
	{
		$this->assertSame(PortalPolicy::BRICK_MANAGE, PortalPolicy::BrickKind(PortalTestBrick::class));
	}

	/** Reading a portal takes that portal's lock, not one every portal shares. */
	public function testReadingAPortalTakesThatPortalsLock(): void
	{
		PortalPolicy::ForCurrentRequest();

		$this->assertFileExists(PortalPolicy::CompileLockPath(rtrim(\utils::GetCachePath(), '/').'/altioo-mcp/portals/', self::PORTAL));
	}

	/** An id that is no file name is refused before anything is read or written under it. */
	public function testAPortalIdThatIsNoFileNameIsRefused(): void
	{
		$sId = '../../altioo-mcp-escaped';
		$sCompiled = rtrim(\utils::GetCachePath(), '/').'/altioo-mcp/portals/';
		PortalAccess::Enter($sId);

		try {
			PortalPolicy::ForCurrentRequest();
			$this->fail('The id was read.');
		} catch (MCPPortalException $oException) {
			// A failure while reading the portal is wrapped, with its cause.
			$this->assertNull($oException->getPrevious(), 'Refused by its shape, before anything tried to read it.');
			$this->assertFileDoesNotExist(PortalPolicy::CompileLockPath($sCompiled, $sId));
			$this->assertFileDoesNotExist($sCompiled.$sId.'.scopes.php');
		}
	}

	/** A class the portal never mentions is refused like an unknown one. */
	public function testAClassThePortalNeverMentionsIsRefused(): void
	{
		$this->assertNull(PortalPolicy::ForCurrentRequest()->ReadSet('Server'));

		$this->expectException(ToolCallException::class);
		ClassSchema::execute('Server');
	}

	/** The stock scope on requests is the caller's own. */
	public function testTheReadSetHoldsTheCallersRequestsAndNoOneElses(): void
	{
		UserRights::Logoff();
		$iOwn = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->iContact])->GetKey();
		$iOther = (int)$this->CreateUserRequest(2, ['org_id' => $this->iOrg, 'caller_id' => (int)$this->CreatePerson(2, $this->iOrg)->GetKey()])->GetKey();
		UserRights::Login($this->sLogin);

		$oSet = new DBObjectSet(PortalPolicy::ForCurrentRequest()->ReadSet('UserRequest'));
		$aIds = array_map('intval', $oSet->GetColumnAsArray('id'));

		$this->assertContains($iOwn, $aIds);
		$this->assertNotContains($iOther, $aIds);
	}

	/**
	 * The stock create form has no caller, organisation or origin: those
	 * come from the action rules, on the server, and are not the client's.
	 */
	public function testTheCreateFormSetsNeitherCallerNorOrganisation(): void
	{
		$aFields = PortalPolicy::ForCurrentRequest()->Fields('UserRequest', PortalPolicy::MODE_CREATE);

		$this->assertArrayHasKey('title', $aFields);
		$this->assertArrayHasKey('description', $aFields);
		$this->assertArrayNotHasKey('caller_id', $aFields);
		$this->assertArrayNotHasKey('org_id', $aFields);
		$this->assertArrayNotHasKey('origin', $aFields);
	}

	/**
	 * The stock ticket form is a Twig layout, read by rendering it as the
	 * portal does: the caller is shown read-only, the public log is writable.
	 */
	public function testTheEditFormIsThePortalsTwigLayout(): void
	{
		$oPolicy = PortalPolicy::ForCurrentRequest();
		$aFields = $oPolicy->Fields('UserRequest', PortalPolicy::MODE_EDIT);

		$this->assertSame('ticket-edit', $oPolicy->FormOf('UserRequest', PortalPolicy::MODE_EDIT));
		$this->assertArrayHasKey('caller_id', $aFields);
		$this->assertSame(OPT_ATT_READONLY, $aFields['caller_id'] & OPT_ATT_READONLY);
		$this->assertArrayHasKey('public_log', $aFields);
		$this->assertSame(0, $aFields['public_log'] & OPT_ATT_READONLY);
	}

	/** A class the portal declares no form for uses the "details" list, and says so. */
	public function testAClassWithoutAPortalFormSaysItUsesTheDefault(): void
	{
		$this->assertNull(PortalPolicy::ForCurrentRequest()->FormOf('Organization', PortalPolicy::MODE_VIEW));
	}

	/** The overview a model reads first: requests, with their create action. */
	public function testTheOverviewOffersRequestsAndHowToCreateOne(): void
	{
		$aOverview = ToolOutput::Decode(ClassList::execute());

		$this->assertSame(self::PORTAL, $aOverview['portal']);
		$aRequest = current(array_filter($aOverview['classes'], static fn (array $a): bool => $a['class'] === 'UserRequest'));
		$this->assertIsArray($aRequest, 'UserRequest is offered');
		$this->assertTrue($aRequest['edit']);
		$this->assertNotSame([], $aRequest['create'], 'the services browse creates a request');
		$this->assertArrayNotHasKey('forms', $aRequest, 'the overview carries no fields');
	}

	/**
	 * @return array<int, string>
	 */
	private function offeredClasses(): array
	{
		return array_column(ToolOutput::Decode(ClassList::execute())['classes'], 'class');
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');
		$oProfile = (new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch();

		return (int)$oProfile->GetKey();
	}
}
