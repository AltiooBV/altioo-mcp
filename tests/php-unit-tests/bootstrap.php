<?php
/**
 * Test bootstrap.
 *
 * Deliberately idempotent and safe to include twice, because it runs under two
 * different harnesses:
 *
 *  1. Standalone — `composer test`, using this module's phpunit.xml.dist, which
 *     names this file as its bootstrap.
 *  2. iTop's own runner — its `Extensions` testsuite scans
 *     `env-production/<module>/tests/php-unit-tests`, but boots with
 *     `unittestautoload.php`, which registers only iTop's autoloaders. This
 *     file is therefore pulled in by each test that needs the Support classes,
 *     since nothing else would autoload them there.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

if (defined('ALTIOO_MCP_TEST_BOOTSTRAPPED')) {
	return;
}
define('ALTIOO_MCP_TEST_BOOTSTRAPPED', true);

// The module root, two levels up from tests/php-unit-tests.
$sModuleRoot = dirname(__DIR__, 2);

require_once $sModuleRoot.'/vendor/autoload.php';

// Support classes are declared under autoload-dev, which is absent from a
// production autoloader dump. Load them explicitly so the suite behaves the
// same however it was started.
spl_autoload_register(static function (string $sClass) use ($sModuleRoot): void {
	$sPrefix = 'Altioo\\iTop\\Extension\\MCP\\Test\\';
	if (!str_starts_with($sClass, $sPrefix)) {
		return;
	}
	$sPath = $sModuleRoot.'/tests/php-unit-tests/'.str_replace('\\', '/', substr($sClass, strlen($sPrefix))).'.php';
	if (file_exists($sPath)) {
		require_once $sPath;
	}
});

/**
 * Absolute path of the iTop web root, or null when it cannot be located.
 */
function altioo_mcp_locate_itop(): ?string
{
	$sFromEnv = getenv('ITOP_ROOT');
	if (is_string($sFromEnv) && $sFromEnv !== '') {
		$sCandidate = rtrim($sFromEnv, '/');

		return file_exists($sCandidate.'/approot.inc.php') ? $sCandidate : null;
	}

	// <itop-web-root>/extensions/<module> -> two levels up from the module root.
	$sGuess = dirname(dirname(__DIR__, 2), 2);

	return file_exists($sGuess.'/approot.inc.php') ? $sGuess : null;
}

if (!defined('APPROOT')) {
	$sItopRoot = altioo_mcp_locate_itop();
	if ($sItopRoot !== null) {
		// iTop's bootstrap.inc.php assigns $fItopStarted and $iItopInitialMemory
		// at file scope, and startup.inc.php's first ExecutionKPI report reads
		// them back with `global`. Reached from a web entry point those are the
		// same variable; reached from here they are not, because PHPUnit loads
		// this file from inside a method and an include inherits the scope of
		// its include line. ExecutionKPI then gets null where it declares float
		// and the whole run dies before the first test. Binding the names here
		// puts iTop's own assignments on the globals it later looks at.
		// ItopTestCase::setUp guards the same two variables, for the same
		// reason, for iTop's own suites.
		global $fItopStarted, $iItopInitialMemory;

		require_once $sItopRoot.'/approot.inc.php';
		require_once APPROOT.'/application/application.inc.php';
		require_once APPROOT.'/application/startup.inc.php';
	}
}

// Global-namespace logging doubles. Declares a LogAPI stand-in only when the
// real one is absent, and always declares the recording TestIssueLog channel.
require_once __DIR__.'/Support/log_doubles.php';

/**
 * Integration tests extend this alias. It resolves to iTop's own
 * ItopDataTestCase when the test harness is present (it lives in
 * tests/php-unit-tests/src/BaseTestCase/ of the iTop *source* tree and is absent
 * from packaged releases), and otherwise to a stand-in that skips every test.
 */
if (!class_exists('Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias', false)) {
	if (class_exists('Combodo\iTop\Test\UnitTest\ItopDataTestCase')) {
		class_alias('Combodo\iTop\Test\UnitTest\ItopDataTestCase', 'Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias');
	} else {
		class_alias('Altioo\iTop\Extension\MCP\Test\Support\SkippedItopDataTestCase', 'Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias');
	}
}
