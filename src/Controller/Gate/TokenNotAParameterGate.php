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
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use ExecutionKPI;

/**
 * No token was sent as a request parameter.
 *
 * @internal
 * @since 2.0.0
 */
final class TokenNotAParameterGate implements iGate
{
	/**
	 * Refuses a token sent as a request parameter, before it can log in.
	 *
	 * See MCPHttp::CarriesATokenAsAParameter() for why. Refused before the
	 * session reset for the same reason as the checks above it: nothing that
	 * is going to be refused anyway should cost a console user their session.
	 *
	 * @throws MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		if (!MCPHttp::CarriesATokenAsAParameter()) {
			return true;
		}

		ErrorReport::Log('Refused an MCP request carrying auth_token as a request parameter. The endpoint reads a token from the Authorization or Auth-Token header only.');

		throw new MCPAuthException(
			'Send the token in the Authorization header (Bearer) or the Auth-Token header. '
			.'A token passed as the auth_token request parameter is refused: a URL is written to access logs.',
			MCPResult::UNAUTHORIZED
		);

		return true;
	}
}
