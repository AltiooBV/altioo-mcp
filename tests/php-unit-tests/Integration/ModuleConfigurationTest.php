<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
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
	 * Read by MCPController::isMCPAccessRestricted(). A setting reachable in
	 * code but absent from module_parameters leaves admins no way to discover
	 * it, so this pins the declaration alongside the default.
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

	/**
	 * Read by MCPHelper::GetAllowedHosts(), which the endpoint consults before
	 * ResetSession() and which the SDK's DNS-rebinding middleware consults
	 * again. It was read in code without ever being declared, so an operator
	 * whose endpoint answered 403 had a parameter named in the log and nothing
	 * in config-itop.php to set.
	 *
	 * Must default to empty, which is not "no check": empty is what makes
	 * MCPHelper derive the list from app_root_url and the localhost variants.
	 */
	public function testAllowedHostsIsDeclaredAndDefaultsToEmpty(): void
	{
		$aHosts = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_ALLOWED_HOSTS, null);

		$this->assertIsArray($aHosts, 'mcp_allowed_hosts must be declared in module_parameters');
		$this->assertSame([], $aHosts, 'a shipped hostname would refuse every instance not served under it');
	}

	/**
	 * The connection record goes through the same allow-list as everything
	 * else, so an installed instance drops it unless the compiled datamodel
	 * names it - whatever DEFAULT_LOG_METHODS says.
	 */
	public function testTheConnectionMethodIsAudited(): void
	{
		$aLogged = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_METHOD, []);

		$this->assertContains(MCPHelper::MCP_METHOD_INITIALIZE, $aLogged);
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
	 * scope is an AttributeEnumSet, and GetAllowedValues() - what this test
	 * used to call - returns null for one. array_keys(null) is a TypeError, so
	 * this assertion could never have run against a real iTop; the same wrong
	 * accessor in TokenScopes silently pushed no tag for any scope but the
	 * base one, and every token scoped MCP-read was refused.
	 *
	 * @dataProvider tokenClassProvider
	 */
	public function testTokenScopeIsReadableAndNotEmpty(string $sClass): void
	{
		if (!MetaModel::IsValidClass($sClass)) {
			$this->markTestSkipped("{$sClass} is not present; the authent-token module is not installed.");
		}

		$mValues = MetaModel::GetAttributeDef($sClass, 'scope')->GetPossibleValues();

		$this->assertIsArray($mValues, "the scope values of {$sClass} could not be read");
		$this->assertNotEmpty($mValues);
	}

	/**
	 * What actually reaches the ContextTag stack before DoLogin() runs. iTop
	 * honours a token scope only when a tag of the same name was pushed, so a
	 * value this module's datamodel declares and this list omits is a token
	 * nobody can authenticate with - which was the state of all but one of
	 * them, on every instance, until the accessor was fixed.
	 *
	 * The expectation is read out of the datamodel rather than written here, so
	 * a scope added to the XML and not pushed fails this test rather than
	 * needing to be remembered.
	 */
	public function testEveryDeclaredScopeIsPushedAsAContextTag(): void
	{
		$aDeclared = self::declaredScopeValues();
		$this->assertGreaterThan(1, count($aDeclared), 'the datamodel declares no MCP scope beyond the base one');

		$aTags = TokenScopes::DeclaredContextTags();

		foreach ($aDeclared as $sScope) {
			$this->assertContains(
				$sScope,
				$aTags,
				"{$sScope} is declared in the datamodel but never pushed as a context tag, so no token holding it can log in"
			);
		}
	}

	/**
	 * The MCP scope values this module's own datamodel adds to PersonalToken.
	 *
	 * @return array<int, string>
	 */
	private static function declaredScopeValues(): array
	{
		$oXml = simplexml_load_file(__DIR__.'/../../../datamodel.altioo-mcp.xml');
		if ($oXml === false) {
			return [];
		}

		$aValues = [];
		foreach ($oXml->xpath('//class[@id="PersonalToken"]//field[@id="scope"]/values/value') ?: [] as $oValue) {
			$aValues[] = (string)$oValue['id'];
		}

		return $aValues;
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
	 * console renders raw keys like "Class:AltiooEventMCPService". Comparing the
	 * lookup against the key is the way to catch that.
	 *
	 * @dataProvider dictionaryKeyProvider
	 */
	public function testDictionaryEntryIsTranslated(string $sKey): void
	{
		$this->assertNotSame($sKey, Dict::S($sKey), "dictionary entry missing for '{$sKey}'");
	}

	/**
	 * Every key the datamodel obliges this module to translate, read out of the
	 * datamodel rather than listed here.
	 *
	 * It was a hand-written list of eleven, and it drifted: two of them named
	 * fieldsets that had been renamed, so the test failed on entries that were
	 * correctly absent, while nine attributes added since - including four added
	 * the same morning - were never checked at all. A list restating what another
	 * file declares is a list that goes stale, and this one went stale in both
	 * directions at once.
	 *
	 * The rule it encodes: a class this module *defines* owes a label for itself,
	 * for every attribute, for every enum value and for every fieldset in its
	 * presentation. A class it only *extends* owes labels for the values it adds
	 * and nothing else, because iTop labels the rest. Profiles owe their name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function dictionaryKeyProvider(): array
	{
		$oXml = simplexml_load_file(__DIR__.'/../../../datamodel.altioo-mcp.xml');
		$aKeys = [];

		foreach ($oXml->xpath('//class[@id]') ?: [] as $oClass) {
			$sClass = (string)$oClass['id'];
			$bDefined = (string)$oClass['_delta'] === 'define';

			if ($bDefined) {
				$aKeys[] = 'Class:'.$sClass;
			}

			foreach ($oClass->xpath('.//field[@id]') ?: [] as $oField) {
				$sField = (string)$oField['id'];
				if ($bDefined) {
					$aKeys[] = 'Class:'.$sClass.'/Attribute:'.$sField;
				}
				foreach ($oField->xpath('.//value[@id]') ?: [] as $oValue) {
					if ($bDefined || (string)$oValue['_delta'] === 'define') {
						$aKeys[] = 'Class:'.$sClass.'/Attribute:'.$sField.'/Value:'.(string)$oValue['id'];
					}
				}
			}

			foreach ($oClass->xpath('.//item[@id]') ?: [] as $oItem) {
				$sItem = (string)$oItem['id'];
				if (str_starts_with($sItem, 'fieldset:')) {
					$aKeys[] = $sItem;
				}
			}
		}

		foreach ($oXml->xpath('//profile/name') ?: [] as $oName) {
			$aKeys[] = 'Profile:'.(string)$oName;
		}

		$aKeys = array_values(array_unique($aKeys));
		sort($aKeys);

		self::assertNotEmpty($aKeys, 'no dictionary keys were derived; the datamodel scan has stopped working');

		return array_combine($aKeys, array_map(static fn (string $s): array => [$s], $aKeys));
	}

	/**
	 * The class label drives the console list header and the object title.
	 */
	public function testClassLabelIsNotTheRawClassName(): void
	{
		$this->assertSame('MCP Service Call', MetaModel::GetName('AltiooEventMCPService'));
	}
}
