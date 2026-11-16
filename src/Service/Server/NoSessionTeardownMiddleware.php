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
 * The SDK's transport answers a DELETE carrying any well-formed `Mcp-Session-Id` with 200, which tells a
 * client it ended something. It ended nothing: the id is real but carries nothing, and the session store
 * accepts any UUID (StatelessSessionStore). The streamable HTTP transport lets a server refuse that
 * teardown - the 2025-06-18 revision's session management says a server "MAY respond to this request
 * with HTTP 405 Method Not Allowed, indicating that the server does not allow clients to terminate
 * sessions" - and the SDK's own StatelessHttpTransport does the same for the 2026-07-28 leg.
 *
 * The id is still issued to a client on an earlier revision, because that revision requires it.
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
	public function process(ServerRequestInterface $oRequest, RequestHandlerInterface $oHandler): ResponseInterface
	{
		if (strtoupper($oRequest->getMethod()) !== 'DELETE') {
			return $oHandler->handle($oRequest);
		}

		$oResponse = $this->oResponses->createResponse(405)
			->withHeader('Allow', self::ALLOW)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$oResponse->getBody()->write('This endpoint keeps no session, so there is nothing to end. Send POST.');

		return $oResponse;
	}
}
