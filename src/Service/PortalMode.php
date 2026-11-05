<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

/**
 * Whether this request serves a portal-only user, and through which portal.
 *
 * iTop's login turns a user with no console away from this endpoint, and
 * until portal mode that refusal was the end of it. A portal user's rights
 * are not what UserRights says they are: the "Portal user" profile reads
 * every bizmodel class, and what narrows it - OQL scopes, form field lists,
 * lifecycle - lives in the portal's own configuration, which UserRights never
 * consults. Serving that user the console's tools would hand them the whole
 * instance. So portal mode is decided here, once, and the surface it opens is
 * decided separately and from nothing: an element is served in portal mode
 * only when it enforces the portal's rules itself, through PortalPolicy, and
 * says so ({@see Serves()}).
 *
 * One portal per request. A user may be allowed into several, and the union
 * of two portals' scopes is wider than either, so they are never merged: the
 * first portal by rank that is both allowed to the user and listed in
 * mcp_portals is the one, which is also the portal iTop's own login sends
 * them to when it is the only one listed. The default, "*", lists them all:
 * who is let in is decided by the profiles they hold, as it is for a console
 * user, and the portal they get is the one iTop's login would give them.
 *
 * The decisions are pure and tested without iTop; the controller gathers
 * their inputs.
 *
 * @since 1.2.0
 */
final class PortalMode
{
	/** No portal is both allowed to the user and listed in mcp_portals. */
	public const REFUSED_NO_SERVED_PORTAL = 'no_served_portal';

	/** The login was not a token: Basic authentication, or a proxy. */
	public const REFUSED_NOT_A_TOKEN = 'not_a_token';

	/** The token never expires, or expires later than mcp_portal_token_max_days allows. */
	public const REFUSED_TOKEN_UNBOUNDED = 'token_unbounded';

	/** In mcp_portals, every portal the user is allowed into. */
	public const ANY_PORTAL = '*';

	/** iTop's console, which a portal-mode user is by definition not allowed into. */
	private const CONSOLE = 'backoffice';

	/** The user is linked to no contact, which every portal requires. */
	public const REFUSED_NO_CONTACT = 'no_contact';

	/** The user holds none of the profiles in mcp_allowed_profiles. */
	public const REFUSED_NO_MCP_PROFILE = 'no_mcp_profile';

	/**
	 * The portal of the request being served, or null for a console request.
	 *
	 * Static for the reason AccessPolicy::Current() is: one request per
	 * process, decided once by the controller, read wherever the surface is
	 * built.
	 */
	private static ?string $sCurrent = null;

	/**
	 * The parameter of the endpoint URL that names a portal, as iTop's own
	 * portal URLs name it (doc/adr/0007-the-connection-names-its-portal.md).
	 */
	public const PARAMETER = 'portal_id';

	/**
	 * Whether a value is shaped like a portal id.
	 *
	 * What the URL may name, and what goes into a file name: the id names the
	 * portal's design file, its lock and the compiled rules iTop then requires.
	 *
	 * @since 1.2.1
	 */
	public static function IsPortalId(string $sValue): bool
	{
		return preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $sValue) === 1;
	}

	/**
	 * The portal to serve a user in, or null when there is none.
	 *
	 * The one the connection names, when it names one: the user must be
	 * allowed into it and mcp_portals must serve it, and when either fails
	 * there is none - never another portal in its place, which would be rules
	 * the person did not choose. Otherwise the first by rank.
	 *
	 * @param array<int, string> $aAllowed   Portal ids the user is allowed into, in rank order, as iTop lists them.
	 * @param array<int, string> $aServed    mcp_portals.
	 * @param string|null        $sRequested The portal the connection names, or null.
	 */
	public static function Choose(array $aAllowed, array $aServed, ?string $sRequested = null): ?string
	{
		$bAny = in_array(self::ANY_PORTAL, $aServed, true);

		foreach ($aAllowed as $sPortal) {
			if ($sPortal !== self::CONSOLE
				&& ($bAny || in_array($sPortal, $aServed, true))
				&& ($sRequested === null || $sPortal === $sRequested)) {
				return $sPortal;
			}
		}

		return null;
	}

	/**
	 * The first condition of portal mode this request fails, or null when it
	 * may be served.
	 *
	 * A token, because it is the credential this module can grade: Basic
	 * authentication carries no scope, and a portal session's password is the
	 * one thing that should not be typed into an assistant's configuration. A
	 * token that ends, because a portal user's token is one they may have made
	 * themselves: once their profile is in personal_tokens_allowed_profiles,
	 * which every personal token needs, authent-token lets their portal
	 * session mint one with any expiry, none included, and a forgotten token
	 * in an assistant's configuration is a key to their portal that never
	 * stops working. A contact, because the portal refuses a user without one
	 * and its scopes are written against :current_contact_id. And the MCP
	 * profile whatever secure_mcp_services says, because switching the profile
	 * check off for console users was never a decision about people with no
	 * console.
	 *
	 * @param string|null $sPortal       What Choose() returned.
	 * @param bool        $bTokenBounded What TokenIsBounded() answered for the token presented.
	 */
	public static function RefusalOf(?string $sPortal, bool $bTokenLogin, bool $bTokenBounded, bool $bLinkedToContact, bool $bHoldsMCPProfile): ?string
	{
		return match (true) {
			$sPortal === null  => self::REFUSED_NO_SERVED_PORTAL,
			!$bTokenLogin      => self::REFUSED_NOT_A_TOKEN,
			!$bTokenBounded    => self::REFUSED_TOKEN_UNBOUNDED,
			!$bLinkedToContact => self::REFUSED_NO_CONTACT,
			!$bHoldsMCPProfile => self::REFUSED_NO_MCP_PROFILE,
			default            => null,
		};
	}

	/**
	 * Whether a token's expiry falls within the bound portal mode allows.
	 *
	 * No expiry is unbounded, and so is one beyond the bound from now -
	 * whenever it was set: what is checked is how long the credential can
	 * still be used, which is the exposure. A bound of 0 is no bound, for an
	 * operator who decides otherwise and says so in mcp_portal_token_max_days.
	 *
	 * @param string|null $sExpiration The token's expiration_date as iTop stores it ('Y-m-d H:i:s'), or null.
	 * @param int         $iNow        A Unix time.
	 *
	 * @since 1.2.0
	 */
	public static function TokenIsBounded(?string $sExpiration, int $iNow, int $iMaxDays): bool
	{
		if ($iMaxDays === 0) {
			return true;
		}

		if ($sExpiration === null || trim($sExpiration) === '') {
			return false;
		}

		$iExpiration = strtotime($sExpiration);

		return $iExpiration !== false && $iExpiration <= $iNow + $iMaxDays * 86400;
	}

	/** @since 1.2.0 */
	public static function Remember(string $sPortal): void
	{
		self::$sCurrent = $sPortal;
	}

	/** @since 1.2.0 */
	public static function Current(): ?string
	{
		return self::$sCurrent;
	}

	/** For tests, and for a runner that serves more than one request. @since 1.2.0 */
	public static function Forget(): void
	{
		self::$sCurrent = null;
		PortalPolicy::Forget();
	}

	/**
	 * Whether an element may be served in portal mode at all, before any of
	 * the filters every element goes through.
	 *
	 * Only one that declares it enforces the portal's rules ({@see PortalAware})
	 * and is this module's own. The namespace is the half a pack cannot
	 * satisfy by accident: PortalPolicy is not yet on the surface a pack may
	 * build on, so a pack element claiming the declaration would be a console
	 * element reaching the portal.
	 *
	 * @since 1.2.0
	 */
	public static function Serves(object $oElement): bool
	{
		return $oElement instanceof PortalAware && str_starts_with(get_class($oElement), self::CORE_NAMESPACE);
	}

	/** Where this module's own elements live. */
	private const CORE_NAMESPACE = 'Altioo\\iTop\\Extension\\MCP\\Core\\';
}
