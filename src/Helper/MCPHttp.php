<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * Header adaptation between MCP clients and iTop's login stack.
 *
 * Carries no iTop dependency on purpose: it runs before DoLogin() and is
 * exercised by a unit test that never boots the application.
 */
final class MCPHttp
{
	/**
	 * Where authent-token reads its credential from - header "Auth-Token",
	 * see TokenLoginExtension::OnModeDetection().
	 */
	private const AUTH_TOKEN_KEY = 'HTTP_AUTH_TOKEN';

	/**
	 * The REDIRECT_ prefixed twin is what survives when the header reaches PHP
	 * through a mod_rewrite pass instead of directly.
	 */
	private const AUTHORIZATION_KEYS = ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'];

	private const BEARER_PREFIX = 'Bearer ';

	/** Named in the challenge so a human reading a 401 knows what refused them. */
	private const REALM = 'iTop MCP';

	/**
	 * The WWW-Authenticate challenge that goes with a 401.
	 *
	 * A 401 with no challenge tells a client that it failed, not what it
	 * failed at, and RFC 9110 requires one on the status anyway. The
	 * resource_metadata parameter is RFC 9728: it points a client at the
	 * document describing which authorization server protects this resource,
	 * which is what turns "the connect button does nothing" into a working
	 * OAuth flow.
	 *
	 * This module implements no OAuth and does not intend to - the deployment
	 * pattern is an OIDC-terminating proxy in front of iTop - but the proxy
	 * cannot inject the parameter into a 401 it never sees. Advertising the
	 * URL of a document someone else serves is the whole of what is needed
	 * here, so it is a setting rather than an implementation.
	 *
	 * @param string|null $sResourceMetadataUrl As configured, or null when nothing is.
	 */
	public static function BearerChallenge(?string $sResourceMetadataUrl): string
	{
		$sChallenge = 'Bearer realm="'.self::REALM.'"';

		$sUrl = self::SafeMetadataUrl($sResourceMetadataUrl);
		if ($sUrl !== null) {
			$sChallenge .= ', resource_metadata="'.$sUrl.'"';
		}

		return $sChallenge;
	}

	/**
	 * The configured URL, if it is one that can go in a header at all.
	 *
	 * It comes from the instance configuration rather than from the request,
	 * so this is not defending against an attacker - it is making a typo in
	 * config-itop.php produce no parameter rather than a split header.
	 */
	private static function SafeMetadataUrl(?string $sUrl): ?string
	{
		if ($sUrl === null) {
			return null;
		}

		$sUrl = trim($sUrl);
		if ($sUrl === '') {
			return null;
		}

		if (!preg_match('#^https?://[^\s"\'\\\\\x00-\x1F\x7F]+$#', $sUrl)) {
			return null;
		}

		return $sUrl;
	}

	/**
	 * Copies an "Authorization: Bearer <token>" credential to the header
	 * authent-token expects.
	 *
	 * MCP clients send the bearer form and many offer no way to set a custom
	 * header, while iTop's token login only ever looks at Auth-Token; without
	 * this the two never meet. Other schemes are left alone, so Basic still
	 * reaches LoginBasic untouched.
	 */
	public static function PromoteBearerToAuthToken(): void
	{
		$sToken = self::ReadBearerToken();
		if ($sToken === null) {
			return;
		}

		self::ForgetBearerHeaders();

		$sExisting = $_SERVER[self::AUTH_TOKEN_KEY] ?? '';
		if (is_string($sExisting) && $sExisting !== '') {
			return;
		}

		$_SERVER[self::AUTH_TOKEN_KEY] = $sToken;
	}

	/**
	 * Drops the header a bearer credential arrived in, once it has been read.
	 *
	 * LoginBasic claims the request on the mere presence of an Authorization
	 * header, without looking at its scheme, and then base64-decodes the bearer
	 * into binary garbage. Whether it gets there before the token plugin is
	 * decided by the order of allowed_login_types, so leaving the header in
	 * place would make authentication depend on how that list is written. No
	 * iTop login mode can consume a bearer, so nothing is lost by removing it.
	 */
	private static function ForgetBearerHeaders(): void
	{
		foreach (self::AUTHORIZATION_KEYS as $sKey) {
			if (self::ExtractBearer($_SERVER[$sKey] ?? null) !== null) {
				unset($_SERVER[$sKey]);
			}
		}
	}

	/**
	 * The bearer credential carried by the request, or null when there is none.
	 */
	public static function ReadBearerToken(): ?string
	{
		foreach (self::AUTHORIZATION_KEYS as $sKey) {
			$sToken = self::ExtractBearer($_SERVER[$sKey] ?? null);
			if ($sToken !== null) {
				return $sToken;
			}
		}

		// Some SAPIs expose Authorization to apache_request_headers() but not to
		// $_SERVER. Under FastCGI it may not reach PHP at all without a
		// webserver rule - see the README.
		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $sName => $sValue) {
				if (strcasecmp($sName, 'Authorization') !== 0) {
					continue;
				}

				$sToken = self::ExtractBearer(is_string($sValue) ? $sValue : null);
				if ($sToken !== null) {
					return $sToken;
				}
			}
		}

		return null;
	}

	private static function ExtractBearer(?string $sHeader): ?string
	{
		if ($sHeader === null) {
			return null;
		}

		$iPrefixLength = strlen(self::BEARER_PREFIX);
		if (strncasecmp($sHeader, self::BEARER_PREFIX, $iPrefixLength) !== 0) {
			return null;
		}

		$sToken = trim(substr($sHeader, $iPrefixLength));

		return $sToken === '' ? null : $sToken;
	}
}
