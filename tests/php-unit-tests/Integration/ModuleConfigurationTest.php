<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Dict;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Everything the module contributes to a compiled datamodel that is not a
 * class: module parameters, the gate profile, token scopes, and dictionary
 * entries.
 */
class ModuleConfigurationTest extends ItopDataTestCaseAlias
{
	/**
	 * Read by MCPController::isMCPAccessRestricted(). It was reachable in code
	 * long before it was declared, which left admins with no way to discover it.
	 */
	public function testSecureMcpServicesIsDeclaredAndDefaultsToOn(): void
	{
		$this->assertTrue(
			MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, 'secure_mcp_services', null),
			'secure_mcp_services must be declared in module_parameters and default to true'
		);
	}

	public function testAllowedProfilesIsAnArrayContainingTheGateProfile(): void
	{
		$aProfiles = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, 'mcp_allowed_profiles', null);

		$this->assertIsArray($aProfiles);
		$this->assertContains('Administrator', $aProfiles);
		$this->assertContains('MCP Services User', $aProfiles);
	}

	/**
	 * Read by MCPController::addCorsHeader(). Must default to an empty list: a
	 * populated default, and above all a "*", would let any site read the
	 * authenticated responses of a logged-in user's browser session.
	 */
	public function testAllowedOriginsIsDeclaredAndDefaultsToEmpty(): void
	{
		$aOrigins = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_ALLOWED_ORIGINS, null);

		$this->assertIsArray($aOrigins, 'mcp_allowed_origins must be declared in module_parameters');
		$this->assertSame([], $aOrigins);
		$this->assertNotContains('*', $aOrigins);
	}

	public function testLoggingSettingsAreDeclared(): void
	{
		$this->assertIsBool(MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG, null));
		$this->assertIsArray(MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_METHOD, null));
	}

	/**
	 * MCPController::logIfConfigured() only writes an audit row when the method
	 * appears in this list, so the methods it extracts must be in it.
	 */
	public function testLoggedMethodsCoverTheMethodsTheControllerExtracts(): void
	{
		$aLogged = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_METHOD, []);

		foreach (['tools/call', 'resources/read', 'prompts/get'] as $sMethod) {
			$this->assertContains($sMethod, $aLogged);
		}
	}

	/**
	 * A request that dies before its JSON-RPC method could be read is audited
	 * under this pseudo-method. It goes through the very same allow-list, so a
	 * constant that is not in the list means exceptions are never audited -
	 * the case where an audit trail is worth the most.
	 */
	public function testTheExceptionPseudoMethodIsAudited(): void
	{
		$aLogged = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_METHOD, []);

		$this->assertContains(MCPHelper::MCP_METHOD_EXCEPTION, $aLogged);
	}

	/**
	 * Read by MCPService when building the server. Must default to empty: a
	 * kill switch that hides something out of the box would look like a bug.
	 */
	public function testDisabledToolsIsDeclaredAndDefaultsToEmpty(): void
	{
		$aDisabled = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_DISABLED, null);

		$this->assertIsArray($aDisabled, 'mcp_disabled_tools must be declared in module_parameters');
		$this->assertSame([], $aDisabled);
	}

	/**
	 * The fallback baked into the code must not contradict the value the setup
	 * compiles, or the module behaves differently depending on whether the
	 * parameter survived a config edit.
	 */
	public function testTheLoggingDefaultAgreesWithTheCompiledDatamodel(): void
	{
		$this->assertSame(
			MCPHelper::DEFAULT_LOG_SETTING,
			MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG, null)
		);
	}

	/**
	 * The message raised on EXIT_CODE_NOTAUTHORIZED names this profile; it has
	 * to exist for that message to mean anything.
	 */
	public function testMcpServicesUserProfileExists(): void
	{
		$oSearch = DBObjectSearch::FromOQL('SELECT URP_Profiles WHERE name = :name');
		$oSet = new DBObjectSet($oSearch, [], ['name' => 'MCP Services User']);

		$this->assertSame(1, $oSet->Count(), 'the MCP Services User profile is missing from the compiled datamodel');
	}

	/**
	 * @dataProvider tokenClassProvider
	 */
	public function testTokenScopeOffersMcp(string $sClass): void
	{
		if (!MetaModel::IsValidClass($sClass)) {
			$this->markTestSkipped("{$sClass} is not present; the authent-token module is not installed.");
		}

		$aValues = array_keys(MetaModel::GetAttributeDef($sClass, 'scope')->GetAllowedValues());

		$this->assertContains('MCP', $aValues);
	}

	/** @return array<string, array{0: string}> */
	public static function tokenClassProvider(): array
	{
		return [
			'PersonalToken' => ['PersonalToken'],
			'UserToken' => ['UserToken'],
		];
	}

	/**
	 * Dict::S() returns the key itself when a translation is missing, so the
	 * console renders raw keys like "Class:EventMCPService". Comparing the
	 * lookup against the key is the way to catch that.
	 *
	 * @dataProvider dictionaryKeyProvider
	 */
	public function testDictionaryEntryIsTranslated(string $sKey): void
	{
		$this->assertNotSame($sKey, Dict::S($sKey), "dictionary entry missing for '{$sKey}'");
	}

	/** @return array<string, array{0: string}> */
	public static function dictionaryKeyProvider(): array
	{
		$aKeys = [
			'Class:EventMCPService',
			'Class:EventMCPService/Attribute:mcp_method',
			'Class:EventMCPService/Attribute:mcp_name',
			'Class:EventMCPService/Attribute:status',
			'Class:EventMCPService/Attribute:status/Value:success',
			'Class:EventMCPService/Attribute:status/Value:error',
			'Class:EventMCPService/Attribute:request_params',
			'fieldset:EventMCPService:main',
			'fieldset:EventMCPService:details',
			'Class:PersonalToken/Attribute:scope/Value:MCP',
			'Class:UserToken/Attribute:scope/Value:MCP',
		];

		return array_combine($aKeys, array_map(static fn (string $s): array => [$s], $aKeys));
	}

	/**
	 * The class label drives the console list header and the object title.
	 */
	public function testClassLabelIsNotTheRawClassName(): void
	{
		$this->assertSame('MCP Service Call', MetaModel::GetName('EventMCPService'));
	}
}
