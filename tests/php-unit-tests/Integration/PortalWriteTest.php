<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
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
 * Portal mode's writes, against the stock portal.
 *
 * What a portal user does to their requests: reply on the public log, close
 * one that is resolved, and open a new one from the service catalogue. Each
 * is held to the portal's forms and scopes - a field the portal shows
 * read-only is refused, a stimulus the state does not accept is refused, and
 * a service outside the user's contracts cannot be filed against.
 *
 * The catalogue is built here: a service is offered to an organisation only
 * through a customer contract, which no sample data can be relied on to have.
 */
class PortalWriteTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	private const PORTAL = 'itop-portal';

	private int $iOrg;

	private int $iContact;

	private string $sLogin;

	/** The user's own request, new. */
	private int $iNew;

	/** The user's own request, resolved. */
	private int $iResolved;

	/** Someone else's request in the same organisation. */
	private int $iOther;

	/** A subcategory offered to the user's organisation. */
	private int $iSubcategory;

	private int $iService;

	/** A subcategory offered to another organisation only. */
	private int $iForeignSubcategory;

	protected function setUp(): void
	{
		parent::setUp();

		foreach (['UserRequest', 'ServiceSubcategory', 'CustomerContract'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}: request and service management.");
			}
		}
		if (!class_exists('Combodo\iTop\Portal\Brick\BrickCollection') || !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal on iTop 3.2.2 or later.');
		}

		$sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('PortalWriteTest '.$sSuffix)->GetKey();
		$iProvider = (int)$this->CreateOrganization('PortalWriteTest provider '.$sSuffix)->GetKey();
		$iForeign = (int)$this->CreateOrganization('PortalWriteTest foreign '.$sSuffix)->GetKey();
		$this->iContact = (int)$this->CreatePerson(1, $this->iOrg)->GetKey();

		[$this->iService, $this->iSubcategory] = $this->catalogue('Mine '.$sSuffix, $iProvider, $this->iOrg);
		[, $this->iForeignSubcategory] = $this->catalogue('Theirs '.$sSuffix, $iProvider, $iForeign);

		$this->iNew = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->iContact])->GetKey();
		$this->iResolved = (int)$this->CreateUserRequest(2, ['org_id' => $this->iOrg, 'caller_id' => $this->iContact])->GetKey();
		$oResolved = MetaModel::GetObject('UserRequest', $this->iResolved);
		$oResolved->Set('status', 'resolved');
		$oResolved->Set('resolution_code', 'other');
		$oResolved->Set('solution', 'Fixed by PortalWriteTest.');
		$oResolved->DBUpdate();
		$this->iOther = (int)$this->CreateUserRequest(3, [
			'org_id' => $this->iOrg, 'caller_id' => (int)$this->CreatePerson(2, $this->iOrg)->GetKey(),
		])->GetKey();

		$this->sLogin = 'portal-write-'.$sSuffix;
		$this->CreateUser($this->sLogin, self::profileId('Portal user'), 'Portal-Write-'.$sSuffix.'!', $this->iContact);

		UserRights::Login($this->sLogin);
		PortalMode::Remember(self::PORTAL);
	}

	protected function tearDown(): void
	{
		PortalMode::Forget();

		parent::tearDown();
	}

	public function testAReplyIsRehearsedFirstAndThenAddedToThePublicLog(): void
	{
		$aDry = ToolOutput::Decode(ObjectUpdate::execute('UserRequest', $this->iNew, ['public_log' => 'Any news?']));
		$this->assertTrue($aDry['simulated']);
		$this->assertArrayHasKey('public_log', (array)$aDry['changes']);
		$this->assertSame(0, $this->entriesIn($this->iNew), 'a dry run wrote');

		ToolOutput::Decode(ObjectUpdate::execute('UserRequest', $this->iNew, ['public_log' => 'Any news?'], false));
		$this->assertSame(1, $this->entriesIn($this->iNew));
	}

	/** Read-only in the portal's form, so not the user's to set, whatever UserRights says. */
	public function testAFieldThePortalShowsReadOnlyIsRefused(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('caller_id');
		ObjectUpdate::execute('UserRequest', $this->iNew, ['caller_id' => $this->iContact]);
	}

	public function testTheStateIsNotAFieldOfTheForm(): void
	{
		$this->expectException(ToolCallException::class);
		ObjectUpdate::execute('UserRequest', $this->iNew, ['status' => 'closed']);
	}

	public function testAnotherCallersRequestCannotBeChanged(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('not found');
		ObjectUpdate::execute('UserRequest', $this->iOther, ['public_log' => 'Hello']);
	}

	public function testANewRequestCannotBeClosed(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('does not offer');
		ObjectApplyStimulus::execute('UserRequest', $this->iNew, 'ev_close');
	}

	public function testAResolvedRequestCanBeClosed(): void
	{
		$aStimuli = array_column(ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iResolved))['object']['stimuli']['available'] ?? [], 'stimulus');
		if (!in_array('ev_close', $aStimuli, true)) {
			$this->markTestSkipped('This portal does not offer ev_close on a resolved request.');
		}

		$aFields = MetaModel::IsValidAttCode('UserRequest', 'user_satisfaction') ? ['user_satisfaction' => '1'] : [];
		$aDry = ToolOutput::Decode(ObjectApplyStimulus::execute('UserRequest', $this->iResolved, 'ev_close', $aFields));
		$this->assertTrue($aDry['simulated']);
		$this->assertSame('closed', $aDry['would_move_to']);
		$this->assertSame('resolved', MetaModel::GetObject('UserRequest', $this->iResolved)->Get('status'), 'a dry run moved it');

		ToolOutput::Decode(ObjectApplyStimulus::execute('UserRequest', $this->iResolved, 'ev_close', $aFields, false));
		$this->assertSame('closed', MetaModel::GetObject('UserRequest', $this->iResolved, true, true)->Get('status'));
	}

	/**
	 * The portal fills in the caller, the organisation and the service; the
	 * client sends only what the create form asks for.
	 */
	public function testARequestIsOpenedOnASubcategoryWithThePortalsRules(): void
	{
		$sAction = $this->subcategoryAction();

		$aDry = ToolOutput::Decode(ObjectCreate::execute(fields: ['title' => 'My screen is dark', 'description' => 'Since this morning.'], action: $sAction, from_id: $this->iSubcategory));
		$this->assertTrue($aDry['simulated']);
		$this->assertNull($aDry['id']);
		$aChanges = (array)$aDry['changes'];
		$this->assertSame($this->iContact, (int)$aChanges['caller_id'], 'the caller is the user');
		$this->assertSame($this->iOrg, (int)$aChanges['org_id'], 'the organisation is the user\'s');
		$this->assertSame($this->iService, (int)$aChanges['service_id'], 'the service is the subcategory\'s');
		$this->assertSame($this->iSubcategory, (int)$aChanges['servicesubcategory_id']);

		$aDone = ToolOutput::Decode(ObjectCreate::execute(fields: ['title' => 'My screen is dark', 'description' => 'Since this morning.'], simulate: false, action: $sAction, from_id: $this->iSubcategory));
		$this->assertFalse($aDone['simulated']);
		$this->assertContains((int)$aDone['id'], array_map(
			static fn (array $aRow): int => (int)$aRow['id'],
			ToolOutput::Decode(PortalList::execute('ongoing-tickets-for-portal-user'))['objects']
		), 'the new request is in the user\'s list');
	}

	/** The rules set the caller; the form does not offer it. */
	public function testACallerSuppliedOnCreationIsRefused(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('caller_id');
		ObjectCreate::execute(fields: ['title' => 'x', 'description' => 'y', 'caller_id' => 1], action: $this->subcategoryAction(), from_id: $this->iSubcategory);
	}

	/** A subcategory under another organisation's contract is not one the user could have picked. */
	public function testASubcategoryOutsideTheUsersContractsIsRefused(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('from_id');
		ObjectCreate::execute(fields: ['title' => 'x', 'description' => 'y'], action: $this->subcategoryAction(), from_id: $this->iForeignSubcategory);
	}

	public function testAnActionThePortalDoesNotOfferIsRefusedWithoutEcho(): void
	{
		try {
			ObjectCreate::execute(fields: ['title' => 'x'], action: "x' OR 1=1 --");
			$this->fail('an unknown action ran');
		} catch (ToolCallException $oException) {
			$this->assertStringNotContainsString('OR 1=1', $oException->getMessage());
		}
	}

	/** The subcategory level lists what the contract offers, and nothing else. */
	public function testTheCatalogueIsTheUsersContracts(): void
	{
		$sList = $this->subcategoryList();
		$aIds = array_map(static fn (array $aRow): int => (int)$aRow['id'], ToolOutput::Decode(PortalList::execute($sList, '', 0, '', 100))['objects']);
		$this->assertContains($this->iSubcategory, $aIds);
		$this->assertNotContains($this->iForeignSubcategory, $aIds);

		// And narrowed to its service, which is a row of the level above.
		$aUnder = ToolOutput::Decode(PortalList::execute($sList, '', $this->iService));
		$this->assertSame([$this->iSubcategory], array_map(static fn (array $aRow): int => (int)$aRow['id'], $aUnder['objects']));

		// One that is not a row of the level above lists nothing, whether it
		// exists or not.
		$this->assertSame(0, ToolOutput::Decode(PortalList::execute($sList, '', 999999999))['total']);
	}

	/**
	 * What a client that has never seen this portal can do from the
	 * description alone: take a create action, follow its from_list up the
	 * parents to the top, walk back down by parent_id, and create - no list
	 * or action id known in advance.
	 */
	public function testACreateActionCanBeReachedFromTheDescriptionAlone(): void
	{
		$aOverview = ToolOutput::Decode(ClassList::execute());
		$aLists = array_column($aOverview['lists'], null, 'list');
		$aAction = current(array_filter($aOverview['create_actions'], static fn (array $a): bool => $a['from_list'] !== null));
		$this->assertIsArray($aAction, 'a create action starts from a list');

		// Up: from the action's list to the top of its hierarchy.
		$aPath = [$aAction['from_list']];
		while (($sParent = $aLists[end($aPath)]['parent'] ?? null) !== null) {
			$this->assertArrayHasKey($sParent, $aLists, 'a parent is itself a described list');
			$aPath[] = $sParent;
		}

		// Down: the first row at the top that has a row under it at every level.
		$iFrom = $this->firstLeaf(array_reverse($aPath), 0);
		$this->assertNotNull($iFrom, 'the hierarchy leads down to a row the action starts from');

		$aDry = ToolOutput::Decode(ObjectCreate::execute(fields: ['title' => 'Found my way', 'description' => 'From the description alone.'], action: (string)$aAction['action'], from_id: $iFrom));
		$this->assertTrue($aDry['valid']);
	}

	/**
	 * What each portal write answers, as it leaves the server, against the
	 * outputSchema it declares - WriteResultSchemaTest's check, here because
	 * the calls need this test's user and catalogue. From mcp/sdk 0.9.0 the
	 * server replaces a result outside its schema with `isError: true` after
	 * the write has run, so a portal user would be told a reply, a closure or
	 * a new request failed when it went through. Dry runs and real writes both,
	 * since their results differ.
	 */
	public function testEveryWriteAnswersInsideItsOutputSchema(): void
	{
		$aReply = ['class' => 'UserRequest', 'id' => $this->iNew, 'fields' => ['public_log' => 'Any news?']];
		$this->assertConforms('core_portal_update', $aReply + ['simulate' => true]);
		$this->assertConforms('core_portal_update', $aReply + ['simulate' => false]);

		$aRequest = ['action' => $this->subcategoryAction(), 'fields' => ['title' => 'My screen is dark', 'description' => 'Since this morning.'], 'from_id' => $this->iSubcategory];
		$this->assertConforms('core_portal_create', $aRequest + ['simulate' => true]);
		$this->assertConforms('core_portal_create', $aRequest + ['simulate' => false]);

		$aStimuli = array_column(ToolOutput::Decode(PortalGet::execute('UserRequest', $this->iResolved))['actions']['stimuli'], 'code');
		if (!in_array('ev_close', $aStimuli, true)) {
			$this->markTestSkipped('This portal does not offer ev_close on a resolved request.');
		}
		$aFields = MetaModel::IsValidAttCode('UserRequest', 'user_satisfaction') ? ['user_satisfaction' => '1'] : [];
		$aClose = ['class' => 'UserRequest', 'id' => $this->iResolved, 'stimulus' => 'ev_close', 'fields' => (object)$aFields];
		$this->assertConforms('core_portal_apply_stimulus', $aClose + ['simulate' => true]);
		$this->assertConforms('core_portal_apply_stimulus', $aClose + ['simulate' => false]);
	}

	/**
	 * A row of the last list reached by walking the lists in order, each
	 * narrowed to a row of the one before; null when none leads down.
	 *
	 * @param array<int, string> $aPath Lists from the top down.
	 */
	private function firstLeaf(array $aPath, int $iParent): ?int
	{
		$sList = array_shift($aPath);
		foreach (ToolOutput::Decode(PortalList::execute($sList, '', $iParent, '', 100))['objects'] as $aRow) {
			$iFound = $aPath === [] ? (int)$aRow['id'] : $this->firstLeaf($aPath, (int)$aRow['id']);
			if ($iFound !== null) {
				return $iFound;
			}
		}

		return null;
	}

	/** The create action that starts from a subcategory, as the stock services brick declares it. */
	private function subcategoryAction(): string
	{
		foreach (ToolOutput::Decode(ClassList::execute())['create_actions'] as $aAction) {
			if ($aAction['from'] === 'ServiceSubcategory') {
				return (string)$aAction['action'];
			}
		}
		$this->markTestSkipped('The portal has no create action on a service subcategory.');
	}

	private function entriesIn(int $iId): int
	{
		return count(MetaModel::GetObject('UserRequest', $iId, true, true)->Get('public_log')->GetAsArray());
	}

	/** The browse level listing subcategories. */
	private function subcategoryList(): string
	{
		foreach (ToolOutput::Decode(ClassList::execute())['lists'] as $aList) {
			if ($aList['class'] === 'ServiceSubcategory') {
				return (string)$aList['list'];
			}
		}
		$this->markTestSkipped('The portal has no browse level on service subcategories.');
	}

	/**
	 * A family, a service in production with a subcategory, and a contract
	 * offering the service to a customer.
	 *
	 * @return array{0: int, 1: int} The service and the subcategory.
	 */
	private function catalogue(string $sName, int $iProvider, int $iCustomer): array
	{
		$iFamily = (int)$this->createObject('ServiceFamily', ['name' => 'Family '.$sName])->GetKey();
		$iService = (int)$this->createObject('Service', [
			'name' => 'Service '.$sName, 'org_id' => $iProvider, 'servicefamily_id' => $iFamily, 'status' => 'production',
		])->GetKey();
		$iSubcategory = (int)$this->createObject('ServiceSubcategory', [
			'name' => 'Subcategory '.$sName, 'service_id' => $iService, 'request_type' => 'service_request', 'status' => 'production',
		])->GetKey();
		$iContract = (int)$this->createObject('CustomerContract', [
			'name' => 'Contract '.$sName, 'org_id' => $iCustomer, 'provider_id' => $iProvider,
		])->GetKey();
		$this->createObject('lnkCustomerContractToService', ['customercontract_id' => $iContract, 'service_id' => $iService]);

		return [$iService, $iSubcategory];
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');
		$oProfile = (new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch();

		return (int)$oProfile->GetKey();
	}
}
