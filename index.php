<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\MCP\Controller\MCPController;

// This module's autoloader, before iTop's.
//
// Which is not the same as "wins". A Composer loader prepends itself when it
// registers, so of two loaders in one process the one registered *last*
// answers first - and iTop's registers below, on startup. Seven packages are
// carried by both trees (psr/*, webmozart/assert) and every one of them
// therefore runs at the version iTop ships, not the version this module's
// composer.lock records.
//
// That is deliberate rather than merely tolerated. Re-prepending this loader
// after startup would shadow iTop's own libraries inside core code written
// against them, and the versions cannot be pinned to iTop's either, because
// this module supports two iTop branches that do not ship the same ones. What
// is checked instead is that whatever runs satisfies what this module declares
// it needs - see VendoredDependencyResolutionTest, which fails on any overlap
// that stops being compatible.
if (!defined('ALTIOO_MCP_AUTOLOADER')) {
	require_once __DIR__.'/vendor/autoload.php';
	define('ALTIOO_MCP_AUTOLOADER', true);
}
require_once dirname(__DIR__, 2).'/approot.inc.php';

require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/loginwebpage.class.inc.php');
require_once(APPROOT.'/application/startup.inc.php');

MCPController::handleRequest();
