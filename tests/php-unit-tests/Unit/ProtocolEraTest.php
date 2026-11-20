<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Server\NoSessionTeardownMiddleware;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * One endpoint, two protocol eras, and the middleware that must not be in
 * front of them.
 *
 * Since SDK 0.8 the transport reads the body, decides which revision the
 * request speaks and routes it: a 2025-x client to the handshake leg, which
 * has a session; a 2026-07-28 client to the stateless leg, which has none.
 * Both have to keep working, and the second is the reason the upgrade was
 * worth making, so both are exercised here rather than assumed.
 *
 * The third test is the one that earns its place. ProtocolVersionMiddleware
 * used to be in MCPService's middleware list and is deliberately not any more:
 * the list runs before the era is classified, and that middleware knows only
 * the handshake revisions, so from out there it answers -32022 to every
 * modern-era call. The SDK logs a warning about it, and a warning in a log is
 * not a thing a future edit will notice - this test is.
 *
 * No iTop here: this is the SDK's own wiring, and MCPService cannot be built
 * without a configured instance. What the service must not do is checked
 * against its source instead.
 */
class ProtocolEraTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	private const MODERN_VERSION = '2026-07-28';

	private const HANDSHAKE_VERSION = '2025-11-25';

	/**
	 * SDK 0.10 serves a handshake-era client over HTTP without sessions unless the builder is told to keep them,
	 * which MCPService is not (it used to hand the SDK a store that remembered nothing; the SDK now binds a session to
	 * the principal that opened it, and answered 503 to the id such a store could not give back).
	 */
	public function testTheHandshakeEraIsServedWithoutASession(): void
	{
		$aResponse = self::answer(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [
					'protocolVersion' => self::HANDSHAKE_VERSION,
					'capabilities'    => [],
					'clientInfo'      => ['name' => 'phpunit', 'version' => '1'],
				],
			],
			[]
		);

		$this->assertSame(200, $aResponse['status'], 'the handshake era is still served');
		$this->assertSame('', $aResponse['session'], 'a handshake-era client is given no session id: none is kept');
		$this->assertSame(
			self::HANDSHAKE_VERSION,
			$aResponse['body']['result']['protocolVersion'] ?? null,
			'the revision the client asked for is the one it is answered on'
		);
	}

	public function testTheModernEraIsAnsweredWithoutASession(): void
	{
		$aResponse = self::answer(self::modernCall(), self::modernHeaders());

		$this->assertSame(200, $aResponse['status'], 'the 2026-07-28 revision is served');
		$this->assertSame(
			'',
			$aResponse['session'],
			'a stateless revision is given no session id - nothing was stored, so there is nothing to come back to'
		);
		$this->assertSame(
			'HELLO',
			$aResponse['body']['result']['content'][0]['text'] ?? null,
			'the tool ran'
		);
	}

	/**
	 * The regression this upgrade is one edit away from at all times.
	 */
	public function testTheVersionMiddlewareInFrontWouldRefuseTheModernEra(): void
	{
		$aResponse = self::answer(self::modernCall(), self::modernHeaders(), [new ProtocolVersionMiddleware()]);

		$this->assertSame(400, $aResponse['status'], 'the call never reaches the era it was written for');
		$this->assertSame(
			-32022,
			$aResponse['body']['error']['code'] ?? null,
			'and is refused as an unsupported version, by a middleware that only knows the handshake ones'
		);
	}

	public function testTheServiceDoesNotInstallThatMiddleware(): void
	{
		$sSource = file_get_contents(self::ROOT.'/src/Service/Server/MCPService.php');
		$this->assertNotFalse($sSource, 'MCPService.php cannot be read');

		$this->assertSame(
			0,
			preg_match('/^\s*(?:use .*|\$aMiddleware\[\] = new )ProtocolVersionMiddleware/m', $sSource),
			'MCPService installs ProtocolVersionMiddleware again - see this test class for what that costs'
		);
	}

	/**
	 * What the 0.9 store was for: the second call of a handshake-era client. It carries the revision in a header, and
	 * the session id the client was never given is not needed.
	 */
	public function testAHandshakeEraCallAfterInitializeIsServedWithoutAnId(): void
	{
		$aResponse = self::answer(self::handshakeCall(), ['MCP-Protocol-Version' => self::HANDSHAKE_VERSION]);

		$this->assertSame(200, $aResponse['status']);
		$this->assertSame('HELLO', $aResponse['body']['result']['content'][0]['text'] ?? null, 'the tool ran');
	}

	/**
	 * A client that invents an id, or still holds one a 2.0 server issued, is served as one that sent none. The SDK
	 * answers 404 to a session "opened before the upgrade" only when it keeps sessions.
	 */
	public function testAnIdTheEndpointNeverIssuedIsNotLookedUp(): void
	{
		$aResponse = self::answer(self::handshakeCall(), [
			'MCP-Protocol-Version' => self::HANDSHAKE_VERSION,
			'Mcp-Session-Id'       => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
		]);

		$this->assertSame(200, $aResponse['status']);
		$this->assertSame('HELLO', $aResponse['body']['result']['content'][0]['text'] ?? null);
	}

	/**
	 * 2.1.0 says a DELETE is answered 405 with `Allow: POST, OPTIONS`, and the SDK's own sessionless transport says the
	 * same without the module's middleware: this holds on either, and the two are asserted apart so that removing the
	 * module's middleware is a decision and not an accident.
	 */
	public function testADeleteIsRefusedWithTheAllowHeaderTheChangelogStates(): void
	{
		$oFactory = new Psr17Factory();
		$aSessionId = ['Mcp-Session-Id' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301'];

		foreach ([[], [new NoSessionTeardownMiddleware($oFactory)]] as $aMiddleware) {
			$oResponse = self::send('DELETE', '', $aSessionId + ['MCP-Protocol-Version' => self::HANDSHAKE_VERSION], $aMiddleware);

			$this->assertSame(405, $oResponse->getStatusCode(), 'a DELETE is refused, whoever answers');
			$this->assertSame('POST, OPTIONS', $oResponse->getHeaderLine('Allow'));
		}
	}

	public function testAGetIsRefusedToo(): void
	{
		$oResponse = self::send('GET', '', ['MCP-Protocol-Version' => self::HANDSHAKE_VERSION, 'Accept' => 'text/event-stream']);

		$this->assertSame(405, $oResponse->getStatusCode());
		$this->assertSame('POST, OPTIONS', $oResponse->getHeaderLine('Allow'));
	}

	/**
	 * The module refuses a batch before the SDK sees it (Controller\BatchRefusal, audited as one row), so the SDK's
	 * own answer is a backstop. 0.9 served the array; 0.10 answers 400 and -32600 on every revision, so the refusal
	 * stands even if the controller's check is ever lost.
	 */
	public function testTheSdkItselfNoLongerServesABatch(): void
	{
		$oResponse = self::send('POST', (string)json_encode([self::handshakeCall()]), ['MCP-Protocol-Version' => self::HANDSHAKE_VERSION]);

		$this->assertSame(400, $oResponse->getStatusCode());
		$aBody = (array)json_decode((string)$oResponse->getBody(), true);
		$this->assertSame(-32600, $aBody['error']['code'] ?? null);
	}

	/**
	 * A handshake-era tools/call, the second call of a client that has initialized.
	 *
	 * @return array<string, mixed>
	 */
	private static function handshakeCall(): array
	{
		return [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => ['name' => 'shout', 'arguments' => ['q' => 'hello']],
		];
	}

	/**
	 * A 2026-07-28 tools/call, complete enough to be served.
	 *
	 * @return array<string, mixed>
	 */
	private static function modernCall(): array
	{
		return [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'shout',
				'arguments' => ['q' => 'hello'],
				// The revision has no handshake, so what initialize used to
				// carry travels with every request instead.
				'_meta'     => [
					'io.modelcontextprotocol/protocolVersion'    => self::MODERN_VERSION,
					'io.modelcontextprotocol/clientInfo'         => ['name' => 'phpunit', 'version' => '1'],
					'io.modelcontextprotocol/clientCapabilities' => [],
				],
			],
		];
	}

	/**
	 * The headers SEP-2243 obliges a modern-era caller to mirror the body with.
	 *
	 * Mcp-Param-* is absent on purpose: the SDK validates those when they are
	 * sent and does not require them, which is what lets the endpoint advertise
	 * a fixed Access-Control-Allow-Headers list - see MCPResponse::corsHeaders().
	 *
	 * @return array<string, string>
	 */
	private static function modernHeaders(): array
	{
		return [
			'MCP-Protocol-Version' => self::MODERN_VERSION,
			'Mcp-Method'           => 'tools/call',
			'Mcp-Name'             => 'shout',
		];
	}

	/**
	 * Runs one request through a server wired the way MCPService wires it: no session store.
	 *
	 * @param array<string, mixed>                        $aBody
	 * @param array<string, string>                       $aHeaders
	 * @param array<int, \Psr\Http\Server\MiddlewareInterface> $aMiddleware
	 *
	 * @return array{status: int, session: string, body: array<string, mixed>}
	 */
	private static function answer(array $aBody, array $aHeaders, array $aMiddleware = []): array
	{
		$oResponse = self::send('POST', (string)json_encode($aBody), $aHeaders, $aMiddleware);

		return [
			'status'  => $oResponse->getStatusCode(),
			'session' => $oResponse->getHeaderLine('Mcp-Session-Id'),
			'body'    => (array)json_decode((string)$oResponse->getBody(), true),
		];
	}

	/**
	 * @param array<string, string>                            $aHeaders
	 * @param array<int, \Psr\Http\Server\MiddlewareInterface> $aMiddleware
	 */
	private static function send(string $sMethod, string $sBody, array $aHeaders, array $aMiddleware = []): \Psr\Http\Message\ResponseInterface
	{
		$oFactory = new Psr17Factory();

		$oRequest = $oFactory->createServerRequest($sMethod, 'https://itop.example.com/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream($sBody));

		foreach ($aHeaders as $sName => $sValue) {
			$oRequest = $oRequest->withHeader($sName, $sValue);
		}

		$oServer = Server::builder()
			->setServerInfo('era-test', '1.0.0')
			->addTool(static fn(string $q): string => strtoupper($q), 'shout', 'Shouts back')
			->build();

		return $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: $aMiddleware));
	}
}
