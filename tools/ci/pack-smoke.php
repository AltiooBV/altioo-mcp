<?php
/**
 * The example pack's fixture, inside the instance it was installed on: the checks that need iTop booted, and the
 * credentials and objects pack-smoke.sh then calls the pack's elements with over HTTP.
 *
 * Checked here rather than over the wire: that the pack's toolset scope is a value PersonalToken's scope accepts.
 * That is the regression for the pack's datamodel file being skipped by the setup (a comment before
 * <itop_design>): the file went unread, the scope was never declared, and a token asking for it was refused at
 * login. The HTTP half checks the same thing as a client sees it.
 *
 * Prints one line, `pack-smoke: <json>`, last. The tokens in it are throwaway credentials for a throwaway instance;
 * the caller masks them, and nothing writes them to a file the job archives.
 *
 * Usage: php tools/ci/pack-smoke.php <itop-dir> <admin-login>
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sItopDir = $argv[1] ?? '';
$sAdmin = $argv[2] ?? 'admin';
const PACK_SCOPE = 'MCP-toolset-acme-servicedesk';

if ($sItopDir === '' || !is_file($sItopDir.'/approot.inc.php')) {
	fwrite(STDERR, "usage: php pack-smoke.php <itop-dir> <admin-login>\n");
	exit(1);
}

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

$iFailures = 0;
function check(string $sWhat, bool $bOk): void
{
	global $iFailures;
	echo ($bOk ? '  ok   ' : '  FAIL ').$sWhat."\n";
	$iFailures += $bOk ? 0 : 1;
}

echo 'iTop '.ITOP_VERSION.' / PHP '.PHP_VERSION."\n";

// Asked as TokenScopes asks it at runtime, through both accessors of the AttributeEnumSet.
$aScopes = [];
$oScope = MetaModel::GetAttributeDef('PersonalToken', 'scope');
foreach (['GetPossibleValues', 'GetAllowedValues'] as $sMethod) {
	$mDeclared = method_exists($oScope, $sMethod) ? $oScope->$sMethod() : null;
	if (is_array($mDeclared)) {
		$aScopes = array_merge($aScopes, array_map('strval', array_keys($mDeclared)));
	}
}
check('PersonalToken accepts the pack\'s scope '.PACK_SCOPE.' (its datamodel file was loaded)', in_array(PACK_SCOPE, $aScopes, true));
check('the pack\'s scope has its label, from the same file', Dict::S('Class:PersonalToken/Attribute:scope/Value:'.PACK_SCOPE) !== 'Class:PersonalToken/Attribute:scope/Value:'.PACK_SCOPE);

if (!UserRights::Login($sAdmin)) {
	fwrite(STDERR, "could not log in as '$sAdmin'\n");
	exit(1);
}
CMDBObject::SetTrackInfo('CI pack smoke');

// acme_ticket_search_mine lists the tickets of the current contact, so the account gets one and a ticket raised by it.
$iOrg = (int)MetaModel::GetObjectFromOQL('SELECT Organization')->GetKey();
$oPerson = MetaModel::NewObject('Person');
$oPerson->Set('name', 'Pack smoke');
$oPerson->Set('first_name', 'CI');
$oPerson->Set('org_id', $iOrg);
$oPerson->DBInsert();
$oUser = MetaModel::GetObject('User', UserRights::GetUserId());
$oUser->Set('contactid', $oPerson->GetKey());
$oUser->DBUpdate();
// The login above read the account before it had a contact; read it again.
UserRights::Logoff();
UserRights::Login($sAdmin);

$oTicket = MetaModel::NewObject('UserRequest');
$oTicket->Set('org_id', $iOrg);
$oTicket->Set('caller_id', $oPerson->GetKey());
$oTicket->Set('title', 'Pack smoke ticket');
$oTicket->Set('description', 'Raised by the CI pack smoke.');
$oTicket->DBInsert();

$aOut = [
	'ticket_id'  => (int)$oTicket->GetKey(),
	'precanned'  => MetaModel::IsValidClass('PrecannedReply'),
	'reply_id'   => 0,
];
if ($aOut['precanned']) {
	$oReply = MetaModel::NewObject('PrecannedReply');
	$oReply->Set('name', 'Pack smoke reply');
	$oReply->Set('body', '<p>Hello $this->caller_id->first_name$, about $this->title$.</p>');
	$oReply->DBInsert();
	$aOut['reply_id'] = (int)$oReply->GetKey();
}

// One token per question pack-smoke.sh asks: everything, the pack's toolset alone, and advisory.
foreach (['full' => 'MCP', 'toolset' => PACK_SCOPE, 'toolset_read' => PACK_SCOPE.',MCP-read', 'advisory' => 'MCP,MCP-advisory'] as $sKey => $sScope) {
	$oToken = MetaModel::NewObject('PersonalToken');
	$oToken->Set('user_id', UserRights::GetUserId());
	$oToken->Set('application', 'ci-pack-smoke-'.$sKey);
	$oToken->Set('scope', $sScope);
	try {
		$oToken->DBInsert();
		$aOut['token_'.$sKey] = (string)$oToken->GetToken();
	} catch (Throwable $e) {
		check("a token scoped '$sScope' can be created (".$e->getMessage().')', false);
		$aOut['token_'.$sKey] = '';
	}
	check("a token scoped '$sScope' carries a secret", $aOut['token_'.$sKey] !== '');
}

if ($iFailures > 0) {
	fwrite(STDERR, "\n$iFailures check(s) failed\n");
	exit(1);
}
echo 'pack-smoke: '.json_encode($aOut)."\n";
