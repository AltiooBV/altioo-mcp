<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Agreement between the code and the files that describe the module.
 *
 * These are facts stated twice or more - a version in three places, a default
 * written once in a constant and once in the XML the setup compiles - where
 * nothing at runtime notices when the copies drift. Reading them as text needs
 * neither iTop nor a database, so the check runs in the unit suite.
 */
class ModuleMetadataTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	private static function datamodel(): SimpleXMLElement
	{
		$oXml = simplexml_load_file(self::ROOT.'/datamodel.altioo-mcp.xml');
		self::assertNotFalse($oXml, 'datamodel.altioo-mcp.xml is not well-formed XML');

		return $oXml;
	}

	private static function moduleParameters(): SimpleXMLElement
	{
		$oParameters = self::datamodel()->module_parameters->parameters;
		self::assertNotNull($oParameters, 'no <parameters> block in the datamodel');

		return $oParameters;
	}

	/**
	 * MCPHelper::VERSION is what the server announces to clients in
	 * serverInfo; the other two are what the iTop setup reads. Nothing makes
	 * them agree by construction, so they are checked instead.
	 */
	public function testExtensionXmlCarriesTheSameVersionAsTheCode(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');
		$this->assertNotFalse($oXml, 'extension.xml is not well-formed XML');

		$this->assertSame(MCPHelper::VERSION, (string)$oXml->version);
	}

	public function testModuleDeclarationCarriesTheSameVersionAsTheCode(): void
	{
		$sSource = file_get_contents(self::ROOT.'/module.altioo-mcp.php');

		$this->assertSame(
			1,
			preg_match("/'".preg_quote(MCPHelper::MODULE_NAME, '/')."\/([0-9]+\.[0-9]+\.[0-9]+)'/", $sSource, $aMatch),
			'module.altioo-mcp.php does not declare a <module>/<x.y.z> id'
		);
		$this->assertSame(MCPHelper::VERSION, $aMatch[1]);
	}

	public function testVersionIsSemver(): void
	{
		$this->assertSame(1, preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', MCPHelper::VERSION));
	}

	/**
	 * The controller stamps this pseudo-method on the audit row when a request
	 * dies before its JSON-RPC method could be read. logIfConfigured() then
	 * drops anything not present in log_mcp_method - which is how exceptions
	 * came to be excluded from the audit trail while looking configured for.
	 */
	public function testTheExceptionPseudoMethodIsAuditedByDefault(): void
	{
		$aMethods = [];
		foreach (self::moduleParameters()->log_mcp_method->item as $oItem) {
			$aMethods[] = (string)$oItem;
		}

		$this->assertContains(MCPHelper::MCP_METHOD_EXCEPTION, $aMethods);
	}

	/**
	 * DEFAULT_LOG_SETTING is the fallback used when the parameter is absent
	 * from the config; it must not contradict the value the setup writes.
	 */
	public function testTheLoggingDefaultMatchesTheDatamodel(): void
	{
		$sDeclared = (string)self::moduleParameters()->log_mcp_service;

		$this->assertSame(MCPHelper::DEFAULT_LOG_SETTING, $sDeclared === 'true');
	}

	public function testTheDefaultLogLevelMatchesTheDatamodel(): void
	{
		$sDefault = '';
		foreach (self::moduleParameters()->log_mcp_level->value as $oValue) {
			if ((string)$oValue->attributes()->default === 'true') {
				$sDefault = (string)$oValue;
			}
		}

		$this->assertSame(MCPHelper::DEFAULT_LOG_LEVEL, $sDefault);
	}

	/**
	 * An operator switch nobody can discover is not a switch. Every setting the
	 * code reads has to be declared in module_parameters, where iTop's
	 * configuration editor shows it.
	 *
	 * @dataProvider settingProvider
	 */
	public function testSettingIsDeclaredInModuleParameters(string $sSetting): void
	{
		$this->assertTrue(
			isset(self::moduleParameters()->{$sSetting}),
			"the module reads '{$sSetting}' but never declares it in module_parameters"
		);
	}

	/** @return array<string, array{0: string}> */
	public static function settingProvider(): array
	{
		$aSettings = [
			'secure_mcp_services',
			'mcp_allowed_profiles',
			MCPHelper::MODULE_SETTING_ALLOWED_ORIGINS,
			MCPHelper::MODULE_SETTING_DISABLED,
			MCPHelper::MODULE_SETTING_LOG,
			MCPHelper::MODULE_SETTING_LOG_METHOD,
			MCPHelper::MODULE_SETTING_LOG_LEVEL,
		];

		return array_combine($aSettings, array_map(static fn (string $s): array => [$s], $aSettings));
	}

	/**
	 * The kill switch must start empty: a default that hides something would
	 * be a surprise, and the surprise would look like a missing tool.
	 */
	public function testTheKillSwitchIsEmptyByDefault(): void
	{
		$oDisabled = self::moduleParameters()->{MCPHelper::MODULE_SETTING_DISABLED};

		$this->assertSame('array', (string)$oDisabled->attributes()->type);
		$this->assertCount(0, $oDisabled->children());
	}
}
