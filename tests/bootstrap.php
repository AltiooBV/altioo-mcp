<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests must run with nothing but Composer's autoloader available, so
 * everything iTop-specific is resolved here and degraded gracefully:
 *
 *  - iTop is located through the ITOP_ROOT environment variable, falling back
 *    to the directory layout an extension normally sits in
 *    (<itop>/web/extensions/<module>). When found, approot.inc.php is loaded so
 *    that MetaModel, UserRights and friends become available.
 *  - When iTop is absent, a minimal global LogAPI stand-in is declared so the
 *    PSR-3 adapter can still be unit tested, and every integration test skips
 *    itself instead of erroring.
 *
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

use Altioo\iTop\Extension\MCP\Test\Support\SkippedItopDataTestCase;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * Absolute path of the iTop web root, or null when it cannot be located.
 */
function altioo_mcp_locate_itop(): ?string
{
	$sFromEnv = getenv('ITOP_ROOT');
	if (is_string($sFromEnv) && $sFromEnv !== '') {
		$sCandidate = rtrim($sFromEnv, '/').'/approot.inc.php';

		return file_exists($sCandidate) ? rtrim($sFromEnv, '/') : null;
	}

	// <itop-web-root>/extensions/<module>/tests -> three levels up.
	$sGuess = dirname(__DIR__, 3);

	return file_exists($sGuess.'/approot.inc.php') ? $sGuess : null;
}

$sItopRoot = altioo_mcp_locate_itop();

if ($sItopRoot !== null) {
	require_once $sItopRoot.'/approot.inc.php';
	require_once APPROOT.'/application/application.inc.php';
	require_once APPROOT.'/application/startup.inc.php';
}

// Global-namespace logging doubles. Declares a LogAPI stand-in only when the
// real one is absent, and always declares the recording TestIssueLog channel.
require_once __DIR__.'/Support/log_doubles.php';

/**
 * Integration tests extend this alias. It resolves to iTop's own
 * ItopDataTestCase when the test framework ships with the target iTop
 * (it lives in tests/php-unit-tests/ of the iTop *source* tree, and is absent
 * from the packaged release archives), and otherwise to a stand-in that skips
 * every test with an explanatory message.
 */
if (class_exists('Combodo\iTop\Test\UnitTest\ItopDataTestCase')) {
	class_alias('Combodo\iTop\Test\UnitTest\ItopDataTestCase', 'Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias');
} else {
	class_alias(SkippedItopDataTestCase::class, 'Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias');
}
