<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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

	/**
	 * The README is the documentation shipped with the extension; an operator
	 * switch missing from it is undiscoverable in practice.
	 *
	 * @dataProvider settingProvider
	 */
	public function testSettingIsDocumentedInTheReadme(string $sSetting): void
	{
		$this->assertStringContainsString($sSetting, file_get_contents(self::ROOT.'/README.md'));
	}

	private static function moduleSource(): string
	{
		return file_get_contents(self::ROOT.'/module.altioo-mcp.php');
	}

	/** @return array<string, mixed> */
	private static function composer(): array
	{
		$aJson = json_decode(file_get_contents(self::ROOT.'/composer.json'), true);
		self::assertIsArray($aJson, 'composer.json is not valid JSON');

		return $aJson;
	}

	/** Lines of exclude.txt that name something, comments and blanks dropped. */
	private static function excludedFromPackage(): array
	{
		$aOut = [];
		foreach (file(self::ROOT.'/exclude.txt', FILE_IGNORE_NEW_LINES) as $sLine) {
			$sLine = trim($sLine);
			if ($sLine !== '' && strpos($sLine, '#') !== 0) {
				$aOut[] = $sLine;
			}
		}

		return $aOut;
	}

	/**
	 * The Hub renders more_info_url as the one link on the listing, and it is
	 * the only route a prospective user has to anything at all. Empty - or
	 * worse, a plausible URL nobody published - ends the evaluation there.
	 */
	public function testExtensionXmlDeclaresADocumentationUrl(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');
		$sUrl = (string)$oXml->more_info_url;

		$this->assertNotSame('', $sUrl, 'extension.xml declares no more_info_url');
		$this->assertSame(1, preg_match('|^https://|', $sUrl), 'more_info_url is not an https URL');
	}

	/**
	 * The description is the whole of what someone reads before deciding to
	 * download. "Add MCP to your iTop" was eleven words that answered none of
	 * the questions an administrator has about an HTTP endpoint an assistant
	 * drives, so the floor here is a length one cannot meet by accident.
	 */
	public function testExtensionXmlDescriptionCarriesTheListingCopy(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');
		$sDescription = (string)$oXml->description;

		$this->assertGreaterThan(200, strlen($sDescription), 'the Hub description is too short to decide anything from');
		$this->assertStringContainsString('iTop 3.2', $sDescription, 'the description does not state which iTop it runs on');
	}

	/**
	 * The two links iTop shows next to an installed module. They were empty
	 * strings, which is where an administrator inheriting the instance in a
	 * year would have looked first.
	 */
	public function testTheModuleDeclaresItsDocumentationLinks(): void
	{
		foreach (['doc.manual_setup', 'doc.more_information'] as $sKey) {
			$this->assertSame(
				1,
				preg_match("/'".preg_quote($sKey, '/')."'\s*=>\s*'https:\/\/[^']+'/", self::moduleSource()),
				"module.altioo-mcp.php leaves {$sKey} empty"
			);
		}
	}

	/**
	 * extension.xml has no itop_version_min, so the branch floor is declared
	 * through a core module that carries the version - itop-structure, which
	 * is mandatory in every installation. Without it the setup happily
	 * installs on 3.1 and the failure surfaces much later, as a fatal error
	 * inside a tool.
	 */
	public function testTheModuleDeclaresTheItopFloor(): void
	{
		$this->assertSame(
			1,
			preg_match("/'itop-structure\/3\.[2-9]\.[0-9]+'/", self::moduleSource()),
			'module.altioo-mcp.php does not depend on itop-structure, so nothing stops an install on iTop 3.1'
		);
	}

	/**
	 * The archive is what an instance still has in a year, when a link has
	 * rotted or the network is not available. Documentation excluded from it
	 * is documentation that instance does not have - and it also made
	 * testSettingIsDocumentedInTheReadme assert against a file the package did
	 * not carry.
	 */
	public function testTheDocumentationIsShippedInThePackage(): void
	{
		$aExcluded = self::excludedFromPackage();

		foreach (['README.md', 'SECURITY.md', 'CHANGELOG.md', 'LICENSE', 'doc', 'tests'] as $sPath) {
			$this->assertNotContains($sPath, $aExcluded, "exclude.txt keeps {$sPath} out of the release archive");
		}
	}

	/**
	 * The package declares one licence, in LICENSE, composer.json,
	 * extension.xml and the README. AGENTS.md and doc/itop-branch-notes.md are
	 * Creative Commons, and they shipped by omission: exclude.txt reasoned
	 * about README.md, doc/, tests/ and tools/ and never about them. A
	 * differently-licensed file landing on a customer instance inside a
	 * single-licence package is a provenance claim the package cannot support.
	 *
	 * Written as "no file the archive carries names that licence" rather than
	 * as a list of the two, so that a third one added later is caught here
	 * instead of by whoever reads the zip.
	 */
	public function testNothingCarryingAnotherLicenceReachesThePackage(): void
	{
		// Assembled rather than written out, so that this file - which has to
		// name the licence to explain itself - does not match its own needle.
		$sNeedle = 'CC BY'.'-SA';
		$aExcluded = self::excludedFromPackage();
		$aOffenders = [];

		$oIt = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(realpath(self::ROOT), RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($oIt as $oFile) {
			$sPath = ltrim(str_replace(realpath(self::ROOT), '', $oFile->getPathname()), '/');
			if (!in_array(pathinfo($sPath, PATHINFO_EXTENSION), ['md', 'php', 'xml', 'txt'], true)) {
				continue;
			}
			// Directories rsync is told to drop whole, and the vendored tree,
			// whose licences are inventoried in licenses.json instead.
			foreach (['vendor/', 'build/', '.git/', 'tools/', '.github/', '.phpunit.cache/'] as $sPrefix) {
				if (str_starts_with($sPath, $sPrefix)) {
					continue 2;
				}
			}
			if (in_array($sPath, $aExcluded, true)) {
				continue;
			}
			if (str_contains(file_get_contents($oFile->getPathname()), $sNeedle)) {
				$aOffenders[] = $sPath;
			}
		}
		sort($aOffenders);

		$this->assertSame(
			[],
			$aOffenders,
			'these ship in the archive and are not '.MCPHelper::LICENSE.'; list them in exclude.txt: '.implode(', ', $aOffenders)
		);
	}

	/**
	 * A version that says 1.0.0 while the changelog says everything is
	 * unreleased reads as "not released yet", whatever the version claims.
	 */
	public function testTheChangelogDocumentsTheCurrentVersion(): void
	{
		$this->assertStringContainsString(
			'## ['.MCPHelper::VERSION.']',
			file_get_contents(self::ROOT.'/CHANGELOG.md'),
			'CHANGELOG.md has no entry for the version the module declares'
		);
	}

	/**
	 * The project URL is written in five places - the Hub link, two composer
	 * fields, the two module documentation links and the README - and they are
	 * edited at different times. One of them left pointing at a URL nobody
	 * published is the failure this catches.
	 */
	public function testEveryProjectLinkPointsAtTheSamePlace(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');
		$sUrl = rtrim((string)$oXml->more_info_url, '/');
		$aComposer = self::composer();

		$this->assertSame($sUrl, rtrim($aComposer['homepage'] ?? '', '/'), 'composer.json homepage disagrees with extension.xml');
		$this->assertSame($sUrl, rtrim($aComposer['support']['source'] ?? '', '/'), 'composer.json support.source disagrees with extension.xml');

		foreach (['issues', 'docs', 'security'] as $sKey) {
			$this->assertStringStartsWith($sUrl, $aComposer['support'][$sKey] ?? '', "composer.json support.{$sKey} points elsewhere");
		}

		$this->assertStringContainsString($sUrl, self::moduleSource(), 'the module documentation links point elsewhere');
		$this->assertStringContainsString($sUrl, file_get_contents(self::ROOT.'/README.md'), 'the README does not link the project');
	}

	/**
	 * SOURCE_URL is served to remote callers as the AGPL 13 source offer, so
	 * it is not merely another copy of the project link: it is the one a
	 * licence obligation is discharged through. A typo here is answered by
	 * "the offer pointed nowhere", which is the same as no offer.
	 */
	public function testTheSourceOfferPointsAtTheProject(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');

		$this->assertSame(
			rtrim((string)$oXml->more_info_url, '/'),
			rtrim(MCPHelper::SOURCE_URL, '/'),
			'MCPHelper::SOURCE_URL disagrees with extension.xml more_info_url'
		);
	}

	/**
	 * The licence is stated in composer.json, in the header of every source
	 * file, and now in a constant a client reads over the wire. The constant
	 * is the one nobody re-reads, so it is the one that drifts.
	 */
	public function testTheAnnouncedLicenceIsTheDeclaredOne(): void
	{
		$aComposer = self::composer();

		$this->assertSame($aComposer['license'] ?? '', MCPHelper::LICENSE);
	}

	/**
	 * An operator running a modified copy has to be able to redirect the offer
	 * at their own source. A constant they cannot override is a compliance
	 * problem they cannot fix, so the parameter has to exist where the setup
	 * writes the configuration - not only in the code that reads it.
	 */
	public function testTheSourceUrlOverrideIsDeclaredWhereAnOperatorCanSetIt(): void
	{
		$this->assertObjectHasProperty(
			MCPHelper::MODULE_SETTING_SOURCE_URL,
			self::moduleParameters(),
			MCPHelper::MODULE_SETTING_SOURCE_URL.' is read by the code but absent from the datamodel'
		);
	}
}
