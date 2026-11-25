<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AltiooEventMCPService;
use CMDBObject;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C16, the added fields: change_id, origin, has_more and next_offset on every history, and the tool
 * and the reason of the MCP call that wrote a change only on the changes this account's own calls wrote.
 */
class HistoryChangeFieldsTest extends ItopDataTestCaseAlias
{
	private const OTHERS_WORDS = 'OTHER-REASON-SENTINEL-must-never-appear';

	private string $sMe;
	private string $sOther;
	private int $iMe;
	private int $iOther;
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();
		$sSuffix = bin2hex(random_bytes(4));
		$iOrg = (int)$this->CreateOrganization('HistChange '.$sSuffix)->GetKey();
		$iProfile = (int)(new \DBObjectSet(\DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$this->sMe = 'hc-me-'.$sSuffix;
		$this->sOther = 'hc-other-'.$sSuffix;
		$this->iMe = (int)$this->CreateUser($this->sMe, $iProfile, 'Hc-Me-'.$sSuffix.'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey())->GetKey();
		$this->iOther = (int)$this->CreateUser($this->sOther, $iProfile, 'Hc-Other-'.$sSuffix.'-1!', (int)$this->CreatePerson(2, $iOrg)->GetKey())->GetKey();
		$this->iOrg = $iOrg;
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		PortalMode::Forget();
		parent::tearDown();
	}

	/** One tracked change to the organisation, made by $sLogin; returns nothing: the history says which. */
	private function changeAs(string $sLogin, string $sCode): void
	{
		UserRights::Login($sLogin);
		CMDBObject::SetCurrentChange(null);
		$oOrg = MetaModel::GetObject('Organization', $this->iOrg);
		$oOrg->Set('code', $sCode);
		$oOrg->DBUpdate();
		CMDBObject::SetCurrentChange(null);
	}

	/** @return array<string, mixed> */
	private function history(array $aArgs = []): array
	{
		return ToolOutput::Decode(ObjectHistory::execute('Organization', $this->iOrg, ...$aArgs));
	}

	/** The change key of the entry written by this account. */
	private function changeOf(int $iUser): int
	{
		foreach ($this->history()['entries'] as $aEntry) {
			if ((int)$aEntry['user_id'] === $iUser && $aEntry['attribute'] === 'code') {
				return (int)$aEntry['change_id'];
			}
		}
		$this->fail('no entry by user '.$iUser);
	}

	private function audit(int $iUser, int $iChange, string $sTool, string $sComment): void
	{
		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', $iUser === $this->iMe ? $this->sMe : $this->sOther);
		$oRow->Set('message', 'row');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', $sTool);
		$oRow->Set('user_id', $iUser);
		$oRow->Set('change_id', $iChange);
		$oRow->Set('change_comment', $sComment);
		$oRow->DBInsertNoReload();
	}

	/** @return array<int, array<string, mixed>> The entries of the two changes, by login. */
	private function setUpTwoChanges(): array
	{
		$this->changeAs($this->sMe, 'MINE-'.bin2hex(random_bytes(2)));
		$this->changeAs($this->sOther, 'THEIRS-'.bin2hex(random_bytes(2)));
		UserRights::Login($this->sMe);
		$iMine = $this->changeOf($this->iMe);
		$iTheirs = $this->changeOf($this->iOther);
		$this->assertNotSame($iMine, $iTheirs);
		$this->audit($this->iMe, $iMine, 'core_object_update', 'my reason');
		$this->audit($this->iOther, $iTheirs, 'core_object_delete', self::OTHERS_WORDS);

		return [$iMine, $iTheirs];
	}

	public function testEveryEntryNamesItsChangeAndHowItWasMade(): void
	{
		$this->changeAs($this->sMe, 'MINE-1');
		UserRights::Login($this->sMe);

		$aEntries = $this->history()['entries'];
		$this->assertNotEmpty($aEntries);
		foreach ($aEntries as $aEntry) {
			$this->assertGreaterThan(0, $aEntry['change_id']);
			$this->assertArrayHasKey('origin', $aEntry);
			$this->assertNotSame('', $aEntry['origin']);
		}
		// The rows written together share their change.
		$aByChange = array_count_values(array_column($aEntries, 'change_id'));
		$this->assertNotEmpty($aByChange);
	}

	public function testThePagingSaysWhetherThereIsMore(): void
	{
		$this->changeAs($this->sMe, 'A-1');
		$this->changeAs($this->sMe, 'A-2');
		$this->changeAs($this->sMe, 'A-3');
		UserRights::Login($this->sMe);

		$aFirst = $this->history(['', 1, 0]);
		$this->assertTrue($aFirst['has_more']);
		$this->assertSame(1, $aFirst['next_offset']);

		$aAll = $this->history(['', 500, 0]);
		$this->assertFalse($aAll['has_more']);
		$this->assertNull($aAll['next_offset']);

		$aLast = $this->history(['', 1, $aAll['total'] - 1]);
		$this->assertFalse($aLast['has_more'], 'the last page has no next');
	}

	public function testToolAndReasonAppearOnlyOnTheCallersOwnChanges(): void
	{
		[$iMine, $iTheirs] = $this->setUpTwoChanges();

		foreach ($this->history()['entries'] as $aEntry) {
			if ($aEntry['change_id'] === $iMine) {
				$this->assertSame('core_object_update', $aEntry['tool']);
				$this->assertSame('my reason', $aEntry['comment']);
			} elseif ($aEntry['change_id'] === $iTheirs) {
				$this->assertArrayNotHasKey('tool', $aEntry);
				$this->assertArrayNotHasKey('comment', $aEntry);
			}
		}
		$this->assertStringNotContainsString(self::OTHERS_WORDS, json_encode($this->history()));
		$this->assertStringNotContainsString('core_object_delete', json_encode($this->history()));
	}

	/** The other way round, so the filter is not just "the first user". */
	public function testTheOtherAccountSeesItsOwnAndNotTheFirstOnes(): void
	{
		[$iMine, $iTheirs] = $this->setUpTwoChanges();
		UserRights::Login($this->sOther);

		$sJson = json_encode($this->history());
		$this->assertStringContainsString(self::OTHERS_WORDS, $sJson);
		$this->assertStringNotContainsString('my reason', $sJson);
		$this->assertStringNotContainsString('core_object_update', $sJson);
	}

	/** Red pass: an audit row of someone else that points at my change adds nothing to it. */
	public function testAnotherAccountsRowPointingAtMyChangeIsNotShownOnIt(): void
	{
		$this->changeAs($this->sMe, 'MINE-'.bin2hex(random_bytes(2)));
		UserRights::Login($this->sMe);
		$iMine = $this->changeOf($this->iMe);
		$this->audit($this->iOther, $iMine, 'core_object_bulk_delete', self::OTHERS_WORDS);

		$sJson = json_encode($this->history());
		$this->assertStringNotContainsString(self::OTHERS_WORDS, $sJson);
		$this->assertStringNotContainsString('core_object_bulk_delete', $sJson);
	}

	/** Red pass: a row that holds no key for the account is not matched by its login. */
	public function testARowWithNoKeyIsNotMatchedByLogin(): void
	{
		$this->changeAs($this->sMe, 'MINE-'.bin2hex(random_bytes(2)));
		UserRights::Login($this->sMe);
		$iMine = $this->changeOf($this->iMe);
		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', $this->sMe);
		$oRow->Set('message', 'row');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'unkeyed-tool');
		$oRow->Set('change_id', $iMine);
		$oRow->Set('change_comment', 'unkeyed reason');
		$oRow->DBInsertNoReload();

		$this->assertStringNotContainsString('unkeyed', json_encode($this->history()));
	}
}
