<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use MetaModel;
use utils;

/**
 * Where this endpoint is as an OAuth protected resource, and the optional altioo-oauth-server module's API for it.
 *
 * @internal
 * @since 2.0.0
 */
class OAuthResource
{
	/**
	 * A class altioo-oauth-server's datamodel defines, read as "it is
	 * installed": a compiled class is what an installed module leaves, and
	 * asking costs no query.
	 */
	private const OAUTH_SERVER_CLASS = 'AltiooOAuthClient';

	/**
	 * That module's API for the metadata of the resource at a URL, and for
	 * which resource a token was granted for. Named as strings, never
	 * imported: the module is optional (doc/adr/0003).
	 */
	private const OAUTH_RESOURCE_METADATA_CLASS = '\\Altioo\\iTop\\Extension\\OAuthServer\\ResourceMetadata';
	public const TOKEN_AUDIENCE_CLASS = '\\Altioo\\iTop\\Extension\\OAuthServer\\TokenAudience';

	/**
	 * The URL this request called the endpoint at, as an OAuth resource is
	 * configured with it: the compiled environment's, with portal_id when the
	 * request names a portal. One resource per portal is one URL per portal
	 * (doc/adr/0007), and an authorization server compares them exactly.
	 *
	 * @since 1.2.0
	 */
	public static function CalledEndpointUrl(): string
	{
		$sUrl = rtrim(utils::GetAbsoluteUrlModulesRoot(), '/').'/'.MCPModule::MODULE_NAME.'/index.php';
		$sPortal = PortalMode::Requested();

		return $sPortal === null ? $sUrl : $sUrl.'?'.PortalMode::PARAMETER.'='.rawurlencode($sPortal);
	}

	/**
	 * The protected-resource metadata URL to advertise, or null.
	 *
	 * The setting when it is set. Otherwise, when altioo-oauth-server is
	 * installed beside this module, the document it serves for this endpoint
	 * - which is what its README tells an operator to copy into the setting,
	 * so an install of both connects from a "Connect" button with nothing
	 * typed. That module stays optional in both directions (doc/adr/0003):
	 * without it, nothing changes.
	 *
	 * The document for the URL this request was made at, when that module
	 * has a resource configured with exactly it: a client that called one
	 * portal's URL is sent to consent for that portal (doc/adr/0007).
	 *
	 * @since 1.0.0
	 */
	public static function MetadataUrl(): ?string
	{
		$bOAuthServerInstalled = class_exists('MetaModel') && MetaModel::IsValidClass(self::OAUTH_SERVER_CLASS);

		$mForCalledUrl = $bOAuthServerInstalled && class_exists(self::OAUTH_RESOURCE_METADATA_CLASS)
			? call_user_func([self::OAUTH_RESOURCE_METADATA_CLASS, 'UrlFor'], self::CalledEndpointUrl())
			: null;

		return self::metadataUrlFor(
			utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_RESOURCE_METADATA, ''),
			$bOAuthServerInstalled,
			utils::GetAbsoluteUrlModulesRoot(),
			is_string($mForCalledUrl) ? $mForCalledUrl : null
		);
	}

	/**
	 * The decision, without iTop.
	 *
	 * @param mixed       $mConfigured  The setting, as the configuration holds it.
	 * @param string      $sModulesRoot utils::GetAbsoluteUrlModulesRoot(), the compiled environment's URL.
	 * @param string|null $sForCalledUrl What that module serves for the URL called, when it knows it.
	 */
	private static function metadataUrlFor(mixed $mConfigured, bool $bOAuthServerInstalled, string $sModulesRoot, ?string $sForCalledUrl = null): ?string
	{
		if (is_string($mConfigured) && $mConfigured !== '') {
			return $mConfigured;
		}

		if (!$bOAuthServerInstalled || $sModulesRoot === '') {
			return null;
		}

		if ($sForCalledUrl !== null && $sForCalledUrl !== '') {
			return $sForCalledUrl;
		}

		return rtrim($sModulesRoot, '/').'/altioo-oauth-server/index.php?route=protected-resource&for=mcp';
	}
}
