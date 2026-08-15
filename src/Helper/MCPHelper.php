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

	/**
	 * Single source of truth for the module version.
	 *
	 * extension.xml and module.altioo-mcp.php are read by the iTop setup before
	 * this module's autoloader exists, so they carry the same string literally;
	 * ModuleMetadataTest pins the three together.
	 *
	 * Semver applies to what a downstream tool pack can touch: the abstracts
	 * (AbstractMCPTool / Resource / ResourceTemplate / Prompt), MCPRegistry,
	 * MCPExtensionCollector, iMCPServiceProvider and the helpers under
	 * Helper/. A breaking change to any of those is a major bump; a new
	 * optional hook with a default implementation is a minor one.
	 */
	const VERSION = '1.0.0';

	const MODULE_SETTING_LOG = 'log_mcp_service';
	const DEFAULT_LOG_SETTING = true;
	const MODULE_SETTING_LOG_METHOD = 'log_mcp_method';

	const MODULE_SETTING_LOG_LEVEL = 'log_mcp_level';
	const DEFAULT_LOG_LEVEL = 'error';
	const LOG_LEVEL_INFO = 'info';
	const LOG_LEVEL_DEBUG = 'debug';
	const LOG_LEVEL_ERROR = 'error';

	const MODULE_SETTING_ALLOWED_ORIGINS = 'mcp_allowed_origins';

	/**
	 * How many elements one tools/list, resources/list or prompts/list page
	 * carries.
	 *
	 * The SDK defaults to 50 and pages the rest behind a cursor. That is
	 * correct protocol and a trap in practice: a client that does not follow
	 * nextCursor - and several do not - simply never sees the 51st tool, with
	 * no error anywhere. The base extension alone is nowhere near that, but an
	 * instance with three tool packs installed is, and the operator would have
	 * no way to tell what happened.
	 *
	 * Set high enough that a normal installation is one page, and left
	 * configurable for the ones that are not.
	 */
	const MODULE_SETTING_PAGINATION_LIMIT = 'mcp_pagination_limit';
	const DEFAULT_PAGINATION_LIMIT = 200;

	/**
	 * URL of the RFC 9728 protected-resource metadata document, when an
	 * OAuth-terminating proxy in front of iTop serves one. Advertised in the
	 * WWW-Authenticate challenge of a 401; empty means no such parameter.
	 */
	const MODULE_SETTING_RESOURCE_METADATA = 'mcp_protected_resource_metadata';

	/**
	 * Operator kill switch: names of tools and prompts, and URIs of resources
	 * and resource templates, that must never be advertised nor callable.
	 * Sits next to mcp_allowed_profiles as the other operator-side gate.
	 */
	const MODULE_SETTING_DISABLED = 'mcp_disabled_tools';

	/**
	 * Pseudo-method stamped on the audit row when the request died before its
	 * JSON-RPC method could be read. Must match an entry of log_mcp_method,
	 * otherwise exceptions are silently dropped from the audit trail.
	 */
	const MCP_METHOD_EXCEPTION = 'exceptions';

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
	 * The protected-resource metadata URL to advertise, or null.
	 */
	public static function GetProtectedResourceMetadataUrl(): ?string
	{
		$sUrl = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_RESOURCE_METADATA, '');

		return is_string($sUrl) && $sUrl !== '' ? $sUrl : null;
	}

	/**
	 * Elements per listing page, as configured.
	 */
	public static function GetPaginationLimit(): int
	{
		$iLimit = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_PAGINATION_LIMIT, self::DEFAULT_PAGINATION_LIMIT);
		if (!is_int($iLimit) || $iLimit < 1) {
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_PAGINATION_LIMIT."' should be a positive integer");

			return self::DEFAULT_PAGINATION_LIMIT;
		}

		return $iLimit;
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
