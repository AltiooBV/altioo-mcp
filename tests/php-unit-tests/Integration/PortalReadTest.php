<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Core\Prompts\MyOpenTickets;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Service\Read\CurrentUserReader;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
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
 * Portal mode's reads, against the stock portal.
 *
 * The lists are the portal's bricks run on the server, and an object is read
 * through the scopes - so what one portal user sees here is what they see in
 * the portal, and another caller's request, in the same organisation, is not
 * in it. Two users in one organisation is the case that matters: the stock
 * ticket scope is the caller's own requests, not the organisation's.
 */
class PortalReadTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';

	/** The stock ManageBrick listing the user's own open requests. */
	private const ONGOING = 'ongoing-tickets-for-portal-user';

	private int $iOrg;

	private int $iContact;

	private string $sLogin;

	/** The portal user's own request. */
	private int $iOwn;

	/** A request by someone else in the same organisation. */
	private int $iOther;

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
		$this->iOrg = (int)$this->CreateOrganization('PortalReadTest '.$sSuffix)->GetKey();
		$this->iContact = (int)$this->CreatePerson(1, $this->iOrg)->GetKey();
		$this->iOwn = (int)$this->CreateUserRequest(1, [
			'org_id' => $this->iOrg, 'caller_id' => $this->iContact, 'title' => 'PortalReadTest printer '.$sSuffix,
		])->GetKey();
		$this->iOther = (int)$this->CreateUserRequest(2, [
			'org_id' => $this->iOrg, 'caller_id' => (int)$this->CreatePerson(2, $this->iOrg)->GetKey(), 'title' => 'PortalReadTest other '.$sSuffix,
		])->GetKey();

		$this->sLogin = 'portal-read-'.$sSuffix;
		// The password policy wants a digit, and a hex suffix has none about one time in 2500: the digit is written in.
		$this->CreateUser($this->sLogin, self::profileId('Portal user'), 'Portal-Read-'.$sSuffix.'-1!', $this->iContact);

		UserRights::Login($this->sLogin);
		PortalAccess::Enter(self::PORTAL);
	}

	protected function tearDown(): void
	{
		PortalAccess::Leave();

		parent::tearDown();
	}

	public function testTheOngoingListHoldsTheUsersRequestAndNoOneElses(): void
	{
		$aIds = $this->idsIn(ToolOutput::Decode(PortalList::execute(self::ONGOING)));

		$this->assertContains($this->iOwn, $aIds);
		$this->assertNotContains($this->iOther, $aIds, 'a colleague\'s request is not the user\'s');
	}

	public function testEveryRowSaysItsClass(): void
	{
		$aResult = ToolOutput::Decode(PortalList::execute(self::ONGOING));

		$this->assertNotSame([], $aResult['objects']);
		foreach ($aResult['objects'] as $aRow) {
			$this->assertTrue(MetaModel::IsValidClass($aRow['class']));
		}
	}

	/** The stock brick's tabs, each counted for this user, as the portal heads them. */
	public function testTheTabsAreCountedAndNarrowTheList(): void
	{
		$aResult = ToolOutput::Decode(PortalList::execute(self::ONGOING));
		$this->assertArrayHasKey('tabs', $aResult);

		$aCounts = array_column($aResult['tabs'], 'count', 'tab');
		$this->assertSame($aResult['total'], array_sum($aCounts), 'every tab, and nothing else, without one');

		// A new request is open, not resolved.
		$sOpen = (string)array_key_first($aCounts);
		$this->assertContains($this->iOwn, $this->idsIn(ToolOutput::Decode(PortalList::execute(self::ONGOING, $sOpen))));
	}

	public function testAnUnknownTabIsRefused(): void
	{
		$this->expectException(ToolCallException::class);
		PortalList::execute(self::ONGOING, 'no-such-tab');
	}

	/** Not quoted back: the id is the client's text. */
	public function testAListThePortalDoesNotOfferIsRefusedWithoutEcho(): void
	{
		try {
			PortalList::execute("ongoing' OR 1=1 --");
			$this->fail('an unknown list was run');
		} catch (ToolCallException $oException) {
			$this->assertStringNotContainsString('OR 1=1', $oException->getMessage());
		}
	}

	public function testASearchFindsByTheWordsInTheColumns(): void
	{
		$sTitle = MetaModel::GetObject('UserRequest', $this->iOwn)->Get('title');
		$sWord = (string)substr($sTitle, (int)strrpos($sTitle, ' ') + 1);

		$this->assertSame([$this->iOwn], $this->idsIn(ToolOutput::Decode(PortalList::execute(self::ONGOING, '', 0, 'printer '.$sWord))));
		$this->assertSame([], $this->idsIn(ToolOutput::Decode(PortalList::execute(self::ONGOING, '', 0, 'scanner '.$sWord))), 'every word is required');
	}

	/** A search runs inside the list, so it cannot reach past what the list holds. */
	public function testASearchCannotReachAnotherCallersRequest(): void
	{
		$sTitle = MetaModel::GetObject('UserRequest', $this->iOther)->Get('title');

		$this->assertSame([], $this->idsIn(ToolOutput::Decode(PortalList::execute(self::ONGOING, '', 0, '"'.$sTitle.'"'))));
	}

	public function testTheUsersRequestIsReadThroughItsViewForm(): void
	{
		$aResult = ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iOwn));

		$this->assertSame('UserRequest', $aResult['object']['class']);
		$this->assertArrayHasKey('title', $aResult['object']);
		$this->assertArrayHasKey('public_log', $aResult['object']);
		$this->assertArrayNotHasKey('private_log', $aResult['object'], 'the agents\' log is not on the portal\'s form');
		$this->assertIsArray($aResult['object']['attachments']);
		$this->assertArrayNotHasKey('audit', $aResult['object'], 'the attribution reads the change log, which this portal does not show');
		$this->assertSame('no', $aResult['object']['rights']['delete'], 'the portal deletes nothing');
	}

	/** Through a parent class, as a row of a Ticket list names it. */
	public function testAParentClassResolvesToTheObjectsOwn(): void
	{
		$this->assertSame('UserRequest', ToolOutput::Decode(ObjectGet::execute('Ticket', $this->iOwn))['class']);
	}

	public function testANewRequestCanBeRepliedToButNotClosed(): void
	{
		$aObject = ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iOwn))['object'];

		$this->assertSame('yes', $aObject['rights']['modify']);
		$this->assertContains('public_log', $aObject['settable_fields']);
		$this->assertNotContains('caller_id', $aObject['settable_fields'], 'the caller is read-only in the portal');
		$this->assertNotContains('ev_close', array_column($aObject['stimuli']['available'] ?? [], 'stimulus'), 'a new request is not closed from the portal');
	}

	/**
	 * The stock portal grants no brick on the change log, so it shows no
	 * history: describe says so, and the history tool refuses even the
	 * user's own request, which UserRights alone would let them read.
	 */
	public function testTheStockPortalShowsNoHistory(): void
	{
		$this->assertFalse(ToolOutput::Decode(ClassList::execute())['history']);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('The portal shows no history.');
		ObjectHistory::execute('UserRequest', $this->iOwn);
	}

	/** The change log is not a list or an object the portal tools serve. */
	public function testTheChangeLogIsNotReadAsAnObject(): void
	{
		$this->expectException(ToolCallException::class);
		ObjectGet::execute('CMDBChangeOp', 1);
	}

	/** The audit row names the portal that served the call (doc/adr/0007). */
	public function testTheAuditRowHasAPortalField(): void
	{
		$this->assertTrue(MetaModel::IsValidAttCode('AltiooEventMCPService', 'portal'));
	}

	/** Not found, whether it exists or not: the portal answers both alike. */
	public function testAnotherCallersRequestIsNotFound(): void
	{
		try {
			ObjectGet::execute('UserRequest', $this->iOther);
			$this->fail('another caller\'s request was read');
		} catch (ToolCallException $oMissing) {
		}
		try {
			ObjectGet::execute('UserRequest', 999999999);
			$this->fail('a request that does not exist was read');
		} catch (ToolCallException $oAbsent) {
		}

		$this->assertSame(
			str_replace((string)$this->iOther, 'N', $oMissing->getMessage()),
			str_replace('999999999', 'N', $oAbsent->getMessage())
		);
	}

	public function testTheDescriptionSaysWhichStatesAStimulusAppliesIn(): void
	{
		$aRequest = current(array_filter(
			ToolOutput::Decode(ClassList::execute())['classes'],
			static fn (array $a): bool => $a['class'] === 'UserRequest'
		));

		foreach ($aRequest['stimuli'] as $aStimulus) {
			$this->assertNotSame([], $aStimulus['from_states'], "{$aStimulus['code']} applies in some state");
			$this->assertNotContains('new', $aStimulus['code'] === 'ev_close' ? $aStimulus['from_states'] : []);
		}
	}

	/**
	 * What the user can do here, as the identity block reports it: portal
	 * mode serves no delete tool, so no grant reports a delete there. Their organisation and portal are named too.
	 */
	public function testTheIdentityReportsWhatThePortalServes(): void
	{
		// Registered per request in production; a test has to do it itself.
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
		MCPService::RememberServed();
		try {
			$aPayload = CurrentUserReader::Payload();
		} finally {
			ServedAccess::Forget();
			MCPRegistry::Clear();
		}

		$this->assertSame(['read', 'write'], $aPayload['access']['capabilities']);
		$this->assertContains('core_portal_list', $aPayload['access']['tools']);
		$this->assertNotContains('core_object_search_by_oql', $aPayload['access']['tools'], 'a console tool is named to a portal user');
		$this->assertContains('core_my_open_tickets', $aPayload['access']['prompts']);
		$this->assertSame(self::PORTAL, $aPayload['current_portal']);
		$this->assertSame($this->iOrg, $aPayload['current_organization']['id']);
	}

	/** The commonest question, answered from the portal's own list rather than OQL. */
	public function testTheMyTicketsPromptNamesThePortalsList(): void
	{
		$oPrompt = new MyOpenTickets();
		$this->assertTrue($oPrompt->isAvailable());

		$sText = $oPrompt->get()[0]['content']['text'];
		$this->assertStringContainsString('core_portal_list', $sText);
		$this->assertStringContainsString(self::ONGOING, $sText);
		$this->assertStringNotContainsString('OQL', $sText);
	}

	/**
	 * @param array<string, mixed> $aResult
	 *
	 * @return array<int, int>
	 */
	private function idsIn(array $aResult): array
	{
		return array_map(static fn (array $aRow): int => (int)$aRow['id'], $aResult['objects']);
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');
		$oProfile = (new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch();

		return (int)$oProfile->GetKey();
	}
}
