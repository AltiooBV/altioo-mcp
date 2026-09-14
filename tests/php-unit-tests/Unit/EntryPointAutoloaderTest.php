<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Who loads the module's autoloader, which has to be exactly one of two files.
 *
 * index.php ships in two places. Under extensions/ it is the file an
 * administrator copied; under env-<env>/ it is the compiled copy iTop serves.
 * module.altioo-mcp.php names 'vendor/autoload.php' as a datamodel file, and
 * iTop resolves that against the *compiled* tree on every request to the
 * environment. So an entry point that also requires __DIR__.'/vendor/autoload.php'
 * is requiring the same package by two absolute paths whenever it is served
 * from extensions/ - require_once cannot dedupe those, and the second
 * declaration of ComposerAutoloaderInitAltiooMcpExtension is a fatal error
 * that takes every request to that URL with it. The suffix is pinned in
 * composer.json, so there is no version of this where the two copies coexist.
 *
 * It was a live 500 on iTop 3.2, and nothing in the suite could have seen it:
 * the unit and integration tests both enter through the autoloader rather than
 * through index.php. What can be checked without a web server is the pair of
 * facts that has to hold - index.php requires no autoloader, and the manifest
 * still requires one - so this checks both, and would fail on either half
 * being changed alone.
 */
class EntryPointAutoloaderTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const MODULE_ROOT = __DIR__.'/../../..';

	/**
	 * A PHP file's source with its comments removed.
	 *
	 * Stripped for the same reason RequestPipelineOrderTest strips them: the
	 * comments in both files under test talk at length about the requires
	 * being searched for, and would answer every one of these searches on
	 * their own.
	 */
	private function sourceWithoutComments(string $sRelativePath): string
	{
		$sPath = self::MODULE_ROOT.'/'.$sRelativePath;
		$this->assertFileExists($sPath);

		$sCode = '';
		foreach (token_get_all((string)file_get_contents($sPath)) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}

	/**
	 * The regression itself.
	 *
	 * Any require or include of an autoloader from index.php is the bug,
	 * whatever it is spelled with and whatever guards it - the constant this
	 * file used to define guarded only its own require and never iTop's, which
	 * is why a guard is not accepted as a fix here.
	 */
	public function testTheEntryPointRequiresNoAutoloaderOfItsOwn(): void
	{
		$sCode = $this->sourceWithoutComments('index.php');

		$this->assertDoesNotMatchRegularExpression(
			'/\b(require|include)(_once)?\b[^;]*autoload/i',
			$sCode,
			'index.php requires an autoloader. module.altioo-mcp.php loads the same one from the compiled '
			.'tree, and served from extensions/ the two paths differ, so PHP fatals on redeclaring '
			.'ComposerAutoloaderInitAltiooMcpExtension.'
		);
	}

	/**
	 * The other half. index.php gets its classes from the manifest's entry, so
	 * removing that entry breaks the endpoint just as surely - and in a way
	 * the first test would happily pass.
	 */
	public function testTheManifestLoadsTheAutoloaderFirstAmongItsDatamodelFiles(): void
	{
		$sCode = $this->sourceWithoutComments('module.altioo-mcp.php');

		$bFound = preg_match("/'datamodel'\s*=>\s*array\s*\((?P<entries>[^)]*)\)/s", $sCode, $aMatch);
		$this->assertSame(1, $bFound, "module.altioo-mcp.php no longer declares a 'datamodel' array");

		$this->assertSame(
			1,
			preg_match("/'([^']+)'/", $aMatch['entries'], $aFirst),
			"the 'datamodel' array is empty"
		);

		$this->assertSame(
			'vendor/autoload.php',
			$aFirst[1],
			"vendor/autoload.php must be the first 'datamodel' entry: index.php no longer loads it, and "
			.'register.php - which the same array loads afterwards - names classes that need it.'
		);
	}

	/**
	 * Ordering, which is what is left to get wrong once both requires are in
	 * the right files. MCPController is not loadable until startup has run,
	 * and a `use` statement resolves nothing on its own.
	 */
	public function testTheControllerIsNotTouchedUntilStartupHasRun(): void
	{
		$sCode = $this->sourceWithoutComments('index.php');

		$iStartup = strpos($sCode, 'startup.inc.php');
		$iHandle = strpos($sCode, 'MCPController::handleRequest');

		$this->assertIsInt($iStartup, 'index.php no longer requires startup.inc.php');
		$this->assertIsInt($iHandle, 'index.php no longer calls MCPController::handleRequest()');
		$this->assertLessThan(
			$iHandle,
			$iStartup,
			'startup.inc.php must be required before MCPController is referenced, since startup is what '
			."loads this module's autoloader"
		);
	}
}
