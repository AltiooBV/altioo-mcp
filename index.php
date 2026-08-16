<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\MCP\Controller\MCPController;

if (!defined('ALTIOO_MCP_AUTOLOADER')) {
	require_once __DIR__ . '/vendor/autoload.php';
	define('ALTIOO_MCP_AUTOLOADER', true);
}
require_once dirname(__DIR__, 2) . '/approot.inc.php';

require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/loginwebpage.class.inc.php');
require_once(APPROOT.'/application/startup.inc.php');

MCPController::handleRequest();
