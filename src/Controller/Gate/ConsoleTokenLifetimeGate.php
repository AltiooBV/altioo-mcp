<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Combodo\iTop\Application\Helper\Session;
use ExecutionKPI;
use UserRights;

/**
 * A token used in console mode lives no longer than the operator allows.
 *
 * The console counterpart of portal mode's bound (PortalAdmission), off by
 * default (doc/adr/0020). Runs while the token is still at hand, since its
 * expiry is read from the token.
 *
 * @internal
 * @since 2.0.0
 */
final class ConsoleTokenLifetimeGate implements iGate
{
	/**
	 * With mcp_token_max_days above 0, refuses a request logged in with a
	 * token that never expires, or expires later than that many days from now.
	 * An application token (UserToken) has no expiry date in iTop, so it
	 * cannot be bounded and is refused, as portal mode refuses it. A request
	 * in portal mode is held to mcp_portal_token_max_days instead; one that
	 * did not log in with a token is not this gate's business. A malformed
	 * value refuses every token login, never reads as "no bound".
	 *
	 * @throws MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		if (PortalMode::Current() !== null || !TokenScopes::LoginWasAToken(Session::Get('login_mode'))) {
			return true;
		}

		$iMaxDays = Settings::GetTokenMaxDays();
		if ($iMaxDays === 0) {
			return true;
		}

		if ($iMaxDays === null) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request: '%s' presented a token and '%s' is not a non-negative integer.",
				UserRights::GetUser(),
				Settings::MODULE_SETTING_TOKEN_MAX_DAYS
			));

			throw new MCPAuthException(
				'Tokens are refused until an administrator corrects this endpoint\'s token lifetime setting.',
				MCPResult::UNAUTHORIZED
			);
		}

		if (PortalMode::TokenIsBounded(PresentedToken::Expiration(), time(), $iMaxDays)) {
			return true;
		}

		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' presented a token that never expires, or expires more than %d days from now ('%s').",
			UserRights::GetUser(),
			$iMaxDays,
			Settings::MODULE_SETTING_TOKEN_MAX_DAYS
		));

		$sLatest = date('Y-m-d', time() + $iMaxDays * 86400);

		// An application token has no expiry date to set; only a personal one does.
		throw new MCPAuthException(
			PresentedToken::CanExpire()
				? sprintf(
					'A token must expire within %d days. Ask for a personal token with an '
					.'expiry date no later than %s, or set one on this token.',
					$iMaxDays,
					$sLatest
				)
				: sprintf(
					'An application token has no expiry date, so it cannot be used while tokens must expire '
					.'within %d days. Ask for a personal token with an expiry date no later than %s.',
					$iMaxDays,
					$sLatest
				),
			MCPResult::UNAUTHORIZED
		);
	}
}
