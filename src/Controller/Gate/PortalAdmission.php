<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Controller\CallLog;
use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Combodo\iTop\Application\Helper\Session;
use LoginWebPage;
use MetaModel;
use UserRights;

/**
 * Lets a user with no console in under portal mode, or leaves the console
 * refusal as it was.
 *
 * Asked once, by the login gate, after iTop has refused a portal-only user the
 * console. It is what calls PortalAccess::Enter() (doc/adr/0007), and so is the
 * one place a request enters a portal.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalAdmission
{
	/**
	 * Lets a user with no console in under portal mode, or leaves the console
	 * refusal as it was.
	 *
	 * Reached only after DoLogin() returned EXIT_CODE_PORTALUSERNOTAUTHORIZED,
	 * which iTop returns from ChangeLocation(), after the login itself has
	 * succeeded - so the user is authenticated here, and UserRights answers
	 * for them. Taking portal mode from that one refusal, rather than logging
	 * in with DoLoginEx(null) and sorting users afterwards, is what keeps
	 * every console login on the path it has always taken.
	 *
	 * With mcp_portals empty nothing is read and the refusal stands; its
	 * default, "*", serves every portal, so a portal-only user holding the
	 * MCP profile is let in by that profile. A user in none of the listed
	 * portals gets the refusal unchanged, with a log line saying why. The other failures are portal mode's own and are told to
	 * the caller as what to change, on the usual split: the caller learns what
	 * to fix, the log names who.
	 *
	 * @throws MCPAuthException For a refusal that is portal mode's own.
	 */
	public static function Admit(): int
	{
		$aServed = Settings::GetServedPortals();
		if ($aServed === []) {
			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		$sRequested = PortalMode::Requested();
		$sPortal = PortalMode::Choose(array_column(UserRights::GetAllowedPortals(), 'id'), $aServed, $sRequested);
		$iMaxDays = Settings::GetPortalTokenMaxDays();
		$sRefusal = PortalMode::RefusalOf(
			$sPortal,
			TokenScopes::LoginWasAToken(Session::Get('login_mode')),
			PortalMode::TokenIsBounded(self::presentedTokenExpiration(), time(), $iMaxDays),
			(int)UserRights::GetContactId() > 0,
			AllowedProfiles::UserHasMCPProfile()
		);

		if ($sRefusal === null) {
			PortalAccess::Enter($sPortal);

			return LoginWebPage::EXIT_CODE_OK;
		}

		CallLog::AuditAdmissionRefusal($sRefusal);

		if ($sRefusal === PortalMode::REFUSED_NO_SERVED_PORTAL && $sRequested !== null) {
			// The id is the request's text: quoted only when it is shaped like one.
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: the URL names %s, which '%s' may not enter or '%s' does not serve (%s).",
				$sRequested === '' ? 'a malformed '.PortalMode::PARAMETER : "the portal '{$sRequested}'",
				UserRights::GetUser(),
				Settings::MODULE_SETTING_PORTALS,
				implode(', ', $aServed)
			));

			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		if ($sRefusal === PortalMode::REFUSED_NO_SERVED_PORTAL) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' is allowed into none of the portals in '%s' (%s).",
				UserRights::GetUser(),
				Settings::MODULE_SETTING_PORTALS,
				implode(', ', $aServed)
			));

			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		if ($sRefusal === PortalMode::REFUSED_NOT_A_TOKEN) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' did not log in with a token.",
				UserRights::GetUser()
			));

			throw new MCPAuthException(
				'A user without console access is served with a token only. Send it in the '
				.'Authorization header (Bearer) or the Auth-Token header.',
				MCPResult::UNAUTHORIZED
			);
		}

		if ($sRefusal === PortalMode::REFUSED_TOKEN_UNBOUNDED) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' presented a token that never expires, or expires more than %d days from now ('%s').",
				UserRights::GetUser(),
				$iMaxDays,
				Settings::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS
			));

			throw new MCPAuthException(sprintf(
				'In portal mode a token must expire within %d days. Ask for a personal token with an '
				.'expiry date no later than %s, or set one on this token.',
				$iMaxDays,
				date('Y-m-d', time() + $iMaxDays * 86400)
			), MCPResult::UNAUTHORIZED);
		}

		if ($sRefusal === PortalMode::REFUSED_NO_CONTACT) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' is linked to no contact.",
				UserRights::GetUser()
			));

			throw new MCPAuthException(
				'This account is linked to no contact, which an iTop portal requires. '
				.'Ask an administrator to link it to the person it belongs to.',
				MCPResult::UNAUTHORIZED
			);
		}

		// REFUSED_NO_MCP_PROFILE, answered and logged as it is for a console user.
		return LoginWebPage::EXIT_CODE_NOTAUTHORIZED;
	}

	/**
	 * The expiry of the token this request presented, as iTop stores it, or
	 * null when it has none - including a token class that carries no expiry
	 * at all, which portal mode therefore cannot bound and refuses.
	 */
	private static function presentedTokenExpiration(): ?string
	{
		$oToken = TokenScopes::OfCurrentRequestObject();
		if ($oToken === null || !MetaModel::IsValidAttCode(get_class($oToken), 'expiration_date')) {
			return null;
		}

		$mExpiration = $oToken->Get('expiration_date');

		return is_string($mExpiration) && $mExpiration !== '' ? $mExpiration : null;
	}
}
