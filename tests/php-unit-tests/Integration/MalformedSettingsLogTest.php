<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * A malformed access list serves nothing (AccessPolicy::Nothing()) and says why in the log on every request, whether or not the
 * request ever builds the instructions: the readers that log are asked by the policy itself, before the answer is failed closed.
 */
class MalformedSettingsLogTest extends ItopDataTestCaseAlias
{
	private string $sLog = '';

	protected function setUp(): void
	{
		parent::setUp();
		$this->sLog = tempnam(sys_get_temp_dir(), 'mcplog');
		MCPLog::Enable($this->sLog);
	}

	protected function tearDown(): void
	{
		foreach ([Settings::MODULE_SETTING_CAPABILITIES, Settings::MODULE_SETTING_ENABLED_TOOLSETS] as $sSetting) {
			MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, $sSetting, []);
		}
		MCPLog::EnableForModule();
		@unlink($this->sLog);
		parent::tearDown();
	}

	/** @return array<string, array{0: string}> */
	public static function settings(): array
	{
		return ['mcp_capabilities' => [Settings::MODULE_SETTING_CAPABILITIES], 'mcp_enabled_toolsets' => [Settings::MODULE_SETTING_ENABLED_TOOLSETS]];
	}

	/** @dataProvider settings */
	public function testAMalformedListServesNothingAndIsLogged(string $sSetting): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, $sSetting, 'read');

		$oPolicy = ServedElements::AccessPolicyOfCurrentRequest();

		foreach (AccessPolicy::CAPABILITIES as $sGrade) {
			$this->assertFalse($oPolicy->allowsCapability($sGrade), $sGrade);
		}
		$this->assertFalse($oPolicy->allowsToolset('objects'));
		$this->assertStringContainsString("'{$sSetting}' should be an array", (string)file_get_contents($this->sLog));
		$this->assertStringContainsString('Nothing is served until it is corrected', (string)file_get_contents($this->sLog));
	}

	/** @dataProvider settings */
	public function testTheInstanceSplitFailsClosedToo(string $sSetting): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, $sSetting, 'read');

		$this->assertFalse(ServedElements::InstancePolicy()->allowsCapability(AccessPolicy::CAPABILITY_READ));
		$this->assertStringContainsString("'{$sSetting}' should be an array", (string)file_get_contents($this->sLog));
	}

	public function testAWellFormedInstanceLogsNothingOfTheKind(): void
	{
		ServedElements::AccessPolicyOfCurrentRequest();

		$this->assertStringNotContainsString('should be an array', (string)file_get_contents($this->sLog));
	}
}
