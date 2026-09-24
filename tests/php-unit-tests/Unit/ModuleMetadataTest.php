<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClassConstant;
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
	 * The one setting whose compiled-in fallback is not the same value as the
	 * shipped one would lock the endpoint against everybody, Administrator
	 * included, on an instance whose configuration block went missing - and
	 * with secure_mcp_services independently defaulting to true, nothing would
	 * say why. The two copies are a comment away from each other in the
	 * source, which is exactly the kind of pair that drifts.
	 */
	public function testTheProfileFallbackIsWhatTheDatamodelShips(): void
	{
		$aShipped = [];
		foreach (self::moduleParameters()->mcp_allowed_profiles->item as $oItem) {
			$aShipped[] = (string)$oItem;
		}

		$oConstant = new ReflectionClassConstant(MCPController::class, 'DEFAULT_ALLOWED_PROFILES');

		$this->assertSame(
			$aShipped,
			$oConstant->getValue(),
			'MCPController::DEFAULT_ALLOWED_PROFILES no longer matches mcp_allowed_profiles in the datamodel'
		);
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

	/**
	 * Every setting the module actually reads, taken from the calls that read
	 * them.
	 *
	 * This was a hand-written list of seven, and the module had grown to
	 * fifteen: mcp_read_only, mcp_allowed_hosts, mcp_capabilities,
	 * mcp_enabled_toolsets, mcp_max_document_bytes, mcp_pagination_limit,
	 * mcp_protected_resource_metadata and mcp_source_url were declared and
	 * documented, and both guards above walked straight past them. A list
	 * maintained by hand guards whatever it happened to contain when it was
	 * last edited, which is not the same set as "what the code reads".
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function settingProvider(): array
	{
		$aConstants = [];
		$aSettings = [];

		foreach (self::sourceFiles() as $sPath) {
			$sSource = file_get_contents($sPath);
			preg_match_all('/const\s+(\w+)\s*=\s*\'([^\']+)\'/', $sSource, $aConst, PREG_SET_ORDER);
			foreach ($aConst as $aMatch) {
				$aConstants[$aMatch[1]] = $aMatch[2];
			}
		}

		foreach (self::sourceFiles() as $sPath) {
			$sSource = file_get_contents($sPath);
			preg_match_all('/GetModuleSetting\(\s*[^,]+,\s*([^,)]+)/', $sSource, $aCall, PREG_SET_ORDER);
			foreach ($aCall as $aMatch) {
				$sArgument = trim($aMatch[1]);
				if (preg_match('/^\'([^\']+)\'$/', $sArgument, $aLiteral)) {
					$aSettings[$aLiteral[1]] = true;
					continue;
				}
				if (preg_match('/::(\w+)$/', $sArgument, $aConstant) && isset($aConstants[$aConstant[1]])) {
					$aSettings[$aConstants[$aConstant[1]]] = true;
				}
			}
		}

		$aNames = array_keys($aSettings);
		sort($aNames);

		self::assertGreaterThan(10, count($aNames), 'no settings were found in the source; the scan above has stopped working');

		return array_combine($aNames, array_map(static fn (string $s): array => [$s], $aNames));
	}

	/** @return array<int, string> */
	private static function sourceFiles(): array
	{
		$aPaths = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/src'));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() === 'php') {
				$aPaths[] = $oFile->getPathname();
			}
		}
		sort($aPaths);

		return $aPaths;
	}

	/**
	 * Every toolset a core element declares is selectable on a token, and named
	 * where an operator reads about them.
	 *
	 * An operator narrows an instance from the comment on mcp_enabled_toolsets,
	 * so a toolset the comment omits is one they drop without meaning to and
	 * whose tools are then simply not advertised - documents, say, which
	 * carries its own MCP-toolset-documents scope and its own dictionary
	 * entries.
	 *
	 * There is no exemption for an element that declares nothing. The fallback
	 * in the abstracts hands such an element its namespace, which is a name for
	 * who wrote it rather than for what it does, so every core element declares
	 * a functional group instead and every value this setting can take has a
	 * scope behind it.
	 *
	 * Read out of the elements rather than listed here, so the count cannot
	 * fall behind.
	 *
	 * @dataProvider toolsetProvider
	 */
	public function testEveryToolsetIsDeclaredAndDocumented(string $sToolset): void
	{
		$this->assertStringContainsString(
			'"'.$sToolset.'"',
			self::enabledToolsetsComment(),
			"the mcp_enabled_toolsets comment does not name the {$sToolset} toolset an operator would have to list"
		);
		$this->assertStringContainsString(
			'`'.$sToolset.'`',
			file_get_contents(self::ROOT.'/README.md'),
			"the README does not name the {$sToolset} toolset"
		);

		$this->assertNotContains(
			$sToolset,
			self::coreNamespaces(),
			"the {$sToolset} toolset is a namespace reached by the fallback, not something an element declares"
		);

		$sScope = MCPContext::SCOPE_TOOLSET_PREFIX.$sToolset;
		$this->assertStringContainsString(
			'<code>'.$sScope.'</code>',
			file_get_contents(self::ROOT.'/datamodel.altioo-mcp.xml'),
			"the {$sToolset} toolset has no {$sScope} token scope, so no token can be narrowed to it"
		);
	}

	/** @return array<string, array{0: string}> */
	public static function toolsetProvider(): array
	{
		$aToolsets = [];
		foreach (TitleDictionaryTest::coreElementProvider() as $aCase) {
			$aToolsets[(new $aCase[0]())->getToolset()] = true;
		}
		$aNames = array_keys($aToolsets);
		sort($aNames);

		self::assertNotEmpty($aNames, 'no core element declared a toolset');

		return array_combine($aNames, array_map(static fn (string $s): array => [$s], $aNames));
	}

	/**
	 * The namespaces the base's own elements declare - what getToolset() falls
	 * back to when an element does not override it.
	 *
	 * @return array<int, string>
	 */
	private static function coreNamespaces(): array
	{
		$aNamespaces = [];
		foreach (TitleDictionaryTest::coreElementProvider() as $aCase) {
			$aNamespaces[(new $aCase[0]())->getNamespace()] = true;
		}

		return array_keys($aNamespaces);
	}

	/** The text of the comment sitting above <mcp_enabled_toolsets>. */
	private static function enabledToolsetsComment(): string
	{
		$sXml = file_get_contents(self::ROOT.'/datamodel.altioo-mcp.xml');
		$iEnd = strpos($sXml, '<'.MCPHelper::MODULE_SETTING_ENABLED_TOOLSETS);
		self::assertNotFalse($iEnd, 'the datamodel does not declare '.MCPHelper::MODULE_SETTING_ENABLED_TOOLSETS);

		$iStart = strrpos(substr($sXml, 0, $iEnd), '<!--');
		self::assertNotFalse($iStart, MCPHelper::MODULE_SETTING_ENABLED_TOOLSETS.' has no comment above it');

		return substr($sXml, $iStart, $iEnd - $iStart);
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
		// Which iTop it runs on is stated there too, and checked against
		// .github/itop-support.json rather than against a branch written here:
		// see testProseAgreesWithTheDeclaredSupport.
		$this->assertStringContainsString('Requires iTop ', $sDescription, 'the description does not state which iTop it runs on');
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
	 * extension.xml and the README. The two doc/ guides are
	 * Creative Commons, and they shipped by omission: exclude.txt reasoned
	 * about README.md, doc/, tests/ and tools/ and never about them. A
	 * differently-licensed file landing on a customer instance inside a
	 * single-licence package is a provenance claim the package cannot support.
	 *
	 * Written as "no file the archive carries grants another licence" rather
	 * than as a list of the two, so that a third one added later is caught here
	 * instead of by whoever reads the zip.
	 *
	 * A grant links to the licence it grants, which is what is looked for: the
	 * two offenders both carry a creativecommons.org URL. Matching the licence
	 * *name* instead would fire on this file, and on the changelog entry
	 * describing the fix - a mention is not a grant.
	 */
	public function testNothingCarryingAnotherLicenceReachesThePackage(): void
	{
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
			// Directories rsync is told to drop whole, and every vendored tree -
			// the module's own and the example pack's - whose licences are
			// inventoried in licenses.json instead of being read here. Matched
			// as path segments: doc/example-pack/vendor/ is a vendor tree too,
			// and a prefix test walks straight into it.
			$aSegments = explode('/', $sPath);
			array_pop($aSegments);
			foreach (['vendor', 'build', '.git', 'tools', '.github', '.phpunit.cache', 'node_modules'] as $sDirectory) {
				if (in_array($sDirectory, $aSegments, true)) {
					continue 2;
				}
			}
			if (in_array($sPath, $aExcluded, true)) {
				continue;
			}
			preg_match_all(
				'#https?://[a-z0-9.-]+/licenses/[a-z0-9./-]*#i',
				file_get_contents($oFile->getPathname()),
				$aUrls
			);
			foreach ($aUrls[0] as $sUrl) {
				if (!str_starts_with($sUrl, 'https://www.gnu.org/licenses/agpl-3.0')) {
					$aOffenders[] = $sPath.' ('.$sUrl.')';
					continue 2;
				}
			}
		}
		sort($aOffenders);

		$this->assertSame(
			[],
			$aOffenders,
			'these ship in the archive and grant a licence other than '.MCPHelper::LICENSE
			.'; list them in exclude.txt: '.implode(', ', $aOffenders)
		);
	}

	/**
	 * web.config hides every directory the archive ships, and no others.
	 *
	 * It hid a templates/ segment for some time after the directory was
	 * deleted - harmless, and the reason the list is worth checking is the
	 * other direction: a directory added later and not listed here is one IIS
	 * serves. Both files say in their own comments to keep in step with the
	 * other and with the tree, and nothing was reading either.
	 */
	public function testWebConfigHidesExactlyTheDirectoriesThatShip(): void
	{
		$oXml = simplexml_load_file(self::ROOT.'/web.config');
		$this->assertNotFalse($oXml, 'web.config is not well-formed XML');

		$aHidden = [];
		foreach ($oXml->{'system.webServer'}->security->requestFiltering->hiddenSegments->add as $oAdd) {
			$aHidden[] = (string)$oAdd['segment'];
		}
		sort($aHidden);

		$aExcluded = self::excludedFromPackage();
		$aShipped = [];
		foreach (glob(self::ROOT.'/*', GLOB_ONLYDIR) as $sDirectory) {
			$sName = basename($sDirectory);
			if (str_starts_with($sName, '.') || in_array($sName, $aExcluded, true)) {
				continue;
			}
			$aShipped[] = $sName;
		}
		sort($aShipped);

		$this->assertSame(
			$aShipped,
			$aHidden,
			'web.config hiddenSegments and the directories the archive ships have drifted apart'
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
	 * SECURITY.md commits to a single point of contact and says it travels
	 * inside the package. It said that address was in composer.json under
	 * support.security; that field held a URL to SECURITY.md on GitHub, which
	 * is what the field means and is a web page - the one thing the sentence
	 * said it was not.
	 *
	 * The address is not written here. Whatever SECURITY.md commits to is what
	 * the other two have to carry, which is the direction that cannot go stale.
	 */
	public function testTheSecurityAddressTravelsWithThePackage(): void
	{
		$sSecurity = file_get_contents(self::ROOT.'/SECURITY.md');

		$this->assertSame(
			1,
			preg_match('/<([^@\s>]+@[^@\s>]+\.[a-z]{2,})>/', $sSecurity, $aMatch),
			'SECURITY.md names no contact address'
		);
		$sAddress = $aMatch[1];

		$this->assertSame(
			$sAddress,
			self::composer()['support']['email'] ?? null,
			'composer.json support.email is not the address SECURITY.md commits to'
		);
		$this->assertStringContainsString(
			$sAddress,
			file_get_contents(self::ROOT.'/README.md'),
			'the README does not carry the address SECURITY.md commits to'
		);
		$this->assertNotContains('SECURITY.md', self::excludedFromPackage(), 'SECURITY.md does not ship');
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

	/**
	 * .github/itop-support.json declares itself the single source of truth for
	 * the branches this extension claims, and composer.json declares the PHP
	 * range. Both are machine-readable, and CI computes its matrix from the
	 * first - but four prose copies of the same claim were maintained by hand:
	 * two tables in the README, the Hub listing's compatibility block, and a
	 * sentence inside extension.xml's description, which the setup shows to
	 * every administrator who installs the module.
	 *
	 * They had already drifted in shape. iTop patch releases move the PHP
	 * ceiling within a branch, so this is the fact most likely to become false,
	 * and three of the four copies had nobody assigned to notice.
	 *
	 * A link is the right answer where one is possible, and it is not here:
	 * .github/ does not ship in the archive, the Hub listing is text somebody
	 * pastes into a web form, and extension.xml is read by a parser. So the
	 * copies stay and are derived instead - each one delimited, each one
	 * checked against the two files that own the answer.
	 *
	 * @dataProvider supportedVersionsProvider
	 */
	public function testProseAgreesWithTheDeclaredSupport(string $sFile, string $sBranches, string $sPhp): void
	{
		$this->assertSame(
			implode(', ', self::supportedBranches()),
			$sBranches,
			$sFile.' names iTop branches that .github/itop-support.json does not declare, or omits one it does'
		);
		$this->assertSame(
			implode(', ', self::supportedPhpRange()),
			$sPhp,
			$sFile.' names a PHP floor or ceiling that composer.json does not declare'
		);
	}

	/**
	 * The three prose copies, reduced to the versions each one states.
	 *
	 * The markdown files carry an explicit region, because the surrounding
	 * pages are full of other version numbers - a dependency floor, an SDK
	 * pin, an iTop release nobody supports - and a check that scanned the
	 * whole file would either miss the claim or fire on everything else.
	 * extension.xml has no room for a marker, so the sentence itself is
	 * matched: "Requires iTop A or B and PHP X-Y".
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function supportedVersionsProvider(): array
	{
		$aCases = [];

		foreach (['README.md', 'doc/hub-listing.md'] as $sFile) {
			$sRegion = self::delimitedRegion($sFile, 'supported-versions');
			self::assertSame(
				1,
				preg_match('/^\|\s*iTop\s*\|([^|]*)\|/m', $sRegion, $aItop),
				$sFile.' has no iTop row inside its supported-versions region'
			);
			self::assertSame(
				1,
				preg_match('/^\|\s*PHP\s*\|([^|]*)\|/m', $sRegion, $aPhp),
				$sFile.' has no PHP row inside its supported-versions region'
			);
			$aCases[$sFile] = [$sFile, self::versionsIn($aItop[1]), self::versionsIn($aPhp[1])];
		}

		$oXml = simplexml_load_file(self::ROOT.'/extension.xml');
		self::assertNotFalse($oXml, 'extension.xml is not well-formed XML');
		self::assertSame(
			1,
			preg_match('/Requires iTop ([0-9. or]+?) and PHP ([0-9.-]+)\./', (string)$oXml->description, $aClaim),
			'extension.xml\'s description does not state "Requires iTop <branches> and PHP <floor>-<ceiling>."'
			.' The setup shows that text to whoever installs the module, so it is a claim like any other'
		);
		$aCases['extension.xml'] = ['extension.xml', self::versionsIn($aClaim[1]), self::versionsIn($aClaim[2])];

		return $aCases;
	}

	/**
	 * The branches .github/itop-support.json declares, in its own order.
	 *
	 * The file does not ship in the release archive, so the unit suite run
	 * from an unpacked instance skips this rather than failing on it - the
	 * same shape as the integration suite skipping without iTop.
	 *
	 * @return array<int, string>
	 */
	private static function supportedBranches(): array
	{
		$sPath = self::ROOT.'/.github/itop-support.json';
		if (!is_readable($sPath)) {
			self::markTestSkipped('.github/itop-support.json is not present; this is a repository check, not a package one');
		}

		$aJson = json_decode(file_get_contents($sPath), true);
		self::assertIsArray($aJson['branches'] ?? null, '.github/itop-support.json declares no branches');

		$aBranches = array_column($aJson['branches'], 'branch');
		self::assertNotEmpty($aBranches);

		return $aBranches;
	}

	/**
	 * The floor and the ceiling composer.json allows, as the two versions a
	 * reader is shown. ">=8.2 <8.5" means 8.2 to 8.4: the constraint names the
	 * first version that is out, and no prose anywhere says "8.5".
	 *
	 * @return array<int, string>
	 */
	private static function supportedPhpRange(): array
	{
		$sConstraint = self::composer()['require']['php'] ?? '';

		self::assertSame(
			1,
			preg_match('/^>=([0-9]+)\.([0-9]+) <([0-9]+)\.([0-9]+)$/', $sConstraint, $aMatch),
			'composer.json\'s php constraint is not the ">=x.y <a.b" this check knows how to read: '.$sConstraint
		);
		self::assertGreaterThan(0, (int)$aMatch[4], 'a ">=x.y <a.0" constraint has no ceiling minor to name');

		return [$aMatch[1].'.'.$aMatch[2], $aMatch[3].'.'.((int)$aMatch[4] - 1)];
	}

	/** Every x.y in a fragment of prose, in the order they are written. */
	private static function versionsIn(string $sText): string
	{
		preg_match_all('/[0-9]+\.[0-9]+/', $sText, $aVersions);

		return implode(', ', $aVersions[0]);
	}

	/** The text between <!-- <name>:begin ... --> and <!-- <name>:end -->. */
	private static function delimitedRegion(string $sFile, string $sName): string
	{
		$sContent = file_get_contents(self::ROOT.'/'.$sFile);
		self::assertNotFalse($sContent, $sFile.' cannot be read');

		self::assertSame(
			1,
			preg_match('/<!--\s*'.preg_quote($sName, '/').':begin.*?-->(.*?)<!--\s*'.preg_quote($sName, '/').':end\s*-->/s', $sContent, $aMatch),
			$sFile.' has no single '.$sName.' region; the check that keeps it honest cannot find what to read'
		);

		return $aMatch[1];
	}
}
