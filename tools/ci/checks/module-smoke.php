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

// The hourly audit clean-up is found by iTop's own discovery, which is what cron asks. A process that
// is not listed here is never run, and nothing else says so.
check(
	'cron finds the audit clean-up process',
	in_array(\Altioo\iTop\Extension\MCP\Service\AuditHousekeeping::class, \Combodo\iTop\Service\InterfaceDiscovery\InterfaceDiscovery::GetInstance()->FindItopClasses(iBackgroundProcess::class), true)
);

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

// The two entries under Administration, compiled from the datamodel. They are administrator-only, so
// this is asked after logging in as one. A menu that did not compile is a dashboard nobody can open,
// and nothing else says so.
ApplicationMenu::LoadAdditionalMenus();
foreach (['MCPActivity', 'MCPServiceCalls'] as $sMenuId) {
	check('the '.$sMenuId.' menu exists', ApplicationMenu::GetMenuIndexById($sMenuId) >= 0);
}

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

// A portal-only user for the portal smoke (checks/portal-smoke.sh): a contact with a colleague in
// the same organisation, a request each, and a personal token for the first that carries the most
// a portal token can ask for. MCP-delete is there on purpose - README says portal mode serves the
// same whatever the grade, and a token that asked for less would make "core_object_delete is not
// served" a fact about the token and not about the mode. The expiry is inside
// mcp_portal_token_max_days, since a portal token that never expires is refused (README, "Giving
// a portal user a token"). Only if the stock portal and request management are installed: the
// instance is the iTop's own default choices, so they are, and a branch without them skips this
// with a line saying so rather than failing.
if (!MetaModel::IsValidClass('UserRequest') || !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')) {
	echo "  --   no stock portal or request management: the portal smoke has nothing to run against\n";
	return;
}

$oOrgSet = new DBObjectSet(DBSearch::FromOQL('SELECT Organization'));
$iOrg = (int)$oOrgSet->Fetch()->GetKey();
$sSuffix = bin2hex(random_bytes(3));

$aPeople = [];
foreach (['Portal', 'Colleague'] as $sFirst) {
	$oPerson = MetaModel::NewObject('Person');
	$oPerson->Set('first_name', $sFirst);
	$oPerson->Set('name', 'Smoke'.$sSuffix);
	$oPerson->Set('org_id', $iOrg);
	$oPerson->Set('email', strtolower($sFirst).'.'.$sSuffix.'@example.com');
	$oPerson->DBInsert();
	$aPeople[$sFirst] = (int)$oPerson->GetKey();
}

$aRequests = [];
foreach (['Portal' => 'own', 'Colleague' => 'other'] as $sFirst => $sWhich) {
	$oRequest = MetaModel::NewObject('UserRequest');
	$oRequest->Set('org_id', $iOrg);
	$oRequest->Set('caller_id', $aPeople[$sFirst]);
	$oRequest->Set('title', 'portal-smoke '.$sWhich.' '.$sSuffix);
	$oRequest->Set('description', 'Made by tools/ci/checks/module-smoke.php');
	$oRequest->DBInsert();
	$aRequests[$sWhich] = (int)$oRequest->GetKey();
}

$sPortalLogin = 'portal-smoke-'.$sSuffix;
$oUserProfiles = DBObjectSet::FromScratch('URP_UserProfile');
foreach (['Portal user', 'MCP Services User'] as $sProfile) {
	$oProfile = MetaModel::GetObjectFromOQL('SELECT URP_Profiles WHERE name = :name', ['name' => $sProfile]);
	$oLink = MetaModel::NewObject('URP_UserProfile');
	$oLink->Set('profileid', $oProfile->GetKey());
	$oLink->Set('reason', 'CI portal smoke');
	$oUserProfiles->AddObject($oLink);
}
$oPortalUser = MetaModel::NewObject('UserLocal');
$oPortalUser->Set('login', $sPortalLogin);
$oPortalUser->Set('password', 'Portal-Smoke-'.$sSuffix.'!');
$oPortalUser->Set('contactid', $aPeople['Portal']);
$oPortalUser->Set('language', 'EN US');
$oPortalUser->Set('profile_list', $oUserProfiles);
$oPortalUser->DBInsert();

// A personal token logs in only if its owner holds a profile authent-token lists (README). The
// HTTP server reads the config file afresh on every request, so it is written, not set in memory.
// The setup leaves the file read-only (0440), which only root gets past: made writable for the
// write and put back, as upgrade-fixture.php does and as an administrator would.
$oConfig = MetaModel::GetConfig();
$sConfigFile = $oConfig->GetLoadedFile();
@chmod($sConfigFile, 0770);
$oConfig->SetModuleSetting('authent-token', 'personal_tokens_allowed_profiles', ['Administrator', 'MCP Services User']);
$oConfig->WriteToFile();
@chmod($sConfigFile, 0440);

$oPortalToken = MetaModel::NewObject('PersonalToken');
$oPortalToken->Set('user_id', $oPortalUser->GetKey());
$oPortalToken->Set('application', 'ci-portal-smoke');
$oPortalToken->Set('scope', 'MCP-write,MCP-delete');
$oPortalToken->Set('expiration_date', date('Y-m-d H:i:s', strtotime('+30 days')));
$oPortalToken->DBInsert();

$sPortalSecret = $oPortalToken->GetToken();
if ($sPortalSecret === null || $sPortalSecret === '') {
	fwrite(STDERR, "the portal token was created but carries no readable secret\n");
	exit(1);
}

// Prefix-keyed like http-smoke-token, for the same reason: taken by its prefix, never as the last line.
echo 'portal-smoke: '.$sPortalSecret.':'.$aRequests['own'].':'.$aRequests['other']."\n";
