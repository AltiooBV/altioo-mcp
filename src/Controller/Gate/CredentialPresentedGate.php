<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use ExecutionKPI;

/**
 * The request brought a credential of its own.
 *
 * It first promotes an Authorization bearer to the header authent-token reads,
 * and the check reads the headers that promotion writes, so it cannot run
 * before it: a bearer that has not been promoted yet is still only an
 * Authorization header, and the two must agree on what counts.
 *
 * @internal
 * @since 2.0.0
 */
final class CredentialPresentedGate implements iGate
{
	/**
	 * Refuses a request that brought no credential of its own.
	 *
	 * This is the guard that lets ResetSession() be conditional, and both
	 * halves of it matter.
	 *
	 * The reset destroys whatever iTop session the browser is holding. It is
	 * unauthenticated, so without this an <img src> on any website is a logout
	 * for every console user who loads that page - a side effect no MCP client
	 * ever asked for, on a request that was never going to be served.
	 *
	 * Skipping the reset alone would be worse than leaving it: a credential-less
	 * request would then reach DoLogin() with the browser's iTop cookie intact
	 * and be authenticated by it, which is the whole endpoint reachable on
	 * ambient authority. So the answer is not to reset later but to refuse
	 * earlier - the outcome for such a request is the 401 it already got, minus
	 * the collateral damage.
	 *
	 * What counts as a credential is anything the caller or the webserver put
	 * there deliberately - see MCPHttp::CarriesACredential(). A cookie is not
	 * one: the browser attaches it without being asked, which is precisely what
	 * makes it useless as evidence that this request was meant.
	 *
	 * @throws MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		MCPHttp::PromoteBearerToAuthToken();

		if (MCPHttp::CarriesACredential()) {
			return true;
		}

		throw new MCPAuthException(
			'No credential was sent. The MCP endpoint needs one on every request: a bearer token in the '
			.'Authorization header, an Auth-Token header, or a login. A browser session does not count.',
			MCPResult::UNAUTHORIZED
		);

		return true;
	}
}
