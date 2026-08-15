<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace Altioo\iTop\Extension\MCP\Helper;

class MCPHelper
{
	const MODULE_NAME = 'altioo-mcp';

	const MODULE_SETTING_LOG = 'log_mcp_service';
	const DEFAULT_LOG_SETTING = false;
	const MODULE_SETTING_LOG_METHOD = 'log_mcp_method';

	const MODULE_SETTING_LOG_LEVEL = 'log_mcp_level';
	const DEFAULT_LOG_LEVEL = 'error';
	const LOG_LEVEL_INFO = 'info';
	const LOG_LEVEL_DEBUG = 'debug';
	const LOG_LEVEL_ERROR = 'error';

	const MCP_METHOD_PARAM = 'error';

	public function __construct()
	{
		MCPLog::Enable(APPROOT.'log/error.log');
	}
}
