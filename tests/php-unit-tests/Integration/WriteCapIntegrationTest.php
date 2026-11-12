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
use CMDBChange;
use CMDBObject;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * The write cap (ADR 0019) against a database: each committed call's audit row carries the objects its
 * change touched, and with a cap of 2 the third committed update in the hour is refused while a dry run
 * still answers. A call is logged the way the controller logs one, by CallLog::LogIfConfigured().
 */
class WriteCapIntegrationTest extends ItopDataTestCaseAlias
{
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
			$oResult = new MCPResult();
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
		$oRows = new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT AltiooEventMCPService WHERE written_objects > 0 AND mcp_name = "core_object_update"'));
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
		$oRow->Set('user_id', CMDBChange::GetCurrentUserId() + 100000);
		$oRow->Set('written_objects', 50);
		$oRow->DBInsertNoReload();

		$this->assertFalse(ToolOutput::Decode($this->update($iOrg, 'CapOther2', false))['simulated']);
	}
}
