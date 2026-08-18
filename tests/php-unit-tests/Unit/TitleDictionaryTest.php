<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * Every core element has a title entry, in every language shipped.
 *
 * The fallback means a missing entry is invisible: the English literal from
 * defaultTitle() is served and nothing complains, in the language it happens
 * to be right for. So the thing worth testing is not that translation works -
 * ModuleConfigurationTest checks that against a live iTop - but that nobody
 * added a fourteenth tool and left it out of the dictionary, which is the way
 * this drifts.
 *
 * Reads the XML rather than a live iTop, so it belongs to the unit suite.
 */
class TitleDictionaryTest extends TestCase
{
	private const MODULE_ROOT = __DIR__.'/../../..';

	/** Languages the datamodel declares, all of which must be complete. */
	private const LANGUAGES = ['EN US', 'FR FR'];

	/**
	 * @dataProvider coreElementProvider
	 */
	public function testEveryCoreElementHasATitleEntryInEveryLanguage(string $sClass): void
	{
		$oElement = (new ReflectionClass($sClass))->newInstance();
		$sKey = $oElement->titleDictionaryKey();

		foreach (self::LANGUAGES as $sLanguage) {
			$this->assertArrayHasKey(
				$sKey,
				self::entriesOf($sLanguage),
				sprintf('%s has no "%s" entry for %s in %s.', $sClass, $sKey, $sLanguage, self::dictionaryFileOf($sLanguage))
			);
		}
	}

	/**
	 * @dataProvider coreElementProvider
	 */
	public function testTheEnglishEntryMatchesTheTitleWrittenInCode(string $sClass): void
	{
		$oRef = new ReflectionClass($sClass);
		$oElement = $oRef->newInstance();

		// No setAccessible(): it has been a no-op since PHP 8.1, and calling it
		// is deprecated as of 8.5.
		$oDefaultTitle = $oRef->getMethod('defaultTitle');

		// Not a style rule: the literal is what a client shows whenever the
		// dictionary is unavailable, so the two saying different things means
		// the title changes depending on whether iTop got as far as loading
		// its dictionaries.
		$this->assertSame(
			$oDefaultTitle->invoke($oElement),
			self::entriesOf('EN US')[$oElement->titleDictionaryKey()] ?? null,
			$sClass.': the English dictionary entry and defaultTitle() disagree.'
		);
	}

	/**
	 * Every concrete element under src/Core.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function coreElementProvider(): array
	{
		$aClasses = [];

		foreach (['Tools', 'Resources', 'ResourceTemplates', 'Prompts'] as $sDirectory) {
			foreach (glob(self::MODULE_ROOT.'/src/Core/'.$sDirectory.'/*.php') ?: [] as $sFile) {
				$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\'.$sDirectory.'\\'.basename($sFile, '.php');
				if (!class_exists($sClass)) {
					continue;
				}

				$oRef = new ReflectionClass($sClass);
				if ($oRef->isAbstract()) {
					continue;
				}
				if (!$oRef->isSubclassOf(AbstractMCPTool::class)
					&& !$oRef->isSubclassOf(AbstractMCPResource::class)
					&& !$oRef->isSubclassOf(AbstractMCPResourceTemplate::class)
					&& !$oRef->isSubclassOf(AbstractMCPPrompt::class)) {
					continue;
				}

				$aClasses[$sClass] = [$sClass];
			}
		}

		return $aClasses;
	}

	/**
	 * The file holding one language, named the way the compiler names its own
	 * output: lowercased, space to hyphen (MFCompiler::CompileDictionaries()).
	 */
	private static function dictionaryFileOf(string $sLanguage): string
	{
		return sprintf('datamodel.altioo-mcp.dict.%s.xml', str_replace(' ', '-', strtolower(trim($sLanguage))));
	}

	/**
	 * The dictionary entries declared for one language.
	 *
	 * Each language lives in its own datamodel file; iTop loads every file
	 * matching /^datamodel(.*)\.xml$/i in the module root and merges them, so
	 * reading one file here is reading the whole of that language.
	 *
	 * @return array<string, string> entry id => label
	 */
	private static function entriesOf(string $sLanguage): array
	{
		static $aCache = [];

		if (isset($aCache[$sLanguage])) {
			return $aCache[$sLanguage];
		}

		$sFile = self::dictionaryFileOf($sLanguage);
		self::assertFileExists(self::MODULE_ROOT.'/'.$sFile, sprintf('%s declares no dictionary file.', $sLanguage));

		$oXml = simplexml_load_file(self::MODULE_ROOT.'/'.$sFile);
		self::assertNotFalse($oXml, sprintf('%s could not be parsed.', $sFile));

		$aEntries = [];
		foreach ($oXml->dictionaries->dictionary as $oDictionary) {
			if ((string)$oDictionary['id'] !== $sLanguage) {
				continue;
			}
			foreach ($oDictionary->entries->entry as $oEntry) {
				$aEntries[(string)$oEntry['id']] = (string)$oEntry;
			}
		}

		return $aCache[$sLanguage] = $aEntries;
	}
}
