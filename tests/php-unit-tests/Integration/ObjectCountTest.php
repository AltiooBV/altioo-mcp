<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
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
 * core_object_count, console and portal, on real objects.
 *
 * A count is a read, and one that could be an oracle: "how many tickets in the
 * other organisation", "how many requests per value of a column the form hides",
 * "which of them has an agent I may not read". Each is asked here as the
 * account that must not learn it.
 */
class ObjectCountTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';
	private const ONGOING = 'ongoing-tickets-for-portal-user';

	private int $iOrgA;
	private int $iOrgB;
	private int $iPersonA;
	private int $iPersonB;
	private string $sSuffix;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs request management.');
		}

		$this->sSuffix = bin2hex(random_bytes(4));
		$this->iOrgA = (int)$this->CreateOrganization('CountA '.$this->sSuffix)->GetKey();
		$this->iOrgB = (int)$this->CreateOrganization('CountB '.$this->sSuffix)->GetKey();
		$this->iPersonA = (int)$this->CreatePerson(1, $this->iOrgA)->GetKey();
		$this->iPersonB = (int)$this->CreatePerson(2, $this->iOrgB)->GetKey();
		// Three requests in A, two in B, titled so that they can be told apart.
		for ($i = 0; $i < 3; $i++) {
			$this->CreateUserRequest(10 + $i, ['org_id' => $this->iOrgA, 'caller_id' => $this->iPersonA, 'title' => 'Count A '.$this->sSuffix]);
		}
		for ($i = 0; $i < 2; $i++) {
			$this->CreateUserRequest(20 + $i, ['org_id' => $this->iOrgB, 'caller_id' => $this->iPersonB, 'title' => 'Count B '.$this->sSuffix]);
		}
	}

	protected function tearDown(): void
	{
		PortalMode::Forget();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function runCount(array $aArguments): array
	{
		$aArguments += ['class' => '', 'filters' => [], 'oql' => '', 'group_by' => [], 'archived' => 'exclude', 'list' => '', 'tab' => '', 'parent_id' => 0];

		return ToolOutput::Decode(ObjectCount::execute(...$aArguments));
	}

	/** @return array<string, int> Org id => count, from a count by org_id. */
	private function byOrg(array $aResult): array
	{
		$aOut = [];
		foreach ($aResult['groups'] as $aGroup) {
			$aOut[(int)$aGroup['value']['org_id']] = $aGroup['count'];
		}

		return $aOut;
	}

	public function testAnAdministratorCountsPerOrganisationWithTheirNames(): void
	{
		$aResult = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count A '.$this->sSuffix], 'group_by' => ['org_id']]);

		$this->assertSame(3, $aResult['total']);
		$this->assertSame([$this->iOrgA => 3], $this->byOrg($aResult));
		$this->assertSame('CountA '.$this->sSuffix, $aResult['groups'][0]['label']['org_id']);
		$this->assertFalse($aResult['truncated']);
		$this->assertArrayHasKey('untrusted_text', $aResult, 'a label is an object\'s own text');
	}

	public function testTwoAttributesGiveOneGroupPerPair(): void
	{
		$aResult = $this->runCount(['class' => 'UserRequest', 'oql' => '', 'group_by' => ['org_id', 'status']]);

		foreach ($aResult['groups'] as $aGroup) {
			$this->assertSame(['org_id', 'status'], array_keys($aGroup['value']));
		}
		$this->assertSame($aResult['total'], array_sum(array_column($aResult['groups'], 'count')));
	}

	public function testAnEnumGroupIsLabelledWithiTopsLabel(): void
	{
		$aResult = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count A '.$this->sSuffix], 'group_by' => ['status']]);

		$this->assertSame('new', $aResult['groups'][0]['value']['status']);
		$this->assertNotSame('', (string)$aResult['groups'][0]['label']['status']);
	}

	public function testOqlWorksAndExcludesClassAndFilters(): void
	{
		$aResult = $this->runCount(['oql' => "SELECT UserRequest WHERE title = 'Count B ".$this->sSuffix."'"]);
		$this->assertSame(2, $aResult['total']);

		$this->expectException(ToolCallException::class);
		$this->runCount(['oql' => 'SELECT UserRequest', 'class' => 'UserRequest']);
	}

	/**
	 * What a refusal says is what a missing attribute says, so that "I may not"
	 * cannot be told from "there is none": the oracle is in the difference.
	 *
	 * @dataProvider unreadableAttributeProvider
	 */
	public function testAnAttributeThatCannotBeCountedIsRefusedAsOneThatDoesNotExist(string $sClass, string $sAttCode): void
	{
		try {
			$this->runCount(['class' => $sClass, 'group_by' => [$sAttCode]]);
			$this->fail("{$sClass}.{$sAttCode} was grouped by");
		} catch (ToolCallException $oException) {
			$this->assertSame("Unknown attribute '{$sAttCode}' on class '{$sClass}'.", $oException->getMessage());
		}
		try {
			$this->runCount(['class' => $sClass, 'group_by' => ['no_such_attribute_here']]);
			$this->fail('a missing attribute was grouped by');
		} catch (ToolCallException $oException) {
			$this->assertSame("Unknown attribute 'no_such_attribute_here' on class '{$sClass}'.", $oException->getMessage());
		}
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function unreadableAttributeProvider(): array
	{
		return [
			'long text'      => ['UserRequest', 'description'],
			'a secret'       => ['UserLocal', 'password'],
			'a linked set'   => ['UserRequest', 'contacts_list'],
			'a case log'     => ['UserRequest', 'public_log'],
		];
	}

	public function testAClassTheCallerMayNotCountIsRefusedAsOneThatDoesNotExist(): void
	{
		$aMessages = [];
		foreach (['CMDBChangeOpSetAttributeScalar', 'NoSuchClass'.$this->sSuffix] as $sClass) {
			try {
				$this->runCount(['class' => $sClass]);
				$this->fail("{$sClass} was counted");
			} catch (ToolCallException $oException) {
				$aMessages[] = str_replace($sClass, '<class>', $oException->getMessage());
			}
		}

		$this->assertSame($aMessages[0], $aMessages[1], 'the change log is refused in the words of a class that is not there');
	}

	public function testAFilterOnAnUnreadableAttributeIsRefusedToo(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage("Unknown attribute 'password' on class 'UserLocal'.");
		$this->runCount(['class' => 'UserLocal', 'filters' => ['password' => 'x']]);
	}

	private function limitedUser(): string
	{
		$sLogin = 'count-limited-'.$this->sSuffix;
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Support Agent'), 'Count-Limited-'.$this->sSuffix.'-1!', $this->iPersonA)->GetKey();
		$oLink = MetaModel::NewObject('URP_UserOrg');
		$oLink->Set('userid', $iUser);
		$oLink->Set('allowed_org_id', $this->iOrgA);
		$oLink->DBInsert();

		return $sLogin;
	}

	/** The silo: an account limited to one organisation counts nothing of the other. */
	public function testAnOrganisationLimitedUserCountsNothingOfAnotherOrganisation(): void
	{
		UserRights::Login($this->limitedUser());

		$aAll = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count B '.$this->sSuffix], 'group_by' => ['org_id']]);
		$this->assertSame(0, $aAll['total'], 'the other organisation\'s requests are not counted');
		$this->assertSame([], $aAll['groups']);

		$aOwn = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count A '.$this->sSuffix], 'group_by' => ['org_id']]);
		$this->assertSame(3, $aOwn['total']);
		$this->assertSame([$this->iOrgA => 3], $this->byOrg($aOwn));

		$aEvery = $this->runCount(['class' => 'UserRequest', 'group_by' => ['org_id']]);
		$this->assertArrayNotHasKey($this->iOrgB, $this->byOrg($aEvery), 'a group is not a way to see that another organisation has tickets');
	}

	/** An external key whose target the account may not read is its id and no name. */
	public function testAnExternalKeyIsNamedOnlyWhereItsTargetIsReadable(): void
	{
		$iRequest = (int)$this->CreateUserRequest(30, ['org_id' => $this->iOrgA, 'caller_id' => $this->iPersonA, 'title' => 'Count agent '.$this->sSuffix])->GetKey();
		$oRequest = MetaModel::GetObject('UserRequest', $iRequest);
		$oRequest->Set('agent_id', $this->iPersonB);
		try {
			$oRequest->DBUpdate();
		} catch (\Throwable $e) {
			$this->markTestSkipped('This datamodel does not let a request in A have an agent from B: '.$e->getMessage());
		}

		$aAdmin = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count agent '.$this->sSuffix], 'group_by' => ['agent_id']]);
		$this->assertNotNull($aAdmin['groups'][0]['label']['agent_id'], 'an administrator reads the agent');

		UserRights::Login($this->limitedUser());
		$aLimited = $this->runCount(['class' => 'UserRequest', 'filters' => ['title' => 'Count agent '.$this->sSuffix], 'group_by' => ['agent_id']]);

		$this->assertSame($this->iPersonB, $aLimited['groups'][0]['value']['agent_id'], 'the id is what the request holds');
		$this->assertNull($aLimited['groups'][0]['label']['agent_id'], 'and the name of someone in an organisation this account cannot see is not given');
	}

	public function testMoreThanAHundredGroupsAreCutAndSaySo(): void
	{
		// Every organisation is a group of Organization itself by id: more than a hundred only on a large instance.
		$aResult = $this->runCount(['class' => 'Person', 'group_by' => ['first_name', 'org_id']]);

		$this->assertLessThanOrEqual(ObjectCount::MAX_GROUPS, count($aResult['groups']));
		if ($aResult['truncated']) {
			$this->assertSame(ObjectCount::MAX_GROUPS, count($aResult['groups']));
			$this->assertStringContainsString((string)ObjectCount::MAX_GROUPS, $aResult['note']);
		}
	}

	public function testThePortalCountsOnlyWhatItsListHoldsForTheUser(): void
	{
		$this->requirePortal();
		$aPortal = $this->portalUser();
		UserRights::Login($aPortal['login']);
		PortalMode::Remember(self::PORTAL);

		$aList = ToolOutput::Decode(PortalList::execute(self::ONGOING));
		$aCount = $this->runCount(['list' => self::ONGOING]);

		$this->assertSame($aList['total'], $aCount['total'], 'the count and the list read one query');
		$this->assertSame(1, $aCount['total'], 'the user\'s own request, and no colleague\'s');
		$this->assertSame('Ticket', $aCount['class'], 'the class of the list, which is the stock portal\'s Ticket');
	}

	public function testThePortalCountsPerAColumnTheListShowsAndRefusesOneItHides(): void
	{
		$this->requirePortal();
		$aPortal = $this->portalUser();
		UserRights::Login($aPortal['login']);
		PortalMode::Remember(self::PORTAL);

		// The stock list is of the abstract Ticket, so what it can be counted per is what the list
		// shows and Ticket has: its caller, its dates, its title. A column of one subclass (status,
		// priority) is not an attribute of the class the list reads, and is refused as one that is
		// not there; so is the class itself, which the list does not show.
		// An external key the list shows: the id is what the row holds, and the name only where the portal shows it.
		$aByCaller = $this->runCount(['list' => self::ONGOING, 'group_by' => ['caller_id']]);
		$this->assertSame($aPortal['contact'], $aByCaller['groups'][0]['value']['caller_id']);

		// A column the list does not show, and a text one, are refused as absent.
		foreach (['status', 'priority', 'finalclass', 'agent_id', 'description', 'private_log', 'org_id'] as $sHidden) {
			try {
				$this->runCount(['list' => self::ONGOING, 'group_by' => [$sHidden]]);
				$this->fail("{$sHidden} was counted per value in the portal");
			} catch (ToolCallException $oException) {
				$this->assertStringStartsWith("Unknown attribute '{$sHidden}'", $oException->getMessage());
			}
		}
	}

	public function testAPortalListThatIsNotOfferedIsRefusedWithoutEcho(): void
	{
		$this->requirePortal();
		$aPortal = $this->portalUser();
		UserRights::Login($aPortal['login']);
		PortalMode::Remember(self::PORTAL);

		try {
			$this->runCount(['list' => "ongoing' OR 1=1 --"]);
			$this->fail('an unknown list was counted');
		} catch (ToolCallException $oException) {
			$this->assertStringNotContainsString('OR 1=1', $oException->getMessage());
		}
	}

	private function requirePortal(): void
	{
		if (!class_exists('Combodo\iTop\Portal\Brick\BrickCollection') || !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal, iTop 3.2.2 or later.');
		}
	}

	/** @return array{login: string, contact: int} */
	private function portalUser(): array
	{
		$iContact = (int)$this->CreatePerson(40, $this->iOrgA)->GetKey();
		$this->CreateUserRequest(40, ['org_id' => $this->iOrgA, 'caller_id' => $iContact, 'title' => 'Count portal own '.$this->sSuffix]);
		$sLogin = 'count-portal-'.$this->sSuffix;
		$this->CreateUser($sLogin, self::profileId('Portal user'), 'Count-Portal-'.$this->sSuffix.'-1!', $iContact);

		return ['login' => $sLogin, 'contact' => $iContact];
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
