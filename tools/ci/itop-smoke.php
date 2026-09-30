<?php
/**
 * Post-setup checks that need iTop booted, and the token the HTTP smoke uses.
 *
 * This file is the generic harness only: it boots iTop, declares `check()`,
 * and confirms the declared version matches what actually compiled. Every
 * check that depends on what *this* module ships - its settings, its
 * classes, its scopes, the token it mints - lives in
 * tools/ci/checks/module-smoke.php, which this file includes if present.
 * That split is what lets a template extracted from this repository carry
 * the harness without carrying this module's own checks.
 *
 * Prints the token secret the per-repo checks minted on the last line of
 * stdout, and nothing else there, so the caller can read it with `tail -1`.
 * It is a throwaway credential for a throwaway instance; it is still written
 * to stdout only, never to a file the job archives.
 *
 * Usage: php tools/ci/itop-smoke.php <itop-dir> <admin-login>
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sItopDir = $argv[1] ?? '';
$sAdmin = $argv[2] ?? 'admin';

if ($sItopDir === '' || !is_file($sItopDir.'/approot.inc.php')) {
	fwrite(STDERR, "usage: php itop-smoke.php <itop-dir> <admin-login>\n");
	exit(1);
}

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

// This module's own extension_code, read from its repository checkout - never
// a literal, so this harness carries over unchanged to a differently-named
// module.
$sModuleCode = (string)(simplexml_load_file(__DIR__.'/../../extension.xml')->extension_code ?? '');

// What was placed in extensions/, against what the compiled environment
// actually loads a moment later.
$sDeclaredVersion = (string)(simplexml_load_file($sItopDir.'/extensions/'.$sModuleCode.'/extension.xml')->version ?? '');

$aFailures = [];

function check(string $sWhat, bool $bOk): void
{
	global $aFailures;
	echo ($bOk ? '  ok   ' : '  FAIL ').$sWhat."\n";
	if (!$bOk) {
		$aFailures[] = $sWhat;
	}
}

echo "iTop ".ITOP_VERSION." / PHP ".PHP_VERSION."\n";
echo "module: $sModuleCode $sDeclaredVersion\n";

$sHttpSmokeToken = null;

$sModuleChecks = __DIR__.'/checks/module-smoke.php';
if (is_file($sModuleChecks)) {
	require $sModuleChecks;
}

if (count($aFailures) > 0) {
	fwrite(STDERR, "\n".count($aFailures)." check(s) failed\n");
	exit(1);
}

if ($sHttpSmokeToken !== null && $sHttpSmokeToken !== '') {
	echo $sHttpSmokeToken."\n";
}
