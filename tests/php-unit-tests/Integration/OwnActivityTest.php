<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\MyActivity;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AltiooEventMCPService;
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
 * core_my_activity, on rows of the audit class in a live datamodel.
 *
 * The audit class is readable by no ordinary profile, so the tool reads it with
 * AllowAllData(); what makes that safe is what these ask: whose rows come
 * back, what is in them, and what a limited account is told about objects it
 * cannot see.
 */
class OwnActivityTest extends ItopDataTestCaseAlias
{
	private const SECRET_PARAMS = 'RAW-PARAMS-SENTINEL-jane.doe@example.com';
	private const SECRET_REFUSAL = 'REFUSAL-SENTINEL-quoted-third-party';

	private string $sSuffix;
	private int $iOrgA;
	private int $iOrgB;
	private int $iPersonA;
	private int $iPersonB;
	private int $iMe;
	private int $iOther;
	private string $sMe;
	private string $sOther;

	protected function setUp(): void
	{
		parent::setUp();
		$this->sSuffix = bin2hex(random_bytes(4));
		$this->iOrgA = (int)$this->CreateOrganization('OwnA '.$this->sSuffix)->GetKey();
		$this->iOrgB = (int)$this->CreateOrganization('OwnB '.$this->sSuffix)->GetKey();
		$this->iPersonA = (int)$this->CreatePerson(1, $this->iOrgA)->GetKey();
		$this->iPersonB = (int)$this->CreatePerson(2, $this->iOrgB)->GetKey();

		$this->sMe = 'own-me-'.$this->sSuffix;
		$this->sOther = 'own-other-'.$this->sSuffix;
		$this->iMe = (int)$this->CreateUser($this->sMe, self::profileId('Administrator'), 'Own-Me-'.$this->sSuffix.'!', $this->iPersonA)->GetKey();
		$this->iOther = (int)$this->CreateUser($this->sOther, self::profileId('Administrator'), 'Own-Other-'.$this->sSuffix.'!', $this->iPersonB)->GetKey();
	}

	protected function tearDown(): void
	{
		PortalMode::Forget();
		parent::tearDown();
	}

	/** @param array<string, mixed> $aValues */
	private function row(int $iUser, array $aValues = []): int
	{
		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', $iUser === $this->iMe ? $this->sMe : $this->sOther);
		$oRow->Set('message', 'row');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'core_object_get');
		if ($iUser > 0) {
			$oRow->Set('user_id', $iUser);
		}
		foreach ($aValues as $sAttCode => $mValue) {
			$oRow->Set($sAttCode, $mValue);
		}

		return (int)$oRow->DBInsertNoReload();
	}

	/** @return array<string, mixed> */
	private function mine(array $aArguments = []): array
	{
		return ToolOutput::Decode(MyActivity::execute(...$aArguments));
	}

	public function testOnlyTheCallersOwnRowsComeBack(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'mine-1']);
		$this->row($this->iOther, ['mcp_name' => 'theirs-1']);
		$this->row($this->iMe, ['mcp_name' => 'mine-2']);
		UserRights::Login($this->sMe);

		$aResult = $this->mine();

		$this->assertSame(2, $aResult['total']);
		$this->assertSame(['mine-2', 'mine-1'], array_column($aResult['activity'], 'name'), 'newest first, and nobody else\'s');
	}

	/** The other way round, so the filter is not just "the first user". */
	public function testTheOtherUserSeesTheirsAndNotMine(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'mine-1']);
		$this->row($this->iOther, ['mcp_name' => 'theirs-1']);
		UserRights::Login($this->sOther);

		$this->assertSame(['theirs-1'], array_column($this->mine()['activity'], 'name'));
	}

	/**
	 * A login is a string: it is rewritten on anonymisation and reused. A row
	 * that carries the caller's login and no key is not theirs to be shown.
	 */
	public function testARowWithNoKeyIsNotMatchedByLogin(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'keyed']);
		$this->row(0, ['mcp_name' => 'unkeyed', 'userinfo' => $this->sMe]);
		UserRights::Login($this->sMe);

		$this->assertSame(['keyed'], array_column($this->mine()['activity'], 'name'));
		$aNotes = implode(' ', $this->mine()['coverage']['notes']);
		$this->assertStringContainsString('never by login', $aNotes);
		$this->assertStringContainsString('hold no key', $aNotes);
	}

	/** Under impersonation the account that acted is the real one, as CMDBChange has it. */
	public function testImpersonationAsksForTheRealUsersRows(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'real-user']);
		$this->row($this->iOther, ['mcp_name' => 'impersonated-user']);
		UserRights::Login($this->sMe);
		if (!UserRights::Impersonate($this->sOther)) {
			$this->markTestSkipped('This iTop would not let the administrator impersonate.');
		}
		try {
			$this->assertSame(['real-user'], array_column($this->mine()['activity'], 'name'));
		} finally {
			UserRights::Impersonate('');
		}
	}

	/** There is nothing to pass: an id in the arguments is not an argument. */
	public function testAnIdInTheArgumentsChangesNothing(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'mine-1']);
		$this->row($this->iOther, ['mcp_name' => 'theirs-1']);
		UserRights::Login($this->sMe);

		foreach (['user_id', 'user', 'login', 'userinfo'] as $sName) {
			try {
				MyActivity::execute(...[$sName => $this->iOther]);
				$this->fail("{$sName} was accepted");
			} catch (\Error $oError) {
				$this->assertStringContainsString('Unknown named parameter', $oError->getMessage());
			}
		}
		$this->assertSame(['mine-1'], array_column($this->mine()['activity'], 'name'));
	}

	public function testRawParametersAndWhatAToolSaidAreNeverReturned(): void
	{
		$this->row($this->iMe, ['request_params' => self::SECRET_PARAMS, 'refusal_text' => self::SECRET_REFUSAL, 'error_ref' => 'ERRREF', 'message' => 'MESSAGE-SENTINEL']);
		UserRights::Login($this->sMe);

		$sJson = (string)json_encode($this->mine());

		foreach ([self::SECRET_PARAMS, self::SECRET_REFUSAL, 'ERRREF', 'MESSAGE-SENTINEL', $this->sMe] as $sForbidden) {
			$this->assertStringNotContainsString($sForbidden, $sJson, $sForbidden);
		}
	}

	public function testTheReasonIsReturnedAndMarkedAsText(): void
	{
		$this->row($this->iMe, ['change_comment' => 'Escalated at the user\'s request']);
		UserRights::Login($this->sMe);

		$aResult = $this->mine();

		$this->assertSame('Escalated at the user\'s request', $aResult['activity'][0]['reason']);
		$this->assertContains('activity[].reason', $aResult['untrusted_text']['fields']);
	}

	public function testWhatIsShownOfARowIsItsKindAndNotItsTokensId(): void
	{
		$this->row($this->iMe, ['simulate' => 'yes', 'status' => 'error', 'client' => 'Cursor 1.4']);
		UserRights::Login($this->sMe);

		$aRow = $this->mine()['activity'][0];

		$this->assertSame('yes', $aRow['dry_run']);
		$this->assertSame('error', $aRow['status']);
		$this->assertSame('Cursor 1.4', $aRow['client']);
		$this->assertNull($aRow['credential']);
		$this->assertArrayNotHasKey('personal_token_id', $aRow);
	}

	/** An object in an organisation the account cannot see is counted and not named. */
	public function testAnObjectTheAccountCannotSeeIsCountedAndNotNamed(): void
	{
		$iOwn = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrgA, 'caller_id' => $this->iPersonA, 'title' => 'OwnA '.$this->sSuffix])->GetKey();
		$iHidden = (int)$this->CreateUserRequest(2, ['org_id' => $this->iOrgB, 'caller_id' => $this->iPersonB, 'title' => 'OwnB '.$this->sSuffix])->GetKey();

		$sLogin = 'own-limited-'.$this->sSuffix;
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Support Agent'), 'Own-Limited-'.$this->sSuffix.'!', $this->iPersonA)->GetKey();
		$oLink = MetaModel::NewObject('URP_UserOrg');
		$oLink->Set('userid', $iUser);
		$oLink->Set('allowed_org_id', $this->iOrgA);
		$oLink->DBInsert();

		$this->row($iUser, [
			'changed_objects' => "UserRequest::{$iOwn} updated (title)\nUserRequest::{$iHidden} updated (title, status)",
			'read_objects'    => "UserRequest: {$iOwn}, {$iHidden}",
		]);
		UserRights::Login($sLogin);

		$aRow = $this->mine()['activity'][0];

		$this->assertSame([['class' => 'UserRequest', 'id' => $iOwn, 'verb' => 'updated', 'attributes' => ['title']]], $aRow['changed']);
		$this->assertSame([['class' => 'UserRequest', 'id' => $iOwn]], $aRow['read']);
		$this->assertSame(2, $aRow['objects_you_cannot_see'], 'the hidden one, once as changed and once as read');
		$this->assertStringNotContainsString((string)$iHidden, (string)json_encode($aRow), 'its id is not given');
	}

	/** A code names a field. One the account may not read is not learned from a list of what changed. */
	public function testAnAttributeCodeThatCannotBeReadIsNotListed(): void
	{
		$this->row($this->iMe, ['changed_objects' => "UserLocal::{$this->iMe} updated (login, password)"]);
		UserRights::Login($this->sMe);

		$aChanged = $this->mine()['activity'][0]['changed'];

		$this->assertSame(['login'], $aChanged[0]['attributes'], 'the password is a secret, and is not named');
	}

	public function testSincePagingAndTheOtherArgumentsAreChecked(): void
	{
		$this->row($this->iMe, ['mcp_name' => 'old', 'date' => '2020-01-01 00:00:00']);
		$this->row($this->iMe, ['mcp_name' => 'new']);
		UserRights::Login($this->sMe);

		$this->assertSame(['new'], array_column($this->mine(['since' => '2025-01-01'])['activity'], 'name'));
		$aPage = $this->mine(['limit' => 1]);
		$this->assertTrue($aPage['has_more']);
		$this->assertSame(1, $aPage['next_offset']);
		$this->assertSame(['old'], array_column($this->mine(['limit' => 1, 'offset' => 1])['activity'], 'name'));

		foreach ([['since' => 'not a date'], ['limit' => 0], ['limit' => 101], ['offset' => -1]] as $aBad) {
			try {
				$this->mine($aBad);
				$this->fail(json_encode($aBad).' was accepted');
			} catch (ToolCallException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testCoverageSaysWhatTheRecordCannotShow(): void
	{
		UserRights::Login($this->sMe);
		MetaModel::GetConfig()->SetModuleSetting('altioo-mcp', 'log_mcp_level', 'error');
		MetaModel::GetConfig()->SetModuleSetting('altioo-mcp', 'mcp_audit_retention_days', 30);

		$aCoverage = $this->mine()['coverage'];

		$this->assertSame('error', $aCoverage['level']);
		$this->assertSame(30, $aCoverage['retention_days']);
		$sNotes = implode(' ', $aCoverage['notes']);
		$this->assertStringContainsString('failures and connections only', $sNotes);
		$this->assertStringContainsString('30 days', $sNotes);
	}

	/** Not served to a portal user until doc/adr/0010 is decided. */
	public function testItIsNotServedInPortalMode(): void
	{
		$this->assertFalse(PortalMode::Serves(new MyActivity()));
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
