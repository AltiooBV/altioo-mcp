<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Combodo\iTop\Application\Helper\Session;
use ExecutionKPI;

/**
 * A login by token is one this module was shown the token of.
 *
 * Runs before the policy is decided, because the policy is what this protects:
 * a token login whose token this module did not see would be graded as no token
 * at all.
 *
 * @internal
 * @since 2.0.0
 */
final class TokenLoginAccountedForGate implements iGate
{
	/**
	 * Refuses a token login whose token this module was not shown.
	 *
	 * The backstop behind rejectATokenSentAsAParameter(): that one closes the
	 * hole as it is known today, this one closes it as iTop reports it. See
	 * TokenScopes::LoginIsAccountedFor().
	 *
	 * @throws MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		$mLoginMode = Session::Get('login_mode');
		if (TokenScopes::LoginIsAccountedFor($mLoginMode)) {
			return true;
		}

		ErrorReport::Log(sprintf(
			"Refused an MCP request: iTop logged it in under login mode '%s' with a token this module was not shown, so the token's scopes could not be applied.",
			is_string($mLoginMode) ? $mLoginMode : gettype($mLoginMode)
		));

		throw new MCPAuthException(
			'Send the token in the Authorization header (Bearer) or the Auth-Token header.',
			MCPResult::UNAUTHORIZED
		);

		return true;
	}
}
