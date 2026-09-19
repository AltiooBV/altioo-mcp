<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Server\Session\StatelessSessionStore;
use Mcp\Server;
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

	public function testTheHandshakeEraStillOpensASession(): void
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
		$this->assertNotSame('', $aResponse['session'], 'a handshake-era client is given a session id');
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
		$sSource = file_get_contents(self::ROOT.'/src/Service/MCPService.php');
		$this->assertNotFalse($sSource, 'MCPService.php cannot be read');

		$this->assertSame(
			0,
			preg_match('/^\s*(?:use .*|\$aMiddleware\[\] = new )ProtocolVersionMiddleware/m', $sSource),
			'MCPService installs ProtocolVersionMiddleware again - see this test class for what that costs'
		);
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
	 * a fixed Access-Control-Allow-Headers list - see MCPController::corsHeaders().
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
	 * Runs one request through a server wired the way MCPService wires it.
	 *
	 * @param array<string, mixed>                        $aBody
	 * @param array<string, string>                       $aHeaders
	 * @param array<int, \Psr\Http\Server\MiddlewareInterface> $aMiddleware
	 *
	 * @return array{status: int, session: string, body: array<string, mixed>}
	 */
	private static function answer(array $aBody, array $aHeaders, array $aMiddleware = []): array
	{
		$oFactory = new Psr17Factory();

		$oRequest = $oFactory->createServerRequest('POST', 'https://itop.example.com/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode($aBody)));

		foreach ($aHeaders as $sName => $sValue) {
			$oRequest = $oRequest->withHeader($sName, $sValue);
		}

		$oServer = Server::builder()
			->setServerInfo('era-test', '1.0.0')
			->addTool(static fn(string $q): string => strtoupper($q), 'shout', 'Shouts back')
			->setSession(new StatelessSessionStore())
			->build();

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: $aMiddleware));

		return [
			'status'  => $oResponse->getStatusCode(),
			'session' => $oResponse->getHeaderLine('Mcp-Session-Id'),
			'body'    => (array)json_decode((string)$oResponse->getBody(), true),
		];
	}
}
