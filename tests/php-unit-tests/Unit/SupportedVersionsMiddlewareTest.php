<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Server\SupportedVersionsMiddleware;
use Http\Discovery\Psr17Factory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review of 2026-11-23, B5: the -32022 refusal listed the handshake revisions as the ones supported, and
 * 2026-07-28, which the same URL serves, was not among them.
 */
class SupportedVersionsMiddlewareTest extends TestCase
{
	private function through(ResponseInterface $oAnswer): ResponseInterface
	{
		$oFactory = new Psr17Factory();
		$oHandler = new class($oAnswer) implements RequestHandlerInterface {
			public function __construct(private readonly ResponseInterface $oAnswer)
			{
			}

			public function handle(ServerRequestInterface $oRequest): ResponseInterface
			{
				return $this->oAnswer;
			}
		};

		return (new SupportedVersionsMiddleware($oFactory))->process($oFactory->createServerRequest('POST', '/'), $oHandler);
	}

	public function testTheSdksOwnRefusalNowListsTheModernRevisionToo(): void
	{
		$oFactory = new Psr17Factory();
		$oRefusal = (new ProtocolVersionMiddleware(null, $oFactory, $oFactory))->process(
			$oFactory->createServerRequest('POST', '/')->withHeader('MCP-Protocol-Version', '1999-01-01'),
			new class implements RequestHandlerInterface {
				public function handle(ServerRequestInterface $oRequest): ResponseInterface
				{
					throw new \LogicException('reached');
				}
			}
		);
		$this->assertNotContains('2026-07-28', json_decode((string)$oRefusal->getBody(), true)['error']['data']['supported'], 'the premise: the SDK leaves it out');
		$oRefusal->getBody()->rewind();

		$oAnswered = $this->through($oRefusal);
		$aBody = json_decode((string)$oAnswered->getBody(), true);

		$this->assertSame(400, $oAnswered->getStatusCode());
		$this->assertSame(Error::UNSUPPORTED_PROTOCOL_VERSION, $aBody['error']['code']);
		$this->assertContains('2026-07-28', $aBody['error']['data']['supported']);
		$this->assertSame('1999-01-01', $aBody['error']['data']['requested']);
		foreach (ProtocolVersion::handshakeVersions() as $oVersion) {
			$this->assertContains($oVersion->value, $aBody['error']['data']['supported'], 'a revision the SDK listed went missing');
		}
		$this->assertSame((string)strlen((string)$oAnswered->getBody()), $oAnswered->getHeaderLine('Content-Length'));
	}

	public function testEveryOtherAnswerPassesThroughUntouched(): void
	{
		$oFactory = new Psr17Factory();
		foreach ([
			[200, '{"jsonrpc":"2.0","id":1,"result":{}}'],
			[400, '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}'],
			[400, 'not json at all'],
			[401, '{"error":{"code":-32022,"data":{"supported":["x"]}}}'],
		] as [$iStatus, $sBody]) {
			$oAnswered = $this->through($oFactory->createResponse($iStatus)->withBody($oFactory->createStream($sBody)));

			$this->assertSame($iStatus, $oAnswered->getStatusCode());
			$this->assertSame($sBody, (string)$oAnswered->getBody());
		}
	}
}
