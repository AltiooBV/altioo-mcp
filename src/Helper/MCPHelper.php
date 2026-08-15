<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use utils;

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

	const MODULE_SETTING_ALLOWED_ORIGINS = 'mcp_allowed_origins';

	/**
	 * Operator kill switch: names of tools and prompts, and URIs of resources
	 * and resource templates, that must never be advertised nor callable.
	 * Sits next to mcp_allowed_profiles as the other operator-side gate.
	 */
	const MODULE_SETTING_DISABLED = 'mcp_disabled_tools';

	const MCP_METHOD_PARAM = 'error';

	public function __construct()
	{
		MCPLog::Enable(APPROOT.'log/error.log');
	}

	/**
	 * Server-side error channel.
	 *
	 * Detail that must not reach the caller goes here: the response gets a
	 * generic message, the log gets everything.
	 */
	public static function LogError(string $sMessage, array $aContext = []): void
	{
		MCPLog::Error($sMessage, null, $aContext);
	}

	/**
	 * Identifiers disabled by the operator, normalised to a list of strings.
	 *
	 * @return array<int, string>
	 */
	public static function GetDisabledIdentifiers(): array
	{
		$aDisabled = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_DISABLED, []);
		if (!is_array($aDisabled)) {
			$sType = gettype($aDisabled);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_DISABLED."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aDisabled, 'is_string'));
	}
}
