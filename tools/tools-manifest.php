#!/usr/bin/env php
<?php
/**
 * The MCP definition of every core tool this release carries, as one sorted
 * JSON document, each with its own SHA-256.
 *
 * What it is for: a client that pins the tool definitions it is served (MCP
 * "tool pinning", OWASP MCP03 "rug pull") needs something to pin against, and
 * an operator needs to tell what a release changed in what models are told.
 * Run it on two releases and diff the output; run it on a release and compare
 * the hash of a tool with the hash of what a connection returns.
 *
 * Needs no iTop, no database and no network: the tools are plain classes and
 * the unit suite (ContextBudgetTest) instantiates them the same way. Needs
 * vendor/ (the MCP SDK's ToolAnnotations), so `composer install` first.
 *
 * What it is not: a copy of `tools/list` on a live connection. That list is a
 * subset, filtered per caller - token scope, toolsets, mcp_disabled_tools,
 * portal mode, excluded classes - and the descriptions of some tools differ in
 * portal mode. This is the console-mode definition, in English: titles are the
 * code's own literal, not a dictionary entry in the caller's language. The
 * README says the same where the release assets are listed.
 *
 * Deterministic on purpose: no timestamp, keys sorted at every depth (lists
 * keep their order), tools sorted by name, so the same tree produces the same
 * bytes and a diff means a definition changed.
 *
 * Usage: php tools/tools-manifest.php [> tools-manifest.json]
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sRoot = dirname(__DIR__);

if (!is_file($sRoot.'/vendor/autoload.php')) {
	fwrite(STDERR, "vendor/ is missing: run composer install first\n");
	exit(2);
}
require $sRoot.'/vendor/autoload.php';

/**
 * Keys sorted at every depth; a list (0..n-1 keys) keeps its order, since
 * "required" and "enum" are ordered by whoever wrote them and re-sorting would
 * hide a reordering a model could notice.
 */
function normalise(mixed $mValue): mixed
{
	if ($mValue instanceof JsonSerializable) {
		$mValue = $mValue->jsonSerialize();
	}
	if (!is_array($mValue)) {
		return $mValue;
	}
	$mValue = array_map('normalise', $mValue);
	if (!array_is_list($mValue)) {
		ksort($mValue, SORT_STRING);
	}

	return $mValue;
}

function canonical(mixed $mValue): string
{
	return json_encode(
		normalise($mValue),
		JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
	);
}

$sVersion = '';
if (preg_match('#<version>(.*?)</version>#', (string)file_get_contents($sRoot.'/extension.xml'), $aMatch) === 1) {
	$sVersion = $aMatch[1];
}

$aTools = [];
foreach (glob($sRoot.'/src/Core/Tools/*.php') ?: [] as $sFile) {
	$sShort = basename($sFile, '.php');
	if (str_starts_with($sShort, 'Abstract')) {
		continue;
	}
	$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sShort;
	$oTool = new $sClass();

	$aDefinition = [
		'name'         => $oTool->getQualifiedName(),
		'title'        => $oTool->getTitle(),
		'description'  => $oTool->getDescription(),
		'inputSchema'  => $oTool->getInputSchema(),
		'outputSchema' => $oTool->getOutputSchema(),
		'annotations'  => $oTool->getAnnotations(),
	];
	$aDefinition = normalise($aDefinition);

	$aTools[$aDefinition['name']] = [
		'sha256'     => hash('sha256', canonical($aDefinition)),
		'definition' => $aDefinition,
	];
}
ksort($aTools, SORT_STRING);

$aManifest = [
	'extension' => 'altioo-mcp',
	'version'   => $sVersion,
	'mode'      => 'console',
	// What the per-tool digests are over, so a client can recompute one.
	'digest'    => 'sha256 of the definition member, JSON-encoded with keys sorted at every depth, unescaped slashes and unicode, no whitespace (decode as objects, not arrays: an empty object must stay {})',
	'tools'     => $aTools,
];

echo json_encode(
	$aManifest,
	JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
), "\n";
