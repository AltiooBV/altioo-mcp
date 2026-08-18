<?php
/**
 * Post-setup checks that need iTop booted, and the token the HTTP smoke uses.
 *
 * What the unattended install proves is that the module compiled. What this
 * proves is that it compiled into something: the settings the controller reads
 * are declared, the audit class exists, and the token scopes the security model
 * depends on are selectable on a real token. Those are the three things that
 * have silently not been true after a datamodel change.
 *
 * Prints the token secret on the last line of stdout, and nothing else there,
 * so the caller can read it with `tail -1`. It is a throwaway credential for a
 * throwaway instance; it is still written to stdout only, never to a file the
 * job archives.
 *
 * Usage: php tools/ci/itop-smoke.php <itop-dir> <admin-login>
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;

$sItopDir = $argv[1] ?? '';
$sAdmin = $argv[2] ?? 'admin';

if ($sItopDir === '' || !is_file($sItopDir.'/approot.inc.php')) {
	fwrite(STDERR, "usage: php itop-smoke.php <itop-dir> <admin-login>\n");
	exit(1);
}

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

// What was placed in extensions/, against what the compiled environment
// actually loads a moment later.
$sDeclaredVersion = (string)(simplexml_load_file($sItopDir.'/extensions/altioo-mcp/extension.xml')->version ?? '');

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

// The module's own code is loadable from the compiled environment. iTop
// registers the module's vendor/autoload.php because module.altioo-mcp.php
// lists it under 'datamodel'; if that ever stops happening, every class in
// this extension is missing at runtime and nothing else here would say so.
check(
	'the module autoloader is registered and reports version '.$sDeclaredVersion,
	class_exists(MCPHelper::class) && MCPHelper::VERSION === $sDeclaredVersion
);

// Settings the controller reads at every request. A missing one does not fail
// the setup - it fails later, as a default nobody chose.
check('secure_mcp_services defaults to on', MetaModel::GetModuleSetting('altioo-mcp', 'secure_mcp_services', null) === true);

// The audit trail. No class, no record of what a model was asked to do.
check('AltiooEventMCPService exists', MetaModel::IsValidClass('AltiooEventMCPService'));

// The scopes are the security model: a token without one of these cannot reach
// the endpoint, so a datamodel change that drops them silently opens or closes
// the door for every client.
$aScopes = MetaModel::IsValidClass('PersonalToken')
	? array_keys(MetaModel::GetAttributeDef('PersonalToken', 'scope')->GetAllowedValues())
	: [];
check('PersonalToken carries the MCP scope', in_array('MCP', $aScopes, true));

// The profile the module ships, which is what an administrator grants.
$oProfileSearch = new DBObjectSearch('URP_Profiles');
$oProfileSearch->AddCondition('name', 'MCP Services User', '=');
check('the MCP Services User profile was created', (new DBObjectSet($oProfileSearch))->Count() === 1);

if (count($aFailures) > 0) {
	fwrite(STDERR, "\n".count($aFailures)." check(s) failed\n");
	exit(1);
}

// A token for the HTTP smoke. Created here rather than by an SQL insert
// because AfterInsert is where authent-token mints and hashes the secret: this
// is the same object an administrator gets from My Account, scope included,
// and the only place the plaintext is ever readable.
if (!UserRights::Login($sAdmin)) {
	fwrite(STDERR, "could not log in as '$sAdmin'\n");
	exit(1);
}
CMDBObject::SetTrackInfo('CI smoke test');

$oToken = MetaModel::NewObject('PersonalToken');
$oToken->Set('user_id', UserRights::GetUserId());
$oToken->Set('application', 'ci-http-smoke');
$oToken->Set('scope', 'MCP');
$oToken->DBInsert();

$sSecret = $oToken->GetToken();
if ($sSecret === null || $sSecret === '') {
	fwrite(STDERR, "the token was created but carries no readable secret\n");
	exit(1);
}

fwrite(STDERR, "token created for $sAdmin with scope MCP\n");
echo $sSecret."\n";
