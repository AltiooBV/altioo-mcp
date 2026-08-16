<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * Header adaptation between MCP clients and iTop's login stack.
 *
 * Carries no iTop dependency on purpose: it runs before DoLogin() and is
 * exercised by a unit test that never boots the application.
 *
 * @since 1.0.0
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
	 * The one media type a JSON-RPC POST may carry.
	 *
	 * Not a formality: the three types a browser can send from a form or from
	 * fetch() without a preflight - text/plain, application/x-www-form-urlencoded
	 * and multipart/form-data - are exactly the ones this excludes. Requiring
	 * application/json makes every cross-origin call preflighted, and a
	 * preflight this endpoint does not answer is a call the browser never makes.
	 */
	public const JSON_MEDIA_TYPE = 'application/json';

	/**
	 * The entry in an allowed-hosts list that turns the Host check off.
	 *
	 * For a deployment whose hostname this module cannot know - app_root_url
	 * written with iTop's %SERVER_NAME% placeholder, or a reverse proxy that
	 * validates Host itself - the honest answer is to say so explicitly rather
	 * than to allow-list a hostname nobody has.
	 */
	public const ANY_HOST = '*';

	/**
	 * The credential this request presented, captured before login.
	 *
	 * Held here rather than read back out of $_SERVER, so the superglobal can
	 * be cleared as soon as iTop has finished with it - see ForgetAuthToken().
	 */
	private static ?string $sAuthToken = null;

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
	 * @since 1.0.0
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
	 *
	 * @since 1.0.0
	 */
	public static function PromoteBearerToAuthToken(): void
	{
		// Captured whichever header it arrived in, and captured unconditionally
		// so that one request cannot inherit the credential of the previous one
		// in a process that serves more than one.
		self::$sAuthToken = self::ExistingAuthToken();

		$sToken = self::ReadBearerToken();
		if ($sToken === null) {
			return;
		}

		self::ForgetBearerHeaders();

		if (self::$sAuthToken !== null) {
			return;
		}

		self::$sAuthToken = $sToken;
		$_SERVER[self::AUTH_TOKEN_KEY] = $sToken;
	}

	/**
	 * The credential this request presented, or null when it presented none.
	 *
	 * Everything downstream of login reads it here rather than from $_SERVER,
	 * which is what lets ForgetAuthToken() run before the PSR-7 request is
	 * built - and therefore what keeps the raw token out of that request's
	 * server parameters, where it would otherwise sit for the whole call.
	 *
	 * @since 1.0.0
	 */
	public static function CurrentAuthToken(): ?string
	{
		return self::$sAuthToken;
	}

	/**
	 * Drops the credential, once nothing else needs it.
	 *
	 * Called after iTop has authenticated the caller and the token's scopes
	 * have been read - the two things that need it - and before the request is
	 * handed to the SDK. A token that no longer exists anywhere cannot be
	 * copied into a request object, a stack trace or a var_dump.
	 *
	 * @since 1.0.0
	 */
	public static function ForgetAuthToken(): void
	{
		self::$sAuthToken = null;
		unset($_SERVER[self::AUTH_TOKEN_KEY]);
	}

	/** The Auth-Token header as it arrived, or null when there is none. */
	private static function ExistingAuthToken(): ?string
	{
		$sExisting = $_SERVER[self::AUTH_TOKEN_KEY] ?? '';

		return is_string($sExisting) && $sExisting !== '' ? $sExisting : null;
	}

	/**
	 * Whether the request reached this instance under a hostname it serves.
	 *
	 * The same rule the SDK's DnsRebindingProtectionMiddleware applies, decided
	 * here as well because it has to run *before* LoginWebPage::ResetSession():
	 * the reset is unauthenticated and happens on every request, so a page on
	 * any website could otherwise make a logged-in user's browser call this
	 * endpoint and end their iTop session. Checking the origin first turns that
	 * into a refusal that touches nothing.
	 *
	 * Origin decides when it is present, Host when it is not - matching the
	 * middleware exactly, so the two cannot reach opposite conclusions about
	 * the same request.
	 *
	 * @param array<int, string> $aAllowedHosts Hostnames without port, or [ANY_HOST] to accept any.
	 * @since 1.0.0
	 */
	public static function IsAllowedHost(?string $sOrigin, ?string $sHost, array $aAllowedHosts): bool
	{
		if (in_array(self::ANY_HOST, $aAllowedHosts, true)) {
			return true;
		}

		$aAllowedHosts = array_map('strtolower', $aAllowedHosts);

		if (is_string($sOrigin) && $sOrigin !== '') {
			$sOriginHost = parse_url($sOrigin, PHP_URL_HOST);

			return is_string($sOriginHost)
				&& $sOriginHost !== ''
				&& in_array(strtolower($sOriginHost), $aAllowedHosts, true);
		}

		if (!is_string($sHost) || $sHost === '') {
			// No Host at all is HTTP/1.0, which no MCP client speaks; the
			// middleware lets it through, and so does this.
			return true;
		}

		return in_array(strtolower(self::HostnameOf($sHost)), $aAllowedHosts, true);
	}

	/**
	 * A Host header without its port. IPv6 literals keep their brackets, which
	 * is the form parse_url() returns and therefore the form a list is written in.
	 *
	 * @since 1.0.0
	 */
	public static function HostnameOf(string $sHost): string
	{
		if (str_starts_with($sHost, '[')) {
			$iClosing = strpos($sHost, ']');

			return $iClosing === false ? $sHost : substr($sHost, 0, $iClosing + 1);
		}

		return explode(':', $sHost, 2)[0];
	}

	/**
	 * Whether a POST body announces itself as JSON.
	 *
	 * Parameters are ignored, so "application/json; charset=utf-8" passes: the
	 * point is not the exact spelling but that the caller could not have sent
	 * this without a preflight.
	 *
	 * @since 1.0.0
	 */
	public static function IsJsonMediaType(?string $sContentType): bool
	{
		if (!is_string($sContentType)) {
			return false;
		}

		$sMediaType = strtolower(trim(explode(';', $sContentType, 2)[0]));

		// application/vnd.foo+json is still JSON, and some clients send it.
		return $sMediaType === self::JSON_MEDIA_TYPE || str_ends_with($sMediaType, '+json');
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
	 *
	 * @since 1.0.0
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
