<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
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
	 * Hostnames this endpoint answers to, checked against Origin - or against
	 * Host when there is no Origin - before anything else happens.
	 *
	 * Left empty it is derived rather than defaulted, because the alternative
	 * defaults are both wrong: the SDK's own list is localhost only, which
	 * refuses every production request, and accepting anything gives up the
	 * check. An installed iTop already knows the name it is served under, so
	 * that is what the derivation reads.
	 */
	const MODULE_SETTING_ALLOWED_HOSTS = 'mcp_allowed_hosts';

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
	 * Toolsets served by this instance. Empty means every toolset, which is
	 * the right default for an instance that has not been asked to narrow.
	 */
	const MODULE_SETTING_ENABLED_TOOLSETS = 'mcp_enabled_toolsets';

	/**
	 * What this instance allows, for everyone: any of read, write and delete.
	 * Empty means all three.
	 */
	const MODULE_SETTING_CAPABILITIES = 'mcp_capabilities';

	/** Shorthand for mcp_capabilities = array('read'). */
	const MODULE_SETTING_READ_ONLY = 'mcp_read_only';

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
	 * What this instance allows, for everyone.
	 *
	 * mcp_read_only is shorthand: turning the whole endpoint read-only is what
	 * an operator wants to do in one line and without looking anything up, and
	 * spelling it as a list of grades is not that. It narrows rather than
	 * overrides, so setting both cannot come out wider than either.
	 *
	 * @return array<int, string> Granted grades, or an empty list for all of them.
	 */
	public static function GetCapabilities(): array
	{
		$aCapabilities = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_CAPABILITIES, []);
		if (!is_array($aCapabilities)) {
			$sType = gettype($aCapabilities);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_CAPABILITIES."' should be an array instead of $sType");
			$aCapabilities = [];
		}

		$aCapabilities = array_values(array_filter($aCapabilities, 'is_string'));

		if (self::IsReadOnly()) {
			$aCapabilities = empty($aCapabilities)
				? [AccessPolicy::CAPABILITY_READ]
				: array_values(array_intersect($aCapabilities, [AccessPolicy::CAPABILITY_READ]));
		}

		return $aCapabilities;
	}

	/**
	 * Whether this instance serves reads only.
	 */
	public static function IsReadOnly(): bool
	{
		return utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_READ_ONLY, false) === true;
	}

	/**
	 * Toolsets the operator turned on, or an empty list meaning all of them.
	 *
	 * The positive counterpart to mcp_disabled_tools: that one names what must
	 * go, this one names what may stay. Naming what may stay is what an
	 * operator wants when a pack they did not write adds tools they have not
	 * read, since a tool added by an update is off until someone says
	 * otherwise.
	 *
	 * @return array<int, string>
	 */
	public static function GetEnabledToolsets(): array
	{
		$aToolsets = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ENABLED_TOOLSETS, []);
		if (!is_array($aToolsets)) {
			$sType = gettype($aToolsets);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ENABLED_TOOLSETS."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aToolsets, 'is_string'));
	}

	/**
	 * Browser origins allowed to read this endpoint's responses.
	 *
	 * @return array<int, string>
	 */
	public static function GetAllowedOrigins(): array
	{
		$aOrigins = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ALLOWED_ORIGINS, []);
		if (!is_array($aOrigins)) {
			$sType = gettype($aOrigins);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_ORIGINS."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aOrigins, 'is_string'));
	}

	/**
	 * Hostnames this endpoint answers to.
	 *
	 * What the operator wrote, if they wrote anything: an explicit list is a
	 * decision, and second-guessing it by adding to it would make the setting
	 * unable to express "only this name".
	 *
	 * @return array<int, string> Hostnames without port, or [MCPHttp::ANY_HOST] for no check.
	 */
	public static function GetAllowedHosts(): array
	{
		$aHosts = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ALLOWED_HOSTS, []);
		if (!is_array($aHosts)) {
			$sType = gettype($aHosts);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_HOSTS."' should be an array instead of $sType");
			$aHosts = [];
		}

		$aHosts = array_values(array_filter($aHosts, 'is_string'));

		return empty($aHosts) ? self::DerivedAllowedHosts() : $aHosts;
	}

	/**
	 * The hostnames an instance that configured none is served under.
	 *
	 * Three sources, and each is there because leaving it out breaks a
	 * deployment that works today: app_root_url is the name the setup recorded
	 * and the one production traffic arrives on; the localhost variants keep a
	 * developer, the docker image and an on-box health check working; and an
	 * origin already allow-listed for CORS is by definition one this endpoint
	 * is meant to answer, so refusing its host here would contradict the other
	 * setting.
	 *
	 * @return array<int, string>
	 */
	private static function DerivedAllowedHosts(): array
	{
		$sAppRootUrl = utils::GetConfig()->Get('app_root_url');
		$sAppRootUrl = is_string($sAppRootUrl) ? trim($sAppRootUrl) : '';

		if ($sAppRootUrl !== '' && str_contains($sAppRootUrl, SERVER_NAME_PLACEHOLDER)) {
			// app_root_url written with iTop's placeholder says the instance
			// answers to whatever name it is reached at. There is no hostname
			// to check against, and inventing one would refuse valid traffic.
			return [MCPHttp::ANY_HOST];
		}

		$aHosts = ['localhost', '127.0.0.1', '[::1]'];

		foreach (array_merge([$sAppRootUrl], self::GetAllowedOrigins()) as $sUrl) {
			if ($sUrl === '') {
				continue;
			}

			$sHost = parse_url($sUrl, PHP_URL_HOST);
			if (is_string($sHost) && $sHost !== '') {
				$aHosts[] = $sHost;
			}
		}

		return array_values(array_unique($aHosts));
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
