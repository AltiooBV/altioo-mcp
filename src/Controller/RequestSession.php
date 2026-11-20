<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

/**
 * Whose the PHP session open at the start of a request is.
 *
 * iTop's startup opens a session before this module's first line runs, and when the
 * request carries a cookie that names an existing session, it is that session, the one
 * a console user's browser holds. The endpoint ends the session the login opened for
 * the request (MCPController::discardRequestSession); ending the browser's own would
 * log its user out of the console, and any web page can make a browser send a request
 * to this URL with its cookies (a top-level GET, a cross-site form POST). So the
 * session is only the request's to end when the request created it, or when the login
 * has run and replaced what the browser's session held.
 *
 * @internal
 * @since 2.1.0
 */
final class RequestSession
{
	/**
	 * True when the session open now is one a client's cookie named and that holds
	 * something: a session somebody else is using, which this request did not create.
	 *
	 * A cookie naming a session that held nothing is not one: PHP (with `use_strict_mode`
	 * off, its default) adopts any identifier a client sends and creates the empty session
	 * under it, so a request that sends invented cookies would otherwise leave a session
	 * file behind for each, which is what ending the request's session is there to prevent.
	 *
	 * @param array<mixed> $aCookies $_COOKIE
	 * @param array<mixed> $aSessionData $_SESSION as it was when the request began, before any gate touched it
	 */
	public static function IsResumed(array $aCookies, string $sSessionName, string $sSessionId, array $aSessionData): bool
	{
		if ($sSessionId === '' || $sSessionName === '') {
			return false;
		}

		$mCookie = $aCookies[$sSessionName] ?? null;

		return is_string($mCookie) && hash_equals($sSessionId, $mCookie) && $aSessionData !== [];
	}

	/**
	 * IsResumed() for the session this request has open, from the superglobals.
	 */
	public static function CurrentIsResumed(): bool
	{
		// Not $_SESSION itself: it does not exist until a session has been started.
		$mSession = session_status() === PHP_SESSION_ACTIVE ? ($GLOBALS['_SESSION'] ?? []) : [];
		$aSession = is_array($mSession) ? $mSession : [];

		return self::IsResumed($_COOKIE, session_name(), session_id(), $aSession);
	}
}
