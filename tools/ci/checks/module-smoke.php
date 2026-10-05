<?php
/**
 * This module's own post-setup checks, included by tools/ci/itop-smoke.php
 * once the generic harness there has booted iTop and declared `check()`,
 * `$sItopDir`, `$sAdmin` and `$sModuleCode`.
 *
 * What the generic harness proves is that *a* module compiled and is
 * loadable. What this proves is that it compiled into something: the
 * settings the controller reads are declared, the audit class exists, and
 * the token scopes the security model depends on are selectable on a real
 * token. Those are the three things that have silently not been true after a
 * datamodel change.
 *
 * Also mints the token the HTTP smoke test uses, into $sHttpSmokeToken - the
 * generic harness echoes it, prefixed `http-smoke-token: `, if this file set it.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;

// The module's own code is loadable from the compiled environment. iTop
// registers the module's vendor/autoload.php because module.<code>.php lists
// it under 'datamodel'; if that ever stops happening, every class in this
// extension is missing at runtime and nothing else here would say so.
check(
	'the module autoloader is registered and reports version '.$sDeclaredVersion,
	class_exists(MCPHelper::class) && MCPHelper::VERSION === $sDeclaredVersion
);

// Settings the controller reads at every request. A missing one does not fail
// the setup - it fails later, as a default nobody chose.
check('secure_mcp_services defaults to on', MetaModel::GetModuleSetting($sModuleCode, 'secure_mcp_services', null) === true);

// The audit trail. No class, no record of what a model was asked to do.
check('AltiooEventMCPService exists', MetaModel::IsValidClass('AltiooEventMCPService'));

// The scopes are the security model: a token without one of these cannot reach
// the endpoint, so a datamodel change that drops them silently opens or closes
// the door for every client.
//
// Asked through both accessors, as TokenScopes::PossibleScopeValues does at
// runtime: scope is an AttributeEnumSet, and GetAllowedValues() - the accessor
// a plain enum answers - returns null for it. array_keys(null) is a fatal, so
// asking that one alone did not report a missing scope, it ended the run three
// checks in and never minted the token the HTTP smoke needs.
$aScopes = [];
if (MetaModel::IsValidClass('PersonalToken')) {
	$oScope = MetaModel::GetAttributeDef('PersonalToken', 'scope');
	foreach (['GetPossibleValues', 'GetAllowedValues'] as $sMethod) {
		$mDeclared = method_exists($oScope, $sMethod) ? $oScope->$sMethod() : null;
		if (!is_array($mDeclared)) {
			continue;
		}
		$aCodes = array_filter(array_keys($mDeclared), 'is_string');
		$aScopes = array_merge($aScopes, $aCodes !== [] ? $aCodes : array_filter($mDeclared, 'is_string'));
	}
}
check('PersonalToken carries the MCP scope', in_array('MCP', $aScopes, true));

// The profile the module ships, which is what an administrator grants.
$oProfileSearch = new DBObjectSearch('URP_Profiles');
$oProfileSearch->AddCondition('name', 'MCP Services User', '=');
check('the MCP Services User profile was created', (new DBObjectSet($oProfileSearch))->Count() === 1);

if (count($aFailures) > 0) {
	return;
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
$sHttpSmokeToken = $sSecret;
