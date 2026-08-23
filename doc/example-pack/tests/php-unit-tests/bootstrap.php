<?php
/**
 * Test bootstrap.
 *
 * The contract suite needs two autoloaders. This pack's own, for the tools and
 * the prompt; and the base extension's, for
 * Altioo\iTop\Extension\MCP\Testing\ElementContract and the abstracts the tools
 * extend. The base is not a Composer dependency of this pack, for the same
 * reason mcp/sdk is in require-dev - iTop loads the base's datamodel files
 * before this module's, so at runtime everything is already registered, and a
 * second copy of either would be a second set of classes racing the first.
 *
 * The base is found where an installed instance actually keeps it, which is
 * beside this module under extensions/. Set ALTIOO_MCP_ROOT if it is elsewhere.
 *
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sPackRoot = dirname(__DIR__, 2);

require_once $sPackRoot.'/vendor/autoload.php';

/**
 * The base extension's root, or null when it cannot be located.
 */
function acme_servicedesk_locate_base(string $sPackRoot): ?string
{
	$aCandidates = [];

	$sFromEnv = getenv('ALTIOO_MCP_ROOT');
	if (is_string($sFromEnv) && $sFromEnv !== '') {
		$aCandidates[] = rtrim($sFromEnv, '/');
	}

	// extensions/acme-servicedesk -> extensions/altioo-mcp: where the setup
	// puts both once this pack has been copied out of the documentation.
	$aCandidates[] = dirname($sPackRoot).'/altioo-mcp';
	// doc/example-pack, still inside the base extension it documents.
	$aCandidates[] = dirname($sPackRoot, 2);

	foreach ($aCandidates as $sCandidate) {
		if (file_exists($sCandidate.'/vendor/autoload.php')) {
			return $sCandidate;
		}
	}

	return null;
}

$sBaseRoot = acme_servicedesk_locate_base($sPackRoot);
if ($sBaseRoot === null) {
	fwrite(STDERR, <<<TXT
	The base extension was not found.

	This pack's tests use Altioo\iTop\Extension\MCP\Testing\ElementContract,
	which ships with altioo-mcp. Put this module beside it under extensions/,
	or set ALTIOO_MCP_ROOT to where altioo-mcp is.

	TXT);
	exit(1);
}

// After this pack's own loader, not before: mcp/sdk is in this pack's
// require-dev and in the base's require, and whichever loader is consulted
// first is the one copy the whole run uses. Loading the base second keeps a
// test run resolving the SDK the same way a request does - through the
// autoloader that was registered first.
require_once $sBaseRoot.'/vendor/autoload.php';
