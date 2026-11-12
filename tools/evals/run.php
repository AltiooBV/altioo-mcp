#!/usr/bin/env php
<?php
/**
 * Runs scenarios against a Claude model and this extension's MCP endpoint
 * (doc/adr/0008). By hand, with your own key; never in CI.
 *
 *   ANTHROPIC_API_KEY=...  MCP_EVAL_URL=https://itop.example/.../index.php  MCP_EVAL_TOKEN=...
 *   php tools/evals/run.php [--model <id>] [--out report.json] [scenarios/name.json ...]
 *
 *   php tools/evals/run.php --dry-run     parse and validate the scenarios, contact nothing
 *
 * Without ANTHROPIC_API_KEY it prints that it skipped and exits 0: nothing is
 * sent anywhere. The key, the URL and the token come from the environment only
 * and are never printed or written to the report. The model defaults to
 * claude-sonnet-5-5 (override: --model or MCP_EVAL_MODEL).
 *
 * The endpoint must be a disposable instance holding synthetic data: what a
 * scenario reads is sent to the model provider (README, "Data flow and
 * privacy"), and some scenarios write. Each scenario names the credential it
 * wants ("token": all, read, write or portal); this runs the scenarios whose
 * token matches the one in MCP_EVAL_TOKEN, named by MCP_EVAL_GRADE (default
 * "all"), and lists the rest as not run.
 *
 * Exit: 0 all run scenarios passed (or skipped), 1 one failed, 2 usage.
 *
 * Checks are on the tool calls only. What the agent told the user, and what the
 * instance holds afterwards, are read by a person from the report.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\McpEvals\ClaudeAgent;
use Altioo\McpEvals\McpClient;
use Altioo\McpEvals\Runner;
use Altioo\McpEvals\Scenario;

$aArgs = array_slice($argv, 1);
$bDry = false;
$sOut = null;
$sModel = getenv('MCP_EVAL_MODEL') ?: 'claude-sonnet-5-5';
$aFiles = [];
while ($aArgs !== []) {
	$sArg = array_shift($aArgs);
	if ($sArg === '--dry-run') {
		$bDry = true;
	} elseif ($sArg === '--model' || $sArg === '--out') {
		$sValue = array_shift($aArgs);
		if ($sValue === null) {
			fwrite(STDERR, "{$sArg} needs a value\n");
			exit(2);
		}
		if ($sArg === '--model') {
			$sModel = $sValue;
		} else {
			$sOut = $sValue;
		}
	} elseif (str_starts_with($sArg, '-')) {
		fwrite(STDERR, "unknown option {$sArg}\n");
		exit(2);
	} else {
		$aFiles[] = $sArg;
	}
}
if ($aFiles === []) {
	$aFiles = glob(__DIR__.'/scenarios/*.json') ?: [];
	sort($aFiles);
}

foreach (['Scenario', 'Expectations', 'McpClient', 'Agent', 'ClaudeAgent', 'Runner'] as $sClass) {
	require_once __DIR__.'/src/'.$sClass.'.php';
}

$aScenarios = [];
foreach ($aFiles as $sFile) {
	try {
		$aScenarios[] = Scenario::FromFile($sFile);
	} catch (InvalidArgumentException $e) {
		fwrite(STDERR, $e->getMessage()."\n");
		exit(2);
	}
}
if ($aScenarios === []) {
	fwrite(STDERR, "no scenario found\n");
	exit(2);
}

if ($bDry) {
	foreach ($aScenarios as $oScenario) {
		printf("ok   %-32s token=%-6s maxCalls=%d\n", $oScenario->id(), $oScenario->token(), $oScenario->maxCalls());
	}
	echo count($aScenarios)." scenario(s) parse; nothing was sent.\n";
	exit(0);
}

if ((string)getenv('ANTHROPIC_API_KEY') === '') {
	echo "skipped: ANTHROPIC_API_KEY is not set, so nothing was run and nothing was sent anywhere.\n";
	exit(0);
}
$sUrl = (string)getenv('MCP_EVAL_URL');
$sToken = (string)getenv('MCP_EVAL_TOKEN');
if ($sUrl === '' || $sToken === '') {
	fwrite(STDERR, "MCP_EVAL_URL and MCP_EVAL_TOKEN are required (a disposable instance, and a token for it)\n");
	exit(2);
}
$sGrade = getenv('MCP_EVAL_GRADE') ?: 'all';

if (!is_file(__DIR__.'/vendor/autoload.php')) {
	fwrite(STDERR, "run `composer install` in tools/evals first\n");
	exit(2);
}
require_once __DIR__.'/vendor/autoload.php';

$oRunner = new Runner(new McpClient($sUrl, $sToken), new ClaudeAgent($sModel));
$aReport = ['model' => $sModel, 'date' => date('c'), 'results' => [], 'notRun' => []];
$bFailed = false;
foreach ($aScenarios as $oScenario) {
	if ($oScenario->token() !== $sGrade) {
		$aReport['notRun'][] = $oScenario->id().' wants token '.$oScenario->token().', MCP_EVAL_GRADE is '.$sGrade;
		continue;
	}
	$aResult = $oRunner->run($oScenario);
	$aReport['results'][] = $aResult;
	$bFailed = $bFailed || !$aResult['passed'];
	printf(
		"%s %-32s %d call(s), %d+%d tokens, %ss\n",
		$aResult['passed'] ? 'PASS' : 'FAIL', $aResult['id'], count($aResult['calls']), $aResult['inputTokens'], $aResult['outputTokens'], $aResult['seconds']
	);
	foreach ($aResult['failures'] as $sFailure) {
		echo "       - {$sFailure}\n";
	}
}
foreach ($aReport['notRun'] as $sLine) {
	echo "not run: {$sLine}\n";
}
if ($sOut !== null) {
	file_put_contents($sOut, json_encode($aReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
	echo "report: {$sOut} (read the final text and the calls; the checks cannot)\n";
}
exit($bFailed ? 1 : 0);
