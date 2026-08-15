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
