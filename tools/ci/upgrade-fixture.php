<?php
/**
 * The data an upgrade must not lose, written before it and checked after it.
 *
 * A module upgrade in iTop is a recompilation: the datamodel is rebuilt and the
 * schema altered to match. Nothing warns when that costs data, and the schema
 * update being additive is what makes it quiet: an attribute removed from the
 * model leaves its column in place, holding rows nothing can read any more, and
 * the setup reports success. A renamed class is the destructive case - its rows
 * keep the old code in finalclass, which the integrity check reads as belonging
 * to no known class and plans for deletion. Either way the only way to know is
 * to put something in the instance first and look for it afterwards.
 *
 * What is seeded is chosen to fail loudly rather than plausibly:
 *
 *   - rows of this module's own class, with values that survive a careless
 *     schema alter differently than they survive a correct one (accents, an
 *     apostrophe, a long string near the column limit);
 *   - a PersonalToken carrying the MCP scope - a value this module adds to a
 *     *core* class through a delta, which is the kind of thing that quietly
 *     stops being an allowed value;
 *   - the list of attribute codes the class had, so an attribute removed in the
 *     new version is named rather than merely missed;
 *   - a module setting tuned away from its default, because iTop preserves
 *     module settings on upgrade only when the setup is given the previous
 *     configuration file, and getting that wrong reverts every client's tuning
 *     to defaults with no error anywhere.
 *
 * Usage: php tools/ci/upgrade-fixture.php <itop-dir> seed
 *        php tools/ci/upgrade-fixture.php <itop-dir> verify
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

const FIXTURE_CLASS = 'AltiooEventMCPService';
const FIXTURE_SETTING = 'mcp_max_document_bytes';
// Not the default (5242880). A value equal to the default would be preserved
// and reverted identically, and the check would pass either way.
const FIXTURE_SETTING_VALUE = 1234567;

$sItopDir = rtrim($argv[1] ?? '', '/');
$sAction = $argv[2] ?? '';

if ($sItopDir === '' || !is_file($sItopDir.'/approot.inc.php') || !in_array($sAction, ['seed', 'verify'], true)) {
	fwrite(STDERR, "usage: php upgrade-fixture.php <itop-dir> seed|verify\n");
	exit(1);
}

$sStateFile = $sItopDir.'/ci-upgrade-fixture.json';

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

$aFailures = [];

function check(string $sWhat, bool $bOk, string $sDetail = ''): void
{
	global $aFailures;
	echo ($bOk ? '  ok   ' : '  FAIL ').$sWhat.($sDetail === '' ? '' : ' - '.$sDetail)."\n";
	if (!$bOk) {
		$aFailures[] = $sWhat;
	}
}

/** The version iTop is running, read the way the runtime reads it. */
function installed_version(string $sItopDir): string
{
	$oXml = @simplexml_load_file($sItopDir.'/extensions/altioo-mcp/extension.xml');

	return (string)($oXml->version ?? '');
}

if (!UserRights::Login(getenv('ITOP_ADMIN_USER') ?: 'admin')) {
	fwrite(STDERR, "could not log in - is this instance installed?\n");
	exit(1);
}

if ($sAction === 'seed') {
	CMDBObject::SetTrackOrigin('custom-extension');
	CMDBObject::SetTrackInfo('CI upgrade fixture');

	$aRows = [
		['mcp_method' => 'tools/call', 'mcp_name' => 'itop_search', 'status' => 'success', 'message' => 'seeded before the upgrade'],
		// Accented and quoted text: a column recreated with a different charset
		// or a value re-inserted through unescaped SQL shows up here and nowhere
		// else in this pipeline.
		['mcp_method' => 'resources/read', 'mcp_name' => "l'inventaire réseau", 'status' => 'error', 'message' => "échec de l'appel"],
		['mcp_method' => 'prompts/get', 'mcp_name' => str_repeat('a', 255), 'status' => 'success', 'message' => 'a name at the column limit'],
	];

	$aState = ['baseline_version' => installed_version($sItopDir), 'events' => [], 'token' => null];

	foreach ($aRows as $aRow) {
		$oEvent = MetaModel::NewObject(FIXTURE_CLASS);
		foreach ($aRow as $sAtt => $sValue) {
			$oEvent->Set($sAtt, $sValue);
		}
		$iId = $oEvent->DBInsert();
		$aState['events'][(string)$iId] = $aRow;
		echo "  seeded ".FIXTURE_CLASS." #$iId ({$aRow['mcp_method']})\n";
	}

	// Every attribute the class has today. An upgrade that removes one strands
	// its column - the rows stay, unreachable - and this is what turns "some
	// data is gone" into "the duration_ms attribute was removed".
	$aState['attributes'] = array_keys(MetaModel::ListAttributeDefs(FIXTURE_CLASS));
	sort($aState['attributes']);

	// A core class this module extends by delta, rather than one it owns.
	$oToken = MetaModel::NewObject('PersonalToken');
	$oToken->Set('user_id', UserRights::GetUserId());
	$oToken->Set('application', 'ci-upgrade-fixture');
	$oToken->Set('scope', 'MCP');
	$aState['token'] = ['id' => $oToken->DBInsert(), 'application' => 'ci-upgrade-fixture', 'scope' => 'MCP'];
	echo "  seeded PersonalToken #{$aState['token']['id']} with scope MCP\n";

	// The administrator's tuning. Written to the configuration file the same way
	// an administrator writes it, so the upgrade meets it in its usual place.
	$oConfig = utils::GetConfig();
	$sConfigFile = $oConfig->GetLoadedFile();
	// The setup leaves the file read-only (0440) and unattended-install.php
	// chmods it back to 0770 before touching it. An administrator editing the
	// file by hand does the same thing.
	@chmod($sConfigFile, 0770);
	$oConfig->SetModuleSetting('altioo-mcp', FIXTURE_SETTING, FIXTURE_SETTING_VALUE);
	$oConfig->WriteToFile();
	// Left owner-writable rather than restored to 0440. What this fixture is
	// about is the *value* surviving the upgrade, not the permission bits, and
	// a config the next setup run cannot open would fail this test for a reason
	// that has nothing to do with the module.
	@chmod($sConfigFile, 0640);
	$aState['setting'] = [FIXTURE_SETTING => FIXTURE_SETTING_VALUE];
	echo "  set altioo-mcp/".FIXTURE_SETTING." = ".FIXTURE_SETTING_VALUE." in $sConfigFile\n";

	$aState['event_count'] = (int)(new DBObjectSet(new DBObjectSearch(FIXTURE_CLASS)))->Count();

	file_put_contents($sStateFile, json_encode($aState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	echo "\nfixture written to $sStateFile (baseline ".$aState['baseline_version'].")\n";
	exit(0);
}

// verify
if (!is_file($sStateFile)) {
	fwrite(STDERR, "no fixture state at $sStateFile - seed before upgrading\n");
	exit(1);
}

$aState = json_decode((string)file_get_contents($sStateFile), true, 512, JSON_THROW_ON_ERROR);

echo "upgraded ".$aState['baseline_version']." -> ".installed_version($sItopDir)."\n";

// The class itself. Everything below is meaningless if this failed.
check(FIXTURE_CLASS.' still exists', MetaModel::IsValidClass(FIXTURE_CLASS));

if (MetaModel::IsValidClass(FIXTURE_CLASS)) {
	$aNow = array_keys(MetaModel::ListAttributeDefs(FIXTURE_CLASS));
	sort($aNow);
	$aRemoved = array_diff($aState['attributes'], $aNow);
	check(
		'no attribute was removed from '.FIXTURE_CLASS,
		count($aRemoved) === 0,
		count($aRemoved) === 0 ? '' : 'removed: '.implode(', ', $aRemoved).' - the column and its rows are still there, unreachable'
	);

	foreach ($aState['events'] as $sId => $aExpected) {
		$oEvent = MetaModel::GetObject(FIXTURE_CLASS, (int)$sId, false, true);
		if ($oEvent === null) {
			check(FIXTURE_CLASS." #$sId survived the upgrade", false, 'the row is gone');
			continue;
		}
		$aWrong = [];
		foreach ($aExpected as $sAtt => $sValue) {
			if (in_array($sAtt, $aNow, true) && (string)$oEvent->Get($sAtt) !== (string)$sValue) {
				$aWrong[] = $sAtt;
			}
		}
		check(
			FIXTURE_CLASS." #$sId survived the upgrade unchanged",
			count($aWrong) === 0,
			count($aWrong) === 0 ? '' : 'altered: '.implode(', ', $aWrong)
		);
	}

	// Rows are added, never removed, by an upgrade.
	$iCount = (int)(new DBObjectSet(new DBObjectSearch(FIXTURE_CLASS)))->Count();
	check(
		'the audit trail did not shrink',
		$iCount >= (int)$aState['event_count'],
		$iCount.' rows now, '.$aState['event_count'].' before'
	);
}

// The delta-added enum value, on the class and on the row that uses it.
$aScopes = MetaModel::IsValidClass('PersonalToken')
	? array_keys(MetaModel::GetAttributeDef('PersonalToken', 'scope')->GetAllowedValues())
	: [];
check('PersonalToken still offers the MCP scope', in_array('MCP', $aScopes, true));

$oToken = MetaModel::GetObject('PersonalToken', (int)$aState['token']['id'], false, true);
check(
	'the seeded token survived with its scope',
	$oToken !== null && (string)$oToken->Get('scope') === 'MCP' && (string)$oToken->Get('application') === $aState['token']['application'],
	$oToken === null ? 'the token is gone' : 'scope is now "'.$oToken->Get('scope').'"'
);

// The tuning. Read through MetaModel, which is how the module's own code reads
// it, so a value that survived in the file but not in the compiled config still
// fails here.
$mSetting = MetaModel::GetModuleSetting('altioo-mcp', FIXTURE_SETTING, null);
check(
	'altioo-mcp/'.FIXTURE_SETTING.' kept the administrator\'s value',
	(int)$mSetting === (int)$aState['setting'][FIXTURE_SETTING],
	'reads '.var_export($mSetting, true).', was set to '.$aState['setting'][FIXTURE_SETTING]
);

if (count($aFailures) > 0) {
	fwrite(STDERR, "\n".count($aFailures)." check(s) failed - the upgrade lost something\n");
	exit(1);
}

echo "\nthe upgrade kept everything this fixture put in the instance\n";
