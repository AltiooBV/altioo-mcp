<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use ExecutionKPI;

/**
 * A POST announces a JSON body.
 *
 * Runs before ResetSession(), for the reason HostIsServedGate does.
 *
 * @internal
 * @since 2.0.0
 */
final class BodyIsJsonGate implements iGate
{
	/**
	 * Refuses a POST that does not announce a JSON body.
	 *
	 * A cross-origin fetch() is preflighted unless its Content-Type is one of
	 * three the browser considers safe, none of which is application/json.
	 * Requiring JSON therefore means every cross-origin call is preceded by an
	 * OPTIONS this endpoint answers with nothing unless the origin is
	 * allow-listed - so the browser never sends the call at all. Without it the
	 * endpoint is reachable as a simple request, which is the whole CSRF class.
	 *
	 * GET and DELETE carry no body and are left alone.
	 *
	 * @throws MCPRequestRejectedException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return true;
		}

		if (MCPHttp::IsJsonMediaType($_SERVER['CONTENT_TYPE'] ?? null)) {
			return true;
		}

		throw new MCPRequestRejectedException(
			'A POST to the MCP endpoint must carry Content-Type: '.MCPHttp::JSON_MEDIA_TYPE.'.',
			MCPRequestRejectedException::HTTP_UNSUPPORTED_MEDIA_TYPE
		);

		return true;
	}
}
