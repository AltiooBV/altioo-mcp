<?php

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
