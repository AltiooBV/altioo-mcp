<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers an HTTP DELETE with 405: this endpoint keeps no session, so there is nothing to end.
 *
 * Before SDK 0.10 the transport answered a DELETE carrying any well-formed `Mcp-Session-Id` with 200, which
 * told a client it ended something. It ended nothing: the id was real but carried nothing. The 2025-06-18
 * revision's session management says a server "MAY respond to this request with HTTP 405 Method Not
 * Allowed, indicating that the server does not allow clients to terminate sessions".
 *
 * Since 0.10 the module keeps no session store at all, and the SDK's own sessionless transport answers a
 * DELETE the same way (405, `Allow: POST, OPTIONS`, a JSON-RPC error body). This answer is kept for its
 * words, which say why, and so that the documented behaviour does not depend on a default of the SDK's.
 * No id is issued to a client of any revision.
 *
 * @internal
 * @since 2.1.0
 */
final class NoSessionTeardownMiddleware implements MiddlewareInterface
{
	/** What is allowed instead; there is no GET stream either. */
	public const ALLOW = 'POST, OPTIONS';

	public function __construct(private readonly ResponseFactoryInterface $oResponses)
	{
	}

	#[\Override]
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if (strtoupper($request->getMethod()) !== 'DELETE') {
			return $handler->handle($request);
		}

		$oResponse = $this->oResponses->createResponse(405)
			->withHeader('Allow', self::ALLOW)
			->withHeader('Content-Type', 'application/json');
		// A JSON-RPC error body, as GET and PUT are answered, and not plain text (review 2026-11-23, C11).
		$oResponse->getBody()->write((string)json_encode([
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => ['code' => -32600, 'message' => 'This endpoint keeps no session, so there is nothing to end. Send POST.'],
		]));

		return $oResponse;
	}
}
