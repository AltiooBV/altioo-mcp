<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Controller\CallLog;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AltiooEventMCPService;
use CMDBObject;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * The write cap (ADR 0019) against a database: each committed call's audit row carries the objects its
 * change touched, and with a cap of 2 the third committed update in the hour is refused while a dry run
 * still answers. A call is logged the way the controller logs one, by CallLog::LogIfConfigured().
 */
class WriteCapIntegrationTest extends ItopDataTestCaseAlias
{
	private int $iOtherUser = 0;

	protected function setUp(): void
	{
		parent::setUp();
		$iOrg = (int)$this->CreateOrganization('CapUsers '.bin2hex(random_bytes(4)))->GetKey();
		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'cap-a-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'Cap-A-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey());
		$this->iOtherUser = (int)$this->CreateUser('cap-b-'.bin2hex(random_bytes(3)), $iProfile, 'Cap-B-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(2, $iOrg)->GetKey())->GetKey();
		UserRights::Login($sLogin);
	}

	protected function tearDown(): void
	{
		$this->cap(0);
		RequestScope::Close();
		parent::tearDown();
	}

	private function cap(mixed $mValue): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_MAX_WRITES_PER_HOUR, $mValue);
	}

	/** One MCP call, answered, then audited as the controller does; each call is its own request. */
	private function call(callable $fn, string $sParams): mixed
	{
		RequestScope::Close();
		CMDBObject::SetCurrentChange(null);
		ChangeTracking::ForRequest(null);
		try {
			return $fn();
		} finally {
			$oResult = new MCPResult(MCPResult::OK, 'tools/call answered');
			$oResult->mcpMethod = 'tools/call';
			$oResult->mcpName = 'core_object_update';
			$oResult->requestParams = $sParams;
			CallLog::LogIfConfigured($oResult);
			CMDBObject::SetCurrentChange(null);
		}
	}

	private function update(int $iOrg, string $sName, bool $bSimulate): mixed
	{
		return $this->call(
			static fn () => ObjectUpdate::execute('Organization', $iOrg, ['name' => $sName], $bSimulate),
			json_encode(['arguments' => ['simulate' => $bSimulate]])
		);
	}

	public function testTheThirdCommittedUpdateInTheHourIsRefusedAndADryRunStillAnswers(): void
	{
		$this->cap(2);
		$sTag = bin2hex(random_bytes(4));
		$iOrg = (int)$this->CreateOrganization('Cap '.$sTag)->GetKey();
		// The fixture's own creation was not a call of this endpoint: no audit row, nothing counted.
		CMDBObject::SetCurrentChange(null);

		$this->assertFalse(ToolOutput::Decode($this->update($iOrg, "Cap1 $sTag", false))['simulated']);
		$this->assertFalse(ToolOutput::Decode($this->update($iOrg, "Cap2 $sTag", false))['simulated']);

		$aWritten = [];
		$oRows = new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT AltiooEventMCPService WHERE written_objects > 0 AND user_id = :user'), [], ['user' => (int)\CMDBChange::GetCurrentUserId()]);
		while ($oRow = $oRows->Fetch()) {
			$aWritten[] = (int)$oRow->Get('written_objects');
		}
		$this->assertSame([1, 1], $aWritten, 'each committed call recorded the one object its change touched');

		try {
			$this->update($iOrg, "Cap3 $sTag", false);
			$this->fail('the third committed update in the hour must be refused');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('write cap of 2', $e->getMessage());
			$this->assertStringContainsString('mcp_max_writes_per_hour', $e->getMessage());
			$this->assertStringContainsString('Room frees up in about', $e->getMessage());
		}
		$this->assertSame("Cap2 $sTag", MetaModel::GetObject('Organization', $iOrg)->Get('name'), 'the refused call wrote nothing');

		$aDry = ToolOutput::Decode($this->update($iOrg, "Cap4 $sTag", true));
		$this->assertTrue($aDry['simulated'], 'a dry run answers over the cap and does not count');
	}

	public function testABulkCallThatWouldPassTheCapIsRefusedWholeAndNothingIsWritten(): void
	{
		$this->cap(2);
		$sTag = bin2hex(random_bytes(4));
		$aIds = [];
		foreach ([1, 2, 3] as $i) {
			$aIds[] = (int)$this->CreateOrganization("Bulk$i $sTag")->GetKey();
		}
		CMDBObject::SetCurrentChange(null);

		try {
			$this->call(static fn () => ObjectBulkUpdate::execute('Organization', $aIds, ['code' => 'X'], false), '{}');
			$this->fail('three objects against a cap of two is refused before it starts');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('3 objects', $e->getMessage());
		}
		foreach ($aIds as $iId) {
			$this->assertNotSame('X', (string)MetaModel::GetObject('Organization', $iId)->Get('code'));
		}
	}

	public function testAMalformedSettingRefusesWritesAndNotDryRuns(): void
	{
		$iOrg = (int)$this->CreateOrganization('CapBad '.bin2hex(random_bytes(4)))->GetKey();
		CMDBObject::SetCurrentChange(null);
		$this->cap('2');

		try {
			$this->update($iOrg, 'CapBad2', false);
			$this->fail('a malformed cap is not read as off');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('not a non-negative integer', $e->getMessage());
		}
		$this->assertTrue(ToolOutput::Decode($this->update($iOrg, 'CapBad2', true))['simulated']);
	}

	public function testAnotherCredentialsRowsAreNotCounted(): void
	{
		$this->cap(1);
		$iOrg = (int)$this->CreateOrganization('CapOther '.bin2hex(random_bytes(4)))->GetKey();
		CMDBObject::SetCurrentChange(null);

		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', 'someone else');
		$oRow->Set('message', 'seeded');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'core_object_update');
		$oRow->Set('user_id', $this->iOtherUser);
		$oRow->Set('written_objects', 50);
		$oRow->DBInsertNoReload();

		$this->assertFalse(ToolOutput::Decode($this->update($iOrg, 'CapOther2', false))['simulated']);
	}

	/** @return array{0: int, 1: array<int, int>, 2: int} The organisation, some people, and a ticket */
	private function ticketWithPeople(int $iPeople): array
	{
		if (!MetaModel::IsValidClass('UserRequest') || !MetaModel::IsValidClass('lnkContactToTicket')) {
			$this->markTestSkipped('Needs request management.');
		}
		$iOrg = (int)$this->CreateOrganization('CapLinks '.bin2hex(random_bytes(4)))->GetKey();
		$aPeople = [];
		for ($i = 0; $i < $iPeople; $i++) {
			$aPeople[] = (int)$this->CreatePerson(30 + $i, $iOrg)->GetKey();
		}
		$iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $aPeople[0]])->GetKey();
		CMDBObject::SetCurrentChange(null);

		return [$iOrg, $aPeople, $iTicket];
	}

	/** @param array<int, int> $aPeople */
	private function entries(array $aPeople): array
	{
		return array_map(static fn (int $i): array => ['contact_id' => $i], $aPeople);
	}

	private function lastWritten(): int
	{
		$aRows = [];
		$oRows = new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT AltiooEventMCPService WHERE written_objects > 0 AND user_id = :user'), [], ['user' => (int)\CMDBChange::GetCurrentUserId()]);
		while ($oRow = $oRows->Fetch()) {
			$aRows[] = (int)$oRow->Get('written_objects');
		}

		return (int)end($aRows);
	}

	/**
	 * Review 2026-11-18, HA-8: link-set entries were counted by the cap only after the write, with no ceiling per call:
	 * with a cap of 3 and 2 spent, one create with 19 `contacts_list` entries committed 20 objects. ADR 0019 counts objects,
	 * not calls, and a link row is an object.
	 */
	public function testALinkSetsEntriesAreCountedBeforeTheWrite(): void
	{
		[, $aPeople, $iTicket] = $this->ticketWithPeople(6);
		$this->cap(3);

		try {
			$this->call(
				static fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['contacts_list' => array_map(static fn (int $i): array => ['contact_id' => $i], $aPeople)], false),
				json_encode(['arguments' => ['simulate' => false]])
			);
			$this->fail('six link rows and the ticket against a cap of three must be refused before anything is written');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('This call would write 7 objects', $e->getMessage());
		}
		$this->assertSame(0, (new DBObjectSet(DBObjectSearch::FromOQL('SELECT lnkContactToTicket WHERE ticket_id = :t'), [], ['t' => $iTicket]))->Count(), 'the refused call wrote nothing');
	}

	public function testTheRowsAnUpdateWouldAddFitTheCapAndAreCountedWhenWritten(): void
	{
		[, $aPeople, $iTicket] = $this->ticketWithPeople(3);
		$this->cap(3);

		// The ticket and two link rows: exactly the cap.
		$this->call(
			fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['contacts_list' => $this->entries([$aPeople[1], $aPeople[2]])], false),
			json_encode(['arguments' => ['simulate' => false]])
		);
		$this->assertSame(3, $this->lastWritten(), 'the audit row counts what the cap asked for');

		try {
			$this->call(
				fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['title' => 'one more'], false),
				json_encode(['arguments' => ['simulate' => false]])
			);
			$this->fail('the cap is spent');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('write cap of 3', $e->getMessage());
		}
	}

	public function testACompleteListThatRepeatsTheRowsAlreadyThereCountsNoneOfThem(): void
	{
		[, $aPeople, $iTicket] = $this->ticketWithPeople(4);
		// The rows are there already, put there by the fixture and not by a call that would be counted.
		foreach ([1, 2, 3] as $i) {
			$this->createObject('lnkContactToTicket', ['ticket_id' => $iTicket, 'contact_id' => $aPeople[$i]]);
		}
		CMDBObject::SetCurrentChange(null);
		$this->cap(3);
		CMDBObject::SetCurrentChange(null);

		// The same three and nothing else: a complete list is not three new rows.
		$aAnswer = ToolOutput::Decode($this->call(
			fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['title' => 'same rows', 'contacts_list' => $this->entries([$aPeople[1], $aPeople[2], $aPeople[3]])], false),
			json_encode(['arguments' => ['simulate' => false]])
		));
		$this->assertFalse($aAnswer['simulated'], 'rows already there were counted again');
		$this->assertSame(1, $this->lastWritten(), 'only the ticket was written');
	}

	public function testARowThatChangesAndARowThatGoesAreCounted(): void
	{
		[, $aPeople, $iTicket] = $this->ticketWithPeople(4);
		foreach ([1, 2, 3] as $i) {
			$this->createObject('lnkContactToTicket', ['ticket_id' => $iTicket, 'contact_id' => $aPeople[$i]]);
		}
		$this->cap(2);
		CMDBObject::SetCurrentChange(null);

		// One row dropped (the ticket and a removal: two) is within the cap; dropping two is not.
		try {
			$this->call(
				fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['contacts_list' => $this->entries([$aPeople[1]])], false),
				json_encode(['arguments' => ['simulate' => false]])
			);
			$this->fail('two removals and the ticket against a cap of two');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('This call would write 3 objects', $e->getMessage());
		}
		$this->call(
			fn () => ObjectUpdate::execute('UserRequest', $iTicket, ['contacts_list' => $this->entries([$aPeople[1], $aPeople[2]])], false),
			json_encode(['arguments' => ['simulate' => false]])
		);
		$this->assertSame(2, $this->lastWritten());
	}

	public function testACreateAndABulkCreateCountTheirLinkEntriesToo(): void
	{
		[$iOrg, $aPeople] = $this->ticketWithPeople(5);
		$this->cap(3);
		$aFields = ['org_id' => $iOrg, 'caller_id' => $aPeople[0], 'title' => 'cap create', 'description' => 'x', 'contacts_list' => $this->entries($aPeople)];

		try {
			$this->call(static fn () => \Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate::execute('UserRequest', $aFields, false), json_encode(['arguments' => ['simulate' => false]]));
			$this->fail('a ticket with five link rows against a cap of three');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('This call would write 6 objects', $e->getMessage());
		}

		try {
			$this->call(static fn () => \Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate::execute('UserRequest', [$aFields], false), '{}');
			$this->fail('a bulk create of a ticket with five link rows against a cap of three');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('This call would write 6 objects', $e->getMessage());
		}
	}
}
