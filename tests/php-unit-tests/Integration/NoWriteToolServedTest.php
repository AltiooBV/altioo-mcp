<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Core\ServerInstructions;
use Altioo\iTop\Extension\MCP\Helper\SettingNames;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C5: what decides that a connection is served no write tool, asked of the real registry and the
 * real policy. A token scoped to read is the scope's doing; an instance set read-only is the settings'; a token
 * with a write scope on an instance that serves writes is served one.
 */
class NoWriteToolServedTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		$iOrg = (int)$this->CreateOrganization('NoWrite '.bin2hex(random_bytes(4)))->GetKey();
		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'no-write-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'No-Write-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey());
		UserRights::Login($sLogin);
	}

	protected function tearDown(): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, SettingNames::MODULE_SETTING_READ_ONLY, false);
		AccessPolicy::Forget();
		ServedAccess::Forget();
		RequestScope::Close();
		parent::tearDown();
	}

	/** @param array<int, string> $aScopes */
	private function connectWithScopes(array $aScopes): void
	{
		AccessPolicy::Remember(ServedElements::InstancePolicy()->narrowedBy(AccessPolicy::FromScopes($aScopes)));
		ServedElements::RememberServed();
	}

	public function testAReadScopeIsWhatLeavesTheCredentialWithoutAWriteTool(): void
	{
		$this->connectWithScopes(['MCP-read']);

		$this->assertSame(ServedAccess::WRITES_BY_SCOPE, ServedAccess::WritesWithheldBy());
		$this->assertFalse(ServedAccess::ServesWrites());
		$this->assertContains('core_object_get', ServedAccess::Current()['tools']);
		$this->assertNotContains('core_object_create', ServedAccess::Current()['tools']);

		$sText = ServerInstructions::ForCaller(AccessPolicy::Current());
		$this->assertStringContainsString('grants no write', $sText);
		$this->assertStringContainsString('MCP-write', $sText);
	}

	public function testAWriteScopeIsServedAWriteTool(): void
	{
		foreach ([['MCP'], ['MCP-write'], ['MCP-delete']] as $aScopes) {
			$this->connectWithScopes($aScopes);
			$this->assertNull(ServedAccess::WritesWithheldBy(), implode(',', $aScopes));
			$this->assertStringNotContainsString("Access\n- No tool", ServerInstructions::ForCaller(AccessPolicy::Current()));
			ServedAccess::Forget();
		}
	}

	public function testAToolsetScopeWithoutTheObjectToolsIsTheScopesToo(): void
	{
		$this->connectWithScopes(['MCP-toolset-datamodel']);

		$this->assertSame(ServedAccess::WRITES_BY_SCOPE, ServedAccess::WritesWithheldBy());
	}

	public function testAnInstanceSetReadOnlyIsTheSettingsAndNeverTheScopes(): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, SettingNames::MODULE_SETTING_READ_ONLY, true);
		$this->connectWithScopes(['MCP']);

		$this->assertSame(ServedAccess::WRITES_BY_SETTINGS, ServedAccess::WritesWithheldBy());
		$sText = ServerInstructions::ForCaller(AccessPolicy::Current());
		$this->assertStringContainsString("server's settings", $sText);
		$this->assertStringNotContainsString('MCP-write', $sText, 'a scope cannot change what the settings forbid');
	}
}
