#!/usr/bin/env php
<?php
/**
 * What an MCP client pays, in bytes, to be told what this endpoint offers.
 *
 * A client puts the tool list, the resource and prompt lists and the server's
 * instructions in front of its model before the first question, and again each
 * time its context is rebuilt. This asks a running instance for exactly those,
 * over JSON-RPC, for each token given, and reports bytes per element and per
 * part (description, input schema, output schema, annotations), then totals.
 *
 * It does not use the MCP SDK's client: what is measured is what the endpoint
 * sends, not what a library makes of it. The token is read as a bearer token
 * from the arguments and is never printed.
 *
 * "~tokens" is bytes / 4, an estimate and nothing more: real tokenisers differ
 * by model and by content, and JSON schema text tokenises worse than prose.
 *
 * Usage:
 *   php tools/measure-surface.php <endpoint-url> <label>=<token> [<label>=<token> ...] [--json]
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$aArgs = array_slice($argv, 1);
$bJson = in_array('--json', $aArgs, true);
$aArgs = array_values(array_filter($aArgs, static fn (string $s): bool => $s !== '--json'));
if (count($aArgs) < 2) {
	fwrite(STDERR, "usage: php tools/measure-surface.php <endpoint-url> <label>=<token> [<label>=<token> ...] [--json]\n");
	exit(2);
}
$sUrl = array_shift($aArgs);
$aTokens = [];
foreach ($aArgs as $sPair) {
	$iEq = strpos($sPair, '=');
	if ($iEq === false || $iEq === 0) {
		fwrite(STDERR, "each credential is label=token\n");
		exit(2);
	}
	$aTokens[substr($sPair, 0, $iEq)] = substr($sPair, $iEq + 1);
}

/**
 * @param array<string, mixed> $aBody
 *
 * @return array{headers: array<string, string>, body: array<string, mixed>}
 */
function rpc(string $sUrl, string $sToken, array $aBody, ?string $sSession): array
{
	$aHeaders = [
		'Authorization: Bearer '.$sToken,
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
		'MCP-Protocol-Version: 2025-06-18',
	];
	if ($sSession !== null && $sSession !== '') {
		$aHeaders[] = 'Mcp-Session-Id: '.$sSession;
	}
	$rContext = stream_context_create(['http' => [
		'method'        => 'POST',
		'header'        => implode("\r\n", $aHeaders),
		'content'       => json_encode($aBody),
		'ignore_errors' => true,
		'timeout'       => 60,
	]]);
	$sResponse = @file_get_contents($sUrl, false, $rContext);
	if ($sResponse === false) {
		fwrite(STDERR, "no answer from $sUrl\n");
		exit(1);
	}
	$aOut = [];
	foreach ($http_response_header ?? [] as $sLine) {
		if (str_contains($sLine, ':')) {
			[$sName, $sValue] = explode(':', $sLine, 2);
			$aOut[strtolower(trim($sName))] = trim($sValue);
		}
	}
	$aDecoded = json_decode($sResponse, true);
	if (!is_array($aDecoded)) {
		fwrite(STDERR, 'not JSON: '.substr($sResponse, 0, 200)."\n");
		exit(1);
	}

	return ['headers' => $aOut, 'body' => $aDecoded];
}

function size(mixed $mValue): int
{
	return $mValue === null ? 0 : strlen((string)json_encode($mValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * @return array<string, mixed>
 */
function measure(string $sUrl, string $sToken): array
{
	$aInit = rpc($sUrl, $sToken, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
		'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'measure-surface', 'version' => '1'],
	]], null);
	if (isset($aInit['body']['error'])) {
		fwrite(STDERR, 'initialize refused: '.json_encode($aInit['body']['error'])."\n");
		exit(1);
	}
	$sSession = $aInit['headers']['mcp-session-id'] ?? null;
	$sInstructions = (string)($aInit['body']['result']['instructions'] ?? '');

	$aOut = ['instructions' => strlen($sInstructions), 'lists' => []];
	foreach (['tools' => 'tools/list', 'resources' => 'resources/list', 'resourceTemplates' => 'resources/templates/list', 'prompts' => 'prompts/list'] as $sKey => $sMethod) {
		$aItems = [];
		$sCursor = null;
		for ($i = 0; $i < 50; $i++) {
			$aParams = $sCursor === null ? new stdClass() : ['cursor' => $sCursor];
			$aAnswer = rpc($sUrl, $sToken, ['jsonrpc' => '2.0', 'id' => 10 + $i, 'method' => $sMethod, 'params' => $aParams], $sSession);
			$aResult = $aAnswer['body']['result'] ?? [];
			foreach ($aResult[$sKey] ?? [] as $aItem) {
				$aItems[] = $aItem;
			}
			$sCursor = $aResult['nextCursor'] ?? null;
			if ($sCursor === null) {
				break;
			}
		}
		$aRows = [];
		foreach ($aItems as $aItem) {
			$aRows[] = [
				'name'         => (string)($aItem['name'] ?? $aItem['uriTemplate'] ?? $aItem['uri'] ?? '?'),
				'description'  => size($aItem['description'] ?? null),
				'inputSchema'  => size($aItem['inputSchema'] ?? $aItem['arguments'] ?? null),
				'outputSchema' => size($aItem['outputSchema'] ?? null),
				'annotations'  => size($aItem['annotations'] ?? null),
				'total'        => size($aItem),
			];
		}
		$aOut['lists'][$sKey] = $aRows;
	}

	return $aOut;
}

$aReport = [];
foreach ($aTokens as $sLabel => $sToken) {
	$aReport[$sLabel] = measure($sUrl, $sToken);
}

if ($bJson) {
	echo json_encode($aReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
	exit(0);
}

foreach ($aReport as $sLabel => $aOne) {
	echo "## $sLabel\n\n";
	$iGrand = $aOne['instructions'];
	foreach ($aOne['lists'] as $sKey => $aRows) {
		if ($aRows === []) {
			echo "$sKey: none\n\n";
			continue;
		}
		echo "### $sKey (".count($aRows).")\n\n| name | description | inputSchema | outputSchema | annotations | total |\n|---|---:|---:|---:|---:|---:|\n";
		$aSum = ['description' => 0, 'inputSchema' => 0, 'outputSchema' => 0, 'annotations' => 0, 'total' => 0];
		usort($aRows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
		foreach ($aRows as $aRow) {
			printf("| %s | %d | %d | %d | %d | %d |\n", $aRow['name'], $aRow['description'], $aRow['inputSchema'], $aRow['outputSchema'], $aRow['annotations'], $aRow['total']);
			foreach ($aSum as $sPart => $_) {
				$aSum[$sPart] += $aRow[$sPart];
			}
		}
		printf("| **sum** | %d | %d | %d | %d | %d |\n\n", $aSum['description'], $aSum['inputSchema'], $aSum['outputSchema'], $aSum['annotations'], $aSum['total']);
		$iGrand += $aSum['total'];
	}
	printf("Instructions: %d bytes.\n\n**Total to be told what is offered: %d bytes, ~%d tokens (bytes / 4, an estimate).**\n\n", $aOne['instructions'], $iGrand, intdiv($iGrand, 4));
}
