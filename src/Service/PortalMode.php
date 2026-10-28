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
 * them to when it is the only one listed.
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
	 * The portal to serve a user in, or null when there is none.
	 *
	 * @param array<int, string> $aAllowed Portal ids the user is allowed into, in rank order, as iTop lists them.
	 * @param array<int, string> $aServed  mcp_portals.
	 */
	public static function Choose(array $aAllowed, array $aServed): ?string
	{
		foreach ($aAllowed as $sPortal) {
			if (in_array($sPortal, $aServed, true)) {
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
	 * contact, because the portal refuses a user without one and its scopes
	 * are written against :current_contact_id. And the MCP profile whatever
	 * secure_mcp_services says, because switching the profile check off for
	 * console users was never a decision about people with no console.
	 *
	 * @param string|null $sPortal What Choose() returned.
	 */
	public static function RefusalOf(?string $sPortal, bool $bTokenLogin, bool $bLinkedToContact, bool $bHoldsMCPProfile): ?string
	{
		return match (true) {
			$sPortal === null  => self::REFUSED_NO_SERVED_PORTAL,
			!$bTokenLogin      => self::REFUSED_NOT_A_TOKEN,
			!$bLinkedToContact => self::REFUSED_NO_CONTACT,
			!$bHoldsMCPProfile => self::REFUSED_NO_MCP_PROFILE,
			default            => null,
		};
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
