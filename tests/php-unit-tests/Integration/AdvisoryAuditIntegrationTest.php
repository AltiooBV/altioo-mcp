<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Controller\CallLog;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AltiooEventMCPService;
use CMDBObject;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * A write an advisory credential asked to commit is rehearsed, and the audit row says so: `simulate`
 * records what ran, not what was asked, so a review can tell a rehearsal from a write.
 */
class AdvisoryAuditIntegrationTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		$iOrg = (int)$this->CreateOrganization('AdvUsers '.bin2hex(random_bytes(4)))->GetKey();
		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'adv-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'Adv-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey());
		UserRights::Login($sLogin);
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	/** One call under $aScopes asking for $bSimulate, answered then audited as the controller does. @return array{0: array<string, mixed>, 1: AltiooEventMCPService} */
	private function update(array $aScopes, int $iOrg, string $sName, bool $bSimulate, bool $bOmitted = false): array
	{
		RequestScope::Close();
		CMDBObject::SetCurrentChange(null);
		ChangeTracking::ForRequest(null);
		AccessPolicy::Remember(AccessPolicy::FromScopes($aScopes));
		try {
			$aAnswer = ToolOutput::Decode($bOmitted ? ObjectUpdate::execute('Organization', $iOrg, ['name' => $sName]) : ObjectUpdate::execute('Organization', $iOrg, ['name' => $sName], $bSimulate));
		} finally {
			$oResult = new MCPResult(MCPResult::OK, 'tools/call answered');
			$oResult->mcpMethod = 'tools/call';
			$oResult->mcpName = 'core_object_update';
			$oResult->requestParams = json_encode(['name' => 'core_object_update', 'arguments' => $bOmitted ? new \stdClass() : ['simulate' => $bSimulate]]);
			CallLog::LogIfConfigured($oResult);
			CMDBObject::SetCurrentChange(null);
		}

		$oSet = new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT AltiooEventMCPService WHERE user_id = :user'), ['id' => true], ['user' => (int)UserRights::GetUserId()]);
		$oRow = null;
		while ($o = $oSet->Fetch()) {
			$oRow = $o;
		}

		return [$aAnswer, $oRow];
	}

	public function testAnAdvisoryTokenAskingToWriteIsRehearsedAndLoggedAsARehearsal(): void
	{
		$iOrg = (int)$this->CreateOrganization('Adv '.bin2hex(random_bytes(4)))->GetKey();
		$sBefore = MetaModel::GetObject('Organization', $iOrg)->Get('name');
		CMDBObject::SetCurrentChange(null);

		[$aAnswer, $oRow] = $this->update(['MCP-write', 'MCP-advisory'], $iOrg, 'Forced', false);

		$this->assertTrue($aAnswer['simulated']);
		$this->assertTrue($aAnswer['forced_by_credential']);
		$this->assertNotSame('', $aAnswer['forced_reason']);
		$this->assertSame($sBefore, MetaModel::GetObject('Organization', $iOrg)->Get('name'), 'the object was written');
		$this->assertSame('yes', $oRow->Get('simulate'));
		$this->assertSame(0, (int)$oRow->Get('written_objects'), 'a forced rehearsal must not count against the write cap');
	}

	public function testAnOrdinaryWriterIsStillLoggedAsAWrite(): void
	{
		$iOrg = (int)$this->CreateOrganization('Adv '.bin2hex(random_bytes(4)))->GetKey();
		CMDBObject::SetCurrentChange(null);

		[$aAnswer, $oRow] = $this->update(['MCP-write'], $iOrg, 'Written', false);

		$this->assertFalse($aAnswer['simulated']);
		$this->assertFalse($aAnswer['forced_by_credential']);
		$this->assertSame('no', $oRow->Get('simulate'));
		$this->assertSame(1, (int)$oRow->Get('written_objects'), 'a real write must still count');
	}

	public function testAWriteThatLeavesSimulateOutRehearsedAndIsLoggedYes(): void
	{
		$iOrg = (int)$this->CreateOrganization('Adv '.bin2hex(random_bytes(4)))->GetKey();
		CMDBObject::SetCurrentChange(null);

		[$aAnswer, $oRow] = $this->update(['MCP-write'], $iOrg, 'Omitted', true, true);

		$this->assertTrue($aAnswer['simulated'], 'the tool defaults to a rehearsal');
		$this->assertFalse($aAnswer['forced_by_credential'], 'the default is not the credential');
		$this->assertSame('yes', $oRow->Get('simulate'), 'the request carried no simulate, the tool rehearsed');
		$this->assertSame(0, (int)$oRow->Get('written_objects'));
	}
}
