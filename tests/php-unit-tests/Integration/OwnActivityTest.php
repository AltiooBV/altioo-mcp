<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Core\Tools\MyActivity;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
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
		$this->iMe = (int)$this->CreateUser($this->sMe, self::profileId('Administrator'), 'Own-Me-'.$this->sSuffix.'-1!', $this->iPersonA)->GetKey();
		$this->iOther = (int)$this->CreateUser($this->sOther, self::profileId('Administrator'), 'Own-Other-'.$this->sSuffix.'-1!', $this->iPersonB)->GetKey();
	}

	protected function tearDown(): void
	{
		UserRights::Deimpersonate();
		PortalAccess::Leave();
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
			// Impersonate('') finds no user and does nothing, and Login() puts the session's
			// impersonation back for every test that follows: end it for real.
			UserRights::Deimpersonate();
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
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Support Agent'), 'Own-Limited-'.$this->sSuffix.'-1!', $this->iPersonA)->GetKey();
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
		$this->assertNotContains($iHidden, array_merge(array_column($aRow['changed'], 'id'), array_column($aRow['read'], 'id')), 'its id is not given');
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

	public function testAnUnsetLevelReadsAsInfo(): void
	{
		UserRights::Login($this->sMe);
		MetaModel::GetConfig()->SetModuleSetting('altioo-mcp', 'log_mcp_level', '');

		$this->assertSame('info', $this->mine()['coverage']['level']);
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

	/** Served to a portal user as the one exception of doc/adr/0010. */
	public function testItIsServedInPortalMode(): void
	{
		$this->assertTrue(PortalAccess::Serves(new MyActivity()));
	}

	private const PORTAL = 'itop-portal';

	/**
	 * @return array{login: string, user: int, contact: int, own: int, other: int, hidden: int}
	 */
	private function portalUser(): array
	{
		if (!MetaModel::IsValidClass('UserRequest') || !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')
			|| !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal and request management, iTop 3.2.2 or later.');
		}
		$iContact = (int)$this->CreatePerson(50, $this->iOrgA)->GetKey();
		$sLogin = 'own-portal-'.$this->sSuffix;
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Portal user'), 'Own-Portal-'.$this->sSuffix.'-1!', $iContact)->GetKey();
		$iOwn = (int)$this->CreateUserRequest(50, ['org_id' => $this->iOrgA, 'caller_id' => $iContact, 'title' => 'Own portal '.$this->sSuffix])->GetKey();
		// A colleague's request in the same organisation: the portal's scope hides it from this user.
		$iColleague = (int)$this->CreatePerson(51, $this->iOrgA)->GetKey();
		$iOther = (int)$this->CreateUserRequest(51, ['org_id' => $this->iOrgA, 'caller_id' => $iColleague, 'title' => 'Colleague '.$this->sSuffix])->GetKey();
		$iHidden = (int)$this->CreateUserRequest(52, ['org_id' => $this->iOrgB, 'caller_id' => $this->iPersonB, 'title' => 'Other org '.$this->sSuffix])->GetKey();

		return ['login' => $sLogin, 'user' => $iUser, 'contact' => $iContact, 'own' => $iOwn, 'other' => $iOther, 'hidden' => $iHidden];
	}

	public function testThePortalUserSeesTheirOwnRowsAndNotAnotherPortalUsers(): void
	{
		$aMe = $this->portalUser();
		$iSecond = (int)$this->CreateUser('own-portal2-'.$this->sSuffix, self::profileId('Portal user'), 'Own-Portal2-'.$this->sSuffix.'-1!', (int)$this->CreatePerson(53, $this->iOrgA)->GetKey())->GetKey();
		$this->row($aMe['user'], ['mcp_name' => 'portal-mine']);
		$this->row($iSecond, ['mcp_name' => 'portal-second']);
		UserRights::Login($aMe['login']);
		PortalAccess::Enter(self::PORTAL);

		$this->assertSame(['portal-mine'], array_column($this->mine()['activity'], 'name'));
	}

	/** The portal's scope, not UserRights: a colleague's request and another organisation's are counted and not named. */
	public function testAnObjectThePortalHidesIsCountedAndNeverNamed(): void
	{
		$aMe = $this->portalUser();
		$this->row($aMe['user'], [
			'changed_objects' => "UserRequest::{$aMe['own']} updated (title)\nUserRequest::{$aMe['other']} updated (title)\nUserRequest::{$aMe['hidden']} updated (title)",
			'read_objects'    => "UserRequest: {$aMe['own']}, {$aMe['other']}, {$aMe['hidden']}",
			'change_id'       => $this->aChangeId(),
		]);
		UserRights::Login($aMe['login']);
		PortalAccess::Enter(self::PORTAL);

		$aRow = $this->mine()['activity'][0];

		$this->assertSame([$aMe['own']], array_column($aRow['changed'], 'id'));
		$this->assertSame([$aMe['own']], array_column($aRow['read'], 'id'));
		$this->assertSame(4, $aRow['objects_you_cannot_see'], 'two hidden objects, each as changed and as read');
		$aNamed = array_merge(array_column($aRow['changed'], 'id'), array_column($aRow['read'], 'id'));
		$this->assertNotContains($aMe['other'], $aNamed);
		$this->assertNotContains($aMe['hidden'], $aNamed);
	}

	/** A code the portal's view form does not show is not named, and without the change none is. */
	public function testAFormHiddenCodeIsNeverNamedAndNoCodeSurvivesALostChange(): void
	{
		$aMe = $this->portalUser();
		$sChanged = "UserRequest::{$aMe['own']} updated (title, private_log, agent_id)";
		$this->row($aMe['user'], ['changed_objects' => $sChanged, 'change_id' => $this->aChangeId(), 'mcp_name' => 'with-change']);
		$this->row($aMe['user'], ['changed_objects' => $sChanged, 'mcp_name' => 'change-gone']);
		UserRights::Login($aMe['login']);
		PortalAccess::Enter(self::PORTAL);

		$aByName = [];
		foreach ($this->mine()['activity'] as $aRow) {
			$aByName[$aRow['name']] = $aRow;
		}

		$aShown = $aByName['with-change']['changed'][0]['attributes'];
		$this->assertContains('title', $aShown, 'the portal shows the title');
		$this->assertNotContains('private_log', $aShown, 'the agents log is not on the portal\'s view form');
		$this->assertNotContains('agent_id', $aShown);
		$this->assertSame([], $aByName['change-gone']['changed'][0]['attributes'], 'no change, no codes');
	}

	public function testThePortalIsToldNothingOfTheInstancesSetup(): void
	{
		$aMe = $this->portalUser();
		UserRights::Login($aMe['login']);
		PortalAccess::Enter(self::PORTAL);
		MetaModel::GetConfig()->SetModuleSetting('altioo-mcp', 'log_mcp_level', 'error');
		MetaModel::GetConfig()->SetModuleSetting('altioo-mcp', 'mcp_audit_retention_days', 30);

		$aCoverage = $this->mine()['coverage'];

		$this->assertSame(['notes'], array_keys($aCoverage));
		$sJson = (string)json_encode($aCoverage);
		foreach (['error', 'retention', '30', 'log_mcp', 'level'] as $sConfig) {
			$this->assertStringNotContainsString($sConfig, $sJson, $sConfig);
		}
		$this->assertStringContainsString('Some earlier calls may not be listed.', $sJson);
	}

	/** The name of a row is what this account itself sent, or a word of this module. */
	public function testTheNameOfARowIsTheCallersOwnRequest(): void
	{
		$aMe = $this->portalUser();
		$this->row($aMe['user'], ['mcp_method' => 'resources/read', 'mcp_name' => 'itop://core/object-document/UserRequest/'.$aMe['hidden'].'/screenshot']);
		$this->row($aMe['user'], ['mcp_method' => 'login', 'mcp_name' => 'portal_admission', 'status' => 'error']);
		UserRights::Login($aMe['login']);
		PortalAccess::Enter(self::PORTAL);

		$aNames = array_column($this->mine()['activity'], 'name');

		$this->assertContains('portal_admission', $aNames);
		// The resource URI is what this account asked for, so it quotes only what it already knew; the
		// tool does not decode it or look anything up through it.
		$this->assertContains('itop://core/object-document/UserRequest/'.$aMe['hidden'].'/screenshot', $aNames);
	}

	private function aChangeId(): int
	{
		$oChange = MetaModel::NewObject('CMDBChange');
		$oChange->Set('date', date('Y-m-d H:i:s'));
		$oChange->Set('userinfo', 'own activity test');

		return (int)$oChange->DBInsert();
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
