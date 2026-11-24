<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Server\NoSessionTeardownMiddleware;
use Http\Discovery\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require_once __DIR__.'/../bootstrap.php';

/** An HTTP DELETE ends no session here, so it is answered 405 instead of a 200 that ended nothing. */
class NoSessionTeardownMiddlewareTest extends TestCase
{
	private function process(string $sMethod): ResponseInterface
	{
		$oFactory = new Psr17Factory();
		$oHandler = new class($oFactory) implements RequestHandlerInterface {
			public function __construct(private readonly Psr17Factory $oFactory)
			{
			}

			public function handle(ServerRequestInterface $oRequest): ResponseInterface
			{
				return $this->oFactory->createResponse(200)->withHeader('X-Reached', 'transport');
			}
		};

		return (new NoSessionTeardownMiddleware($oFactory))->process(
			$oFactory->createServerRequest($sMethod, '/')->withHeader('Mcp-Session-Id', '4d7e6c1a-2b3f-4c5d-8e9f-0a1b2c3d4e5f'),
			$oHandler
		);
	}

	public function testDeleteIsAnsweredMethodNotAllowedWithTheAllowedMethods(): void
	{
		$oResponse = $this->process('DELETE');

		$this->assertSame(405, $oResponse->getStatusCode());
		$this->assertSame('POST, OPTIONS', $oResponse->getHeaderLine('Allow'));
		$this->assertFalse($oResponse->hasHeader('X-Reached'), 'the transport was reached and answered it');
		$this->assertSame('application/json', $oResponse->getHeaderLine('Content-Type'), 'a JSON-RPC error body, as GET and PUT are answered (review 2026-11-23, C11)');
		$aBody = json_decode((string)$oResponse->getBody(), true);
		$this->assertSame(-32600, $aBody['error']['code'] ?? null);
	}

	/** @dataProvider otherMethods */
	public function testEveryOtherMethodIsLeftToTheTransport(string $sMethod): void
	{
		$this->assertSame('transport', $this->process($sMethod)->getHeaderLine('X-Reached'));
	}

	/** @return array<string, array{0: string}> */
	public function otherMethods(): array
	{
		return ['POST' => ['POST'], 'GET' => ['GET'], 'OPTIONS' => ['OPTIONS']];
	}
}
