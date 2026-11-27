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

		// CredentialPresentedGate ran first, so a request that gets here brought a credential in its headers (or the
		// web server named the user): what it has to do is drop the parameter, not send what it already sent
		// (review 2026-11-23, retest). Neither value is echoed.
		throw new MCPAuthException(
			'Remove the auth_token request parameter from the URL and send the request again with the credential in its headers alone. '
			.'A request carrying that parameter is refused even then: a URL is written to access logs.',
			MCPResult::UNAUTHORIZED
		);

		return true;
	}
}
