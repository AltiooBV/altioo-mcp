<?php
/**
 * Turns the channel's exclusions (doc/adr/0012) on or off in an installed iTop's configuration file, the
 * way an administrator edits it, for the HTTP smoke. The server reads the file afresh on every request.
 *
 * Usage: php tools/ci/checks/exclusions.php <itop-dir> on|off [exempt profile name]
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sItopDir = rtrim($argv[1] ?? '', '/');
$sMode = $argv[2] ?? '';
if ($sItopDir === '' || !is_file($sItopDir.'/approot.inc.php') || !in_array($sMode, ['on', 'off'], true)) {
	fwrite(STDERR, "usage: php exclusions.php <itop-dir> on|off\n");
	exit(1);
}

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

$oConfig = utils::GetConfig();
$sFile = $oConfig->GetLoadedFile();
@chmod($sFile, 0770);
$oConfig->SetModuleSetting('altioo-mcp', 'mcp_excluded_classes', $sMode === 'on' ? ['Team'] : []);
// description: neither part of a friendly name nor of a list column, so the portal's own request loses it alone.
$oConfig->SetModuleSetting('altioo-mcp', 'mcp_excluded_attributes', $sMode === 'on' ? ['Person' => ['phone', 'mobile_phone'], 'UserRequest' => ['description']] : []);
// The exempt profile: the second argument names it ('' for none). The smoke's service account and its portal
// user both hold it, so that "a portal user holding the profile is still excluded" is tried on the wire.
$oConfig->SetModuleSetting('altioo-mcp', 'mcp_exclusion_exempt_profiles', ($sMode === 'on' && ($argv[3] ?? '') !== '') ? [(string)$argv[3]] : []);
$oConfig->WriteToFile();
@chmod($sFile, 0640);
echo "exclusions $sMode\n";
