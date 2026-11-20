<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The server is built, and it answers.
 *
 * Every other test here exercises one class. This one is the only thing that
 * assembles the whole server - the registry, the access policy, every tool's
 * annotations and input schema - and asks it the questions a real client asks
 * first. Nothing else builds it, so a bool passed where an array is declared,
 * or a one-argument call to a two-argument method, is a TypeError on every
 * request that only this test can fail on - the controller's catch-all
 * swallows it into a generic 500.
 *
 * The request is built here rather than from $_SERVER, which is the one thing
 * MCPService::run() does differently - there is no php://input under CLI. The
 * PSR-17 implementation it goes through is nyholm/psr7, required by
 * composer.json; Psr17AvailabilityTest is what holds that dependency in place,
 * and needs no iTop to do it.
 */
class ServerBootTest extends ItopDataTestCaseAlias
{
	private const SESSION_HEADER = 'Mcp-Session-Id';

	/** The protocol version a current client offers; the server answers with its own. */
	private const CLIENT_PROTOCOL_VERSION = '2025-06-18';

	private function server(): Server
	{
		MCPExtensionCollector::CollectAll();

		// Unrestricted: this is about the server being buildable at all, not
		// about which elements a given caller is served - MCPServiceGatingTest
		// covers that, and covers it without needing iTop.
		return (new ReflectionMethod(MCPService::class, 'createServer'))
			->invoke(null, AccessPolicy::Unrestricted());
	}

	/**
	 * One request/response exchange against a freshly built server.
	 *
	 * A new server per call is not a shortcut, it is the deployment: this
	 * endpoint is stateless and every request builds its own. Since SDK 0.10 a
	 * handshake-era client is served without sessions unless the builder is told
	 * to keep them, which MCPService does not: initialize issues no session id and
	 * a later request carries only the protocol revision, in MCP-Protocol-Version.
	 *
	 * @param array<string, mixed> $aPayload
	 */
	private function ask(array $aPayload, ?string $sSessionId = null, bool $bAfterInitialize = true): ResponseInterface
	{
		$oFactory = new Psr17Factory();

		$oRequest = $oFactory
			->createServerRequest('POST', 'https://itop.example.com/extensions/altioo-mcp/index.php')
			->withHeader('Host', 'itop.example.com')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode($aPayload)));

		if ($sSessionId !== null && $sSessionId !== '') {
			$oRequest = $oRequest->withHeader(self::SESSION_HEADER, $sSessionId);
		}

		if ($bAfterInitialize) {
			$oRequest = $oRequest->withHeader('MCP-Protocol-Version', self::CLIENT_PROTOCOL_VERSION);
		}

		// No middleware: the Host allow-list is instance configuration and is
		// asserted where it is decided (MCPHttpTest). What is under test here
		// is the server behind it.
		return $this->server()->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));
	}

	/** @return array<string, mixed> */
	private function decode(ResponseInterface $oResponse): array
	{
		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}

		$aDecoded = json_decode((string)$oBody, true);
		$this->assertIsArray($aDecoded, 'the server answered something that is not JSON');

		return $aDecoded;
	}

	/**
	 * The handshake. It issues no session id: a client that is given none sends none.
	 *
	 * Returns the id the server issued, which is '' and which the tests then pass
	 * on as a client without one would.
	 */
	private function initialize(): string
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => self::CLIENT_PROTOCOL_VERSION,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
			],
		], null, false);

		$this->assertSame(200, $oResponse->getStatusCode(), 'initialize was not answered');
		$this->assertArrayNotHasKey('error', $this->decode($oResponse));

		return $oResponse->getHeaderLine(self::SESSION_HEADER);
	}

	/**
	 * Building it is most of the test. Both defects that reached production
	 * threw here, before a single request was routed.
	 */
	public function testTheServerCanBeBuilt(): void
	{
		$this->assertInstanceOf(Server::class, $this->server());
	}

	/**
	 * The endpoint keeps no session (README, "Transport and protocol support"), so the handshake does not issue one:
	 * an id would have to be looked up on the next request, and nothing is there to find. SDK 0.9 issued one the
	 * store accepted blindly; 0.10 binds a session to the principal that opened it and answers 503 for one it
	 * cannot read, so the module no longer asks for sessions at all.
	 */
	public function testInitializeIssuesNoSessionId(): void
	{
		$this->assertSame('', $this->initialize());
	}

	/**
	 * The first call every client makes after the handshake, and the one that
	 * touches every registered element: each tool's name, description,
	 * annotations and input schema are read to build the answer.
	 */
	public function testToolsListIsAnsweredWithTools(): void
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
			'params'  => new \stdClass(),
		], $this->initialize());

		$this->assertSame(200, $oResponse->getStatusCode());

		$aDecoded = $this->decode($oResponse);
		$this->assertArrayNotHasKey('error', $aDecoded, 'tools/list returned a JSON-RPC error');
		$this->assertArrayHasKey('result', $aDecoded);
		$this->assertArrayHasKey('tools', $aDecoded['result']);
		$this->assertNotEmpty($aDecoded['result']['tools'], 'the server advertised no tools at all');

		foreach ($aDecoded['result']['tools'] as $aTool) {
			$this->assertArrayHasKey('name', $aTool);
			$this->assertArrayHasKey('inputSchema', $aTool);
		}
	}

	/**
	 * The listing has to arrive in one page. The SDK pages behind a cursor at
	 * 50 by default, and a client that ignores nextCursor - several do - never
	 * asks for the rest, so a tool past the boundary is advertised to nobody.
	 */
	public function testTheToolListingIsNotPaginatedAway(): void
	{
		$aDecoded = $this->decode($this->ask([
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/list',
			'params'  => new \stdClass(),
		], $this->initialize()));

		$this->assertArrayNotHasKey(
			'nextCursor',
			$aDecoded['result'] ?? [],
			'tools/list came back paginated: raise mcp_pagination_limit, or the tools past the first page reach no client that ignores the cursor'
		);
	}

	/** Same question for the other two listings, which are built the same way. */
	public function testResourcesAndPromptsAreAnsweredWithoutError(): void
	{
		$sSessionId = $this->initialize();

		foreach (['resources/list', 'prompts/list', 'resources/templates/list'] as $iIndex => $sMethod) {
			$oResponse = $this->ask([
				'jsonrpc' => '2.0',
				'id'      => 10 + $iIndex,
				'method'  => $sMethod,
				'params'  => new \stdClass(),
			], $sSessionId);

			$this->assertSame(200, $oResponse->getStatusCode(), "{$sMethod} did not answer 200");
			$this->assertArrayNotHasKey('error', $this->decode($oResponse), "{$sMethod} returned a JSON-RPC error");
		}
	}

	/**
	 * A call with no session id is served: there is no session to be missing. What a request may do is decided
	 * from the credential it brought, and the revision it speaks comes from MCP-Protocol-Version.
	 */
	public function testACallWithoutASessionIsServed(): void
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'tools/list',
			'params'  => new \stdClass(),
		]);

		$this->assertSame(200, $oResponse->getStatusCode());
		$this->assertNotEmpty($this->decode($oResponse)['result']['tools'] ?? []);
	}

	/**
	 * An id a client invents is not looked up either: a request that names a session the endpoint never issued is
	 * answered as one that names none, not 404 (SDK 0.10: "a session opened before the upgrade") nor 503.
	 */
	public function testASessionIdTheServerNeverIssuedChangesNothing(): void
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 5,
			'method'  => 'tools/list',
			'params'  => new \stdClass(),
		], '3f2504e0-4f89-41d3-9a0c-0305e82c3301');

		$this->assertSame(200, $oResponse->getStatusCode());
		$this->assertNotEmpty($this->decode($oResponse)['result']['tools'] ?? []);
	}
}
