<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Completes the list of revisions an "unsupported protocol version" refusal offers (review 2026-11-23, B5).
 *
 * One URL answers two eras. A request whose revision header the SDK cannot place is refused on the handshake leg,
 * with -32022 and the handshake revisions as the ones supported, and 2026-07-28, which the other leg serves, is
 * not among them: a client choosing from the list was told it could not speak the revision it was already
 * speaking. The refusal and its status are the SDK's and are left alone; only the revisions the stateless leg
 * serves are added to the list the client is told to pick from.
 *
 * Only a 400 carrying -32022 and a list of strings is touched; any other answer passes through byte for byte.
 *
 * @internal
 * @since 2.2.0
 */
final class SupportedVersionsMiddleware implements MiddlewareInterface
{
	public function __construct(private readonly StreamFactoryInterface $oStreams)
	{
	}

	#[\Override]
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$oResponse = $handler->handle($request);
		if ($oResponse->getStatusCode() !== 400) {
			return $oResponse;
		}

		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}
		$sBody = (string)$oBody;
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}

		$aDecoded = json_decode($sBody, true);
		$aSupported = is_array($aDecoded) ? ($aDecoded['error']['data']['supported'] ?? null) : null;
		if (!is_array($aDecoded) || ($aDecoded['error']['code'] ?? null) !== Error::UNSUPPORTED_PROTOCOL_VERSION || !is_array($aSupported)) {
			return $oResponse->withBody($this->oStreams->createStream($sBody));
		}

		foreach (ProtocolVersion::cases() as $oVersion) {
			if ($oVersion->isModern() && !in_array($oVersion->value, $aSupported, true)) {
				$aSupported[] = $oVersion->value;
			}
		}
		$aDecoded['error']['data']['supported'] = array_values($aSupported);
		$sRewritten = (string)json_encode($aDecoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return $oResponse
			->withBody($this->oStreams->createStream($sRewritten))
			->withHeader('Content-Length', (string)strlen($sRewritten));
	}
}
