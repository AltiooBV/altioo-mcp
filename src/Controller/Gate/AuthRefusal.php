<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use LoginWebPage;
use UserRights;

/**
 * The refusal a login that did not succeed is answered with, by the code iTop
 * gave: what the caller is told to change, while the log names who.
 *
 * @internal
 * @since 2.0.0
 */
final class AuthRefusal
{
	public static function CreateAuthException(int $iRet): MCPAuthException
	{
		switch ($iRet) {
			case LoginWebPage::EXIT_CODE_WRONGCREDENTIALS:
				return self::createWrongCredentialsException();
			case LoginWebPage::EXIT_CODE_NOTAUTHORIZED:
				return self::createProfileException();
			case LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED:
				return self::createNoConsoleAccessException();
			default:
				return new MCPAuthException('Unknown authentication error (retCode='.$iRet.')', MCPResult::UNAUTHORIZED);
		}
	}

	/**
	 * Refuses a caller who authenticated but holds none of the profiles this
	 * instance lets through.
	 *
	 * The message says "by default" because there is no fixed answer: the gate
	 * is mcp_allowed_profiles, an operator may set it to anything, and naming
	 * one profile as though it were the rule sends an administrator looking for
	 * a grant that is not what their instance checks. It names Administrator
	 * too, which is half of the shipped default.
	 *
	 * What this instance actually requires goes to the log rather than into the
	 * response, for the same reason the refused hostname does in
	 * rejectUnlessHostIsServed(): it is operator configuration, the person who
	 * needs it can read log/error.log, and a caller collecting 401s cannot.
	 */
	private static function createProfileException(): MCPAuthException
	{
		$aProfiles = AllowedProfiles::GetAuthorizedProfiles();

		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' holds none of the profiles in '%s' (%s).",
			UserRights::GetUser(),
			'mcp_allowed_profiles',
			empty($aProfiles) ? 'the list is empty, so nobody can be let through' : implode(', ', $aProfiles)
		));

		return new MCPAuthException(
			'This user is not authorized to use the MCP services. '
			.'(By default, the profile "MCP Services User" or "Administrator" is required; '
			.'the profiles this instance accepts are set by the mcp_allowed_profiles module parameter.)',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a caller whose credentials are valid but whose profiles give them
	 * no console.
	 *
	 * DoLogin(false, false, ...) asks DoLoginEx() for the 'backoffice' portal,
	 * so a user who only reaches the end-user portal authenticates and is then
	 * turned away by ChangeLocation() with EXIT_CODE_PORTALUSERNOTAUTHORIZED.
	 * That is the intended boundary - this endpoint serves what the console
	 * serves - but it is reached by an ordinary mistake: granting "MCP Services
	 * User" to a portal user and issuing them a token. Nothing else about that
	 * setup looks wrong, and without this case the operator got the default
	 * branch's "Unknown authentication error (retCode=5)" and no log line.
	 *
	 * Same split as everywhere else on this path: the caller is told what to
	 * change, the log names who it was.
	 */
	private static function createNoConsoleAccessException(): MCPAuthException
	{
		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' authenticated but holds no profile granting access to the "
			.'console, so there is no interface for this endpoint to serve. A portal-only user cannot '
			.'use the MCP services whatever their token scopes or MCP profile are, unless portal mode '
			."serves one of their portals ('%s').",
			UserRights::GetUser(),
			Settings::MODULE_SETTING_PORTALS
		));

		return new MCPAuthException(
			'This user has no access to the iTop console. The MCP services serve what the console '
			.'serves, so a portal-only user cannot reach them - the account needs a profile granting '
			.'console access in addition to whatever lets it through the MCP gate, or an administrator '
			.'has to serve its portal in portal mode (the mcp_portals module parameter).',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a credential that did not authenticate, saying which kind.
	 *
	 * A token is the case a client can recover from on its own, so it is the
	 * one the challenge names as invalid_token: an OAuth client refreshes or
	 * sends the person through authorize again, where "Invalid login" alone
	 * read like a wrong password. iTop answers this same exit code for a token
	 * that is unknown, expired or revoked, and for one whose scopes include no
	 * MCP scope (see TokenScopes), and it does not say which, so the message
	 * names all of them.
	 */
	private static function createWrongCredentialsException(): MCPAuthException
	{
		if (MCPHttp::CurrentAuthToken() === null) {
			return new MCPAuthException('The login or password was not accepted.', MCPResult::UNAUTHORIZED);
		}

		return new MCPAuthException(
			'The token was not accepted: it is unknown, has expired or been revoked, or carries no MCP scope. '
			.'Refresh it, or connect again.',
			MCPResult::UNAUTHORIZED,
			'invalid_token'
		);
	}
}
