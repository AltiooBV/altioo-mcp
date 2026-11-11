<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * doc/adr/0013: an account holding an exempt profile is served what is excluded - by the account only, never in
 * portal mode, and a setting that cannot be honoured exempts nobody.
 */
class ExclusionExemptionTest extends ItopDataTestCaseAlias
{
	private const SENTINEL = '+3355507735';

	private string $sProfile;
	private int $iOrg;
	private int $iPerson;
	private int $iTeam;
	private string $sExempt;
	private string $sPlain;
	private string $sPortalUser;
	private int $iOwn = 0;

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Person', 'Team'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$sSuffix = bin2hex(random_bytes(4));
		$this->sProfile = 'MCP Exempt '.$sSuffix;
		$this->createObject('URP_Profiles', ['name' => $this->sProfile, 'description' => 'ExclusionExemptionTest']);

		$this->iOrg = (int)$this->CreateOrganization('Exempt '.$sSuffix)->GetKey();
		$oPerson = $this->CreatePerson(1, $this->iOrg);
		$oPerson->Set('phone', self::SENTINEL);
		$oPerson->DBUpdate();
		$this->iPerson = (int)$oPerson->GetKey();
		$this->iTeam = (int)$this->createObject('Team', ['name' => 'Exempt team '.$sSuffix, 'org_id' => $this->iOrg])->GetKey();

		$iAdmin = self::profileId('Administrator');
		$this->sExempt = 'exempt-'.$sSuffix;
		$this->sPlain = 'plain-'.$sSuffix;
		$iExemptUser = (int)$this->CreateUser($this->sExempt, $iAdmin, 'Exempt-'.$sSuffix.'-1!', $this->iPerson)->GetKey();
		$this->grant($iExemptUser, $this->sProfile);
		$this->CreateUser($this->sPlain, $iAdmin, 'Plain-'.$sSuffix.'-1!', (int)$this->CreatePerson(2, $this->iOrg)->GetKey());

		if (MetaModel::IsValidClass('UserRequest')) {
			$iContact = (int)$this->CreatePerson(3, $this->iOrg)->GetKey();
			$this->iOwn = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $iContact, 'description' => 'Exempt portal description'])->GetKey();
			$this->sPortalUser = 'portal-exempt-'.$sSuffix;
			// Not the custom profile: the portal refuses a user holding a profile its scopes do not list. The
			// stock 'Portal user' profile is the one a portal user does hold, so it is the one made exempt for this.
			$this->CreateUser($this->sPortalUser, self::profileId('Portal user'), 'Portal-Exempt-'.$sSuffix.'-1!', $iContact);
		}

		$this->configure(['Team'], ['Person' => ['phone'], 'UserRequest' => ['description']], [$this->sProfile]);
	}

	protected function tearDown(): void
	{
		UserRights::Deimpersonate();
		PortalMode::Forget();
		$this->configure([], [], []);
		parent::tearDown();
	}

	private function grant(int $iUser, string $sProfile): void
	{
		$this->createObject('URP_UserProfile', ['userid' => $iUser, 'profileid' => self::profileId($sProfile), 'reason' => 'ExclusionExemptionTest']);
	}

	private function configure(mixed $aClasses, mixed $aAttributes, mixed $aExempt): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_EXEMPT_PROFILES, $aExempt);
		ExclusionPolicy::Forget();
		PortalPolicy::Forget();
	}

	public function testAnExemptAccountIsServedWhatIsExcluded(): void
	{
		UserRights::Login($this->sExempt);

		$this->assertTrue(ExclusionPolicy::IsExempt());
		$this->assertStringContainsString(self::SENTINEL, json_encode(ToolOutput::Decode(ObjectGet::execute('Person', $this->iPerson, '*'))), 'the exempt account was not served the attribute');
		$this->assertSame($this->iTeam, ToolOutput::Decode(ObjectGet::execute('Team', $this->iTeam))['object']['id'], 'the exempt account was not served the class');
		$this->assertContains('Team', array_column(ToolOutput::Decode(ObjectSearchByClass::execute('Contact', [], 200))['objects'], 'class'));
	}

	public function testAnAccountThatIsNotExemptGetsTheAbsentAnswer(): void
	{
		UserRights::Login($this->sPlain);

		$this->assertFalse(ExclusionPolicy::IsExempt());
		$this->assertStringNotContainsString(self::SENTINEL, json_encode(ToolOutput::Decode(ObjectGet::execute('Person', $this->iPerson, '*'))));
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage("Unknown class 'Team'");
		ObjectGet::execute('Team', $this->iTeam);
	}

	/** The profile is the whole of it: an account is not exempt because it asks to be, or because of what it carries. */
	public function testNothingButTheAccountDecidesIt(): void
	{
		$sSource = (string)file_get_contents(dirname(__DIR__, 3).'/src/Service/Rights/ExclusionPolicy.php');
		foreach (['TokenScopes', 'MCPContext', 'clientInfo', 'OAuthServer', 'RequestCarriesAToken'] as $sNot) {
			$this->assertStringNotContainsString($sNot, $sSource, "the exemption reads {$sNot}, which the caller chooses");
		}

		UserRights::Login($this->sPlain);
		$this->assertFalse(ExclusionPolicy::IsExempt(), 'an account holding no exempt profile is exempt');
	}

	public function testAPortalUserHoldingTheProfileIsStillExcluded(): void
	{
		if ($this->iOwn === 0 || !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')) {
			$this->markTestSkipped('Needs request management and the stock portal.');
		}

		$this->configure(['Team'], ['Person' => ['phone'], 'UserRequest' => ['description']], ['Portal user']);
		UserRights::Login($this->sPortalUser);
		PortalMode::Remember('itop-portal');
		PortalPolicy::Forget();

		$this->assertFalse(ExclusionPolicy::IsExempt(), 'portal mode is never exempt, though the account holds a profile listed as exempt');
		$this->assertStringNotContainsString('Exempt portal description', json_encode(ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iOwn))), 'the excluded attribute reached a portal user');
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'not a list'              => ['MCP Exempt'],
			'a name no profile has'   => [['NoSuchProfileXyzzy']],
			'a number'                => [[3]],
			'an empty name'           => [['']],
		];
	}

	/**
	 * @dataProvider malformedProvider
	 */
	public function testASettingThatCannotBeHonouredExemptsNobodyAndStopsEveryone(mixed $mExempt): void
	{
		$this->configure(['Team'], ['Person' => ['phone']], $mExempt);
		UserRights::Login($this->sExempt);

		$this->assertTrue(Settings::AccessSettingsAreMalformed());
		$this->assertFalse(ExclusionPolicy::IsExempt(), 'an account was exempted by a setting that cannot be honoured');
		foreach (['objects', 'datamodel'] as $sToolset) {
			$this->assertFalse(MCPService::AccessPolicyOfCurrentRequest()->allowsToolset($sToolset), "{$sToolset} is served");
		}
	}

	public function testAnEmptySettingExemptsNobody(): void
	{
		$this->configure(['Team'], ['Person' => ['phone']], []);
		UserRights::Login($this->sExempt);

		$this->assertFalse(ExclusionPolicy::IsExempt());
		$this->assertStringNotContainsString(self::SENTINEL, json_encode(ToolOutput::Decode(ObjectGet::execute('Person', $this->iPerson, '*'))));
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
