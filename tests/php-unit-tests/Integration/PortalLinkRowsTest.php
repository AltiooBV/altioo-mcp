<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;
use UserRights;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The rows of a link set in portal mode are the portal's rows (RT-9), and a
 * write of one keeps the rows the portal hides from the user (RT-10).
 *
 * `Portal user` reads every class through UserRights, so what hides another
 * organisation's people and devices from the portal is its scope, asked row by
 * row; and what the portal shows of a row is the list it declares for the
 * class, not every attribute. The scenarios are those of the review of
 * 2026-11-18 (RP-1, RP-2), on the stock portal.
 */
class PortalLinkRowsTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';

	private int $iOrg;

	private int $iForeignOrg;

	private int $iContact;

	/** A colleague, in the user's organisation: the portal shows them. */
	private int $iColleague;

	/** A person of another organisation, whom the portal does not show. */
	private int $iForeignPerson;

	/** A location of the user's organisation where all three work. */
	private int $iLocation;

	private int $iTicket;

	/** A family of two services, one the user's contracts cover and one they do not. */
	private int $iFamily;

	private string $sSuffix;

	protected function setUp(): void
	{
		parent::setUp();

		foreach (['UserRequest', 'Location', 'ServiceFamily', 'Server', 'lnkContactToTicket'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}
		if (!class_exists('Combodo\iTop\Portal\Brick\BrickCollection') || !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal on iTop 3.2.2 or later.');
		}

		$this->sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('LinkRows '.$this->sSuffix)->GetKey();
		$this->iForeignOrg = (int)$this->CreateOrganization('LinkRows foreign '.$this->sSuffix)->GetKey();

		$this->iLocation = (int)$this->createObject('Location', ['name' => 'LinkRows site '.$this->sSuffix, 'org_id' => $this->iOrg])->GetKey();
		$this->iContact = $this->person('Me', $this->iOrg, 'me');
		$this->iColleague = $this->person('Colleague', $this->iOrg, 'colleague');
		$this->iForeignPerson = $this->person('Foreign', $this->iForeignOrg, 'foreign');

		$this->iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->iContact])->GetKey();

		// What the user may not create themselves, made before they log in.
		$this->createObject('Server', [
			'name' => 'LinkRows server '.$this->sSuffix, 'org_id' => $this->iForeignOrg, 'location_id' => $this->iLocation,
			'serialnumber' => 'SECRET-SN-'.$this->sSuffix, 'status' => 'production',
		]);
		$iProvider = (int)$this->CreateOrganization('LinkRows provider '.$this->sSuffix)->GetKey();
		$this->iFamily = (int)$this->createObject('ServiceFamily', ['name' => 'LinkRows family '.$this->sSuffix])->GetKey();
		$iMine = (int)$this->createObject('Service', ['name' => 'Mine '.$this->sSuffix, 'org_id' => $iProvider, 'servicefamily_id' => $this->iFamily, 'status' => 'production'])->GetKey();
		$this->createObject('Service', ['name' => 'HIDDEN-SERVICE-'.$this->sSuffix, 'org_id' => $iProvider, 'servicefamily_id' => $this->iFamily, 'status' => 'production', 'description' => 'internal only']);
		$iContract = (int)$this->createObject('CustomerContract', ['name' => 'Contract '.$this->sSuffix, 'org_id' => $this->iOrg, 'provider_id' => $iProvider])->GetKey();
		$this->createObject('lnkCustomerContractToService', ['customercontract_id' => $iContract, 'service_id' => $iMine]);
		$oColleague = MetaModel::GetObject('Person', $this->iColleague, true, true);
		$oColleague->Set('picture', new \ormDocument(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'image/png', 'colleague.png'));
		$oColleague->DBUpdate();

		$sLogin = 'link-rows-'.$this->sSuffix;
		$this->CreateUser($sLogin, self::profileId('Portal user'), 'Link-Rows-'.$this->sSuffix.'-1!', $this->iContact);

		UserRights::Login($sLogin);
		RequestScope::EnterPortal(self::PORTAL);
	}

	protected function tearDown(): void
	{
		RequestScope::Close();

		parent::tearDown();
	}

	/**
	 * A location shared across organisations: the portal's "Contacts" table lists the colleague
	 * and not the foreign person, and only in the columns the portal lists for a contact.
	 */
	public function testALocationServesItsPeopleAsThePortalShowsThem(): void
	{
		$aRows = $this->rowsOf('Location', $this->iLocation, 'person_list');
		$sJson = json_encode($aRows, JSON_THROW_ON_ERROR);

		$aIds = array_map('intval', array_column($aRows, 'id'));
		$this->assertContains($this->iColleague, $aIds, 'the colleague is in the user\'s scope');
		$this->assertNotContains($this->iForeignPerson, $aIds, 'a person of another organisation is not');
		$this->assertStringNotContainsString('foreign-'.$this->sSuffix, $sJson, 'nothing of the foreign person leaks, whatever the key');

		// The stock portal lists status, organisation and function of a contact, beside the name.
		foreach ($aRows as $aRow) {
			$this->assertSame([], array_diff(array_keys($aRow), ['id', 'friendlyname', 'finalclass', 'status', 'org_id', 'function']), 'a row holds the portal\'s columns: '.json_encode(array_keys($aRow)));
		}
		foreach (['email', 'phone', 'mobile_phone', 'employee_number', 'notify', 'picture'] as $sHidden) {
			$this->assertStringNotContainsString('"'.$sHidden.'"', $sJson, "the portal's list of a contact does not hold {$sHidden}");
		}
		$this->assertStringNotContainsString('colleague-'.$this->sSuffix.'@example.com', $sJson, 'nor the colleague\'s own e-mail address');
	}

	/** The portal has no scope for servers, so a device of another organisation is not shown. */
	public function testADeviceThePortalHasNoScopeForIsNotServed(): void
	{
		$aRows = $this->rowsOf('Location', $this->iLocation, 'physicaldevice_list');

		$this->assertSame([], $aRows);
		$this->assertStringNotContainsString('SECRET-SN-'.$this->sSuffix, json_encode(ToolOutput::Decode(ObjectGet::execute('Location', $this->iLocation)), JSON_THROW_ON_ERROR));
	}

	/** A family lists its services; a service the user's contracts do not cover is not one the portal shows. */
	public function testAServiceOutsideTheContractsIsNotServed(): void
	{
		try {
			$aRows = $this->rowsOf('ServiceFamily', $this->iFamily, 'services_list');
		} catch (ToolCallException $oException) {
			$this->markTestSkipped('This portal does not let the user read a service family.');
		}

		$sJson = json_encode($aRows, JSON_THROW_ON_ERROR);
		$this->assertStringContainsString('Mine '.$this->sSuffix, $sJson);
		$this->assertStringNotContainsString('HIDDEN-SERVICE-'.$this->sSuffix, $sJson);
		$this->assertStringNotContainsString('internal only', $sJson);
	}

	/** An n-n set: the rows are the links whose remote person the portal shows, and the answer's total is of those. */
	public function testATicketServesItsContactsAsThePortalShowsThem(): void
	{
		$this->link($this->iTicket, $this->iColleague, 'mine-'.$this->sSuffix);
		$this->link($this->iTicket, $this->iForeignPerson, 'foreign-role-'.$this->sSuffix);

		$aRows = $this->rowsOf('UserRequest', $this->iTicket, 'contacts_list');
		$sJson = json_encode($aRows, JSON_THROW_ON_ERROR);

		$this->assertCount(1, $aRows, 'one link, the one to a person the portal shows');
		$this->assertSame($this->iColleague, (int)$aRows[0]['contact_id']);
		$this->assertArrayHasKey('contact_id_friendlyname', $aRows[0], 'the remote name, which is what the portal\'s table starts with');
		$this->assertStringNotContainsString('foreign-role-'.$this->sSuffix, $sJson);
	}

	/** A blob is delivered by core_object_get_document, which asks the gates a document is asked, never inlined in a row. */
	public function testNoBlobIsInlinedInARow(): void
	{
		$sJson = json_encode($this->rowsOf('Location', $this->iLocation, 'person_list'), JSON_THROW_ON_ERROR);

		$this->assertNotSame([], json_decode($sJson, true), 'the colleague has a row');
		$this->assertStringNotContainsString('picture', $sJson);
		$this->assertStringNotContainsString('iVBORw0KGgo', $sJson, 'the file\'s bytes are not in the answer');
		$this->assertStringNotContainsString('colleague.png', $sJson);
	}

	/**
	 * The stock portal lists no blob, so the test above cannot tell a column that was dropped from one that
	 * never was asked for. A portal whose list names the picture is asked here: the column builder is given it.
	 */
	public function testAColumnThatIsABlobIsNeverBuiltEvenWhenAListNamesIt(): void
	{
		$oColleague = MetaModel::GetObject('Person', $this->iColleague, true, true);
		$oAddColumn = new \ReflectionMethod(PortalAccess::class, 'addColumn');
		$oAccess = PortalAccess::ForCurrentRequest();

		$aRow = [];
		$oInstanceSet = null;
		$oAddColumn->invokeArgs($oAccess, [&$aRow, $oColleague, 'Person', 'picture', &$oInstanceSet]);
		$this->assertSame([], $aRow, 'a blob is not a column of a row');

		$oAddColumn->invokeArgs($oAccess, [&$aRow, $oColleague, 'Person', 'function', &$oInstanceSet]);
		$oAddColumn->invokeArgs($oAccess, [&$aRow, $oColleague, 'Person', 'org_name', &$oInstanceSet]);
		$this->assertSame(['function'], array_keys($aRow), 'a stored scalar is one, a field of another class is not');
	}

	/** What a write reports of a set is what a read reports: the same rows, so a dry run discloses no more. */
	public function testAWriteAnswerHoldsTheRowsThePortalShows(): void
	{
		$this->link($this->iTicket, $this->iForeignPerson, 'foreign-role-'.$this->sSuffix);

		$aDry = ToolOutput::Decode(ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => $this->iColleague, 'role' => 'mine']]]));

		$this->assertTrue($aDry['valid']);
		$sJson = json_encode($aDry, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('foreign-role-'.$this->sSuffix, $sJson, 'neither the changes nor what the ticket will hold name a row the portal hides');
	}

	/** RT-10: the portal's own form submits a delta and keeps what it hides; so does a rewrite of the set. */
	public function testAHiddenLinkSurvivesAnUpdateOfTheSet(): void
	{
		$this->link($this->iTicket, $this->iForeignPerson, 'foreign-role-'.$this->sSuffix);
		$this->link($this->iTicket, $this->iContact, 'old');

		$aDry = ToolOutput::Decode(ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => $this->iColleague, 'role' => 'new']]]));
		$this->assertTrue($aDry['simulated']);
		$this->assertEqualsCanonicalizing([$this->iForeignPerson, $this->iContact], $this->contactsOf($this->iTicket), 'a dry run wrote');

		$aDone = ToolOutput::Decode(ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => $this->iColleague, 'role' => 'new']]], false));
		$this->assertFalse($aDone['simulated']);

		$aNow = $this->contactsOf($this->iTicket);
		$this->assertContains($this->iForeignPerson, $aNow, 'the link the portal hides from the user is still there');
		$this->assertContains($this->iColleague, $aNow, 'the link they asked for is added');
		$this->assertNotContains($this->iContact, $aNow, 'the link they could see and left out is removed');

		$this->assertArrayHasKey('links_kept', $aDone, 'the answer says what became of the rows the user was not shown');
		$this->assertArrayHasKey('contacts_list', $aDone['links_kept']);
		$this->assertStringNotContainsString('foreign-role-'.$this->sSuffix, json_encode($aDone, JSON_THROW_ON_ERROR));
	}

	/** Emptying the set is what the portal's form does to every row it shows, and no more. */
	public function testEmptyingTheSetRemovesWhatIsShownAndKeepsWhatIsHidden(): void
	{
		$this->link($this->iTicket, $this->iForeignPerson, 'foreign-role-'.$this->sSuffix);
		$this->link($this->iTicket, $this->iContact, 'old');

		ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => []], false);

		$this->assertSame([$this->iForeignPerson], $this->contactsOf($this->iTicket));
	}

	/** Keeping a row is not a way to name one: a hidden person cannot be added, as before. */
	public function testAHiddenPersonCannotBeLinkedByTheCaller(): void
	{
		try {
			ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => $this->iForeignPerson]]], false);
			$this->fail('a person the portal does not show was linked');
		} catch (ToolCallException $oException) {
			$this->assertStringContainsString('does not show you', $oException->getMessage());
		}

		$this->assertSame([], $this->contactsOf($this->iTicket));
	}

	/** An ordinary update that does not touch the set leaves every row, hidden or not. */
	public function testAnUpdateOfAnotherFieldLeavesTheSetAlone(): void
	{
		$this->link($this->iTicket, $this->iForeignPerson, 'foreign-role-'.$this->sSuffix);
		$this->link($this->iTicket, $this->iColleague, 'mine');

		ObjectUpdate::execute('UserRequest', $this->iTicket, ['public_log' => 'Any news?'], false);

		$this->assertEqualsCanonicalizing([$this->iForeignPerson, $this->iColleague], $this->contactsOf($this->iTicket));
	}

	/** The console serves a link set as the datamodel renders it: every row, every attribute. Portal mode changes nothing there. */
	public function testTheConsoleKeepsRenderingASetWhole(): void
	{
		RequestScope::Close();
		$oLocation = MetaModel::GetObject('Location', $this->iLocation, true, true);
		$oAttDef = MetaModel::GetAttributeDef('Location', 'person_list');
		$this->assertNull(\Altioo\iTop\Extension\MCP\Service\Read\LinkedRows::Of($oLocation, $oAttDef), 'outside portal mode nothing narrows a set');

		$sJson = json_encode(ObjectSerializer::Value($oLocation, 'Location', 'person_list'), JSON_THROW_ON_ERROR);
		$this->assertStringContainsString('foreign-'.$this->sSuffix.'@example.com', $sJson, 'every row, with its e-mail address, as before');
	}

	/**
	 * @return array<int, array<string, mixed>> The rows of the set as `core_object_get` serves them to the portal user.
	 */
	private function rowsOf(string $sClass, int $iId, string $sAttCode): array
	{
		$aGot = ToolOutput::Decode(ObjectGet::execute($sClass, $iId, $sAttCode));
		$mRows = $aGot['object'][$sAttCode] ?? [];

		return isset($mRows['links']) ? $mRows['links'] : $mRows;
	}

	/** @return int ID of a person of the organisation, with every attribute the portal's list does not hold. */
	private function person(string $sName, int $iOrg, string $sWho): int
	{
		return (int)$this->createObject('Person', [
			'name' => $sName.' '.$this->sSuffix, 'first_name' => 'Link', 'org_id' => $iOrg, 'location_id' => $this->iLocation,
			'email' => $sWho.'-'.$this->sSuffix.'@example.com', 'phone' => '+3355500'.random_int(100, 999), 'employee_number' => 'EMP-'.$sWho.$this->sSuffix,
		])->GetKey();
	}

	private function link(int $iTicket, int $iContact, string $sRole): void
	{
		$this->createObject('lnkContactToTicket', ['ticket_id' => $iTicket, 'contact_id' => $iContact, 'role' => $sRole]);
	}

	/**
	 * The contacts a ticket is linked to, whatever the user is shown: read from the database.
	 *
	 * @return array<int, int>
	 */
	private function contactsOf(int $iTicket): array
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT lnkContactToTicket WHERE ticket_id = :ticket');
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, [], ['ticket' => $iTicket]);

		$aContacts = [];
		while ($oLink = $oSet->Fetch()) {
			$aContacts[] = (int)$oLink->Get('contact_id');
		}
		sort($aContacts);

		return $aContacts;
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');
		$oProfile = (new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch();

		return (int)$oProfile->GetKey();
	}
}
