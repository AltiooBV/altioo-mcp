<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Http\Discovery\Psr17Factory;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Server\Transport\StreamableHttpTransport;
use ReflectionMethod;

/**
 * A tool called through the SDK's tools/call, as a client would, and its
 * structured result checked against the outputSchema the tool declares.
 *
 * Shared by WriteResultSchemaTest, for the console's writes, and
 * PortalWriteTest, for the portal's, which need its user and catalogue in
 * place first. The structured half is decoded without the assoc flag, which
 * keeps `{}` and `[]` apart - the difference the check exists for.
 */
trait CallsToolsOnTheWire
{
	private int $iRequestId = 100;

	/**
	 * @param array<string, mixed> $aArguments
	 *
	 * @return \stdClass The structured half, as it was on the wire.
	 */
	private function assertConforms(string $sTool, array $aArguments): \stdClass
	{
		$oResult = $this->call($sTool, $aArguments);
		$sText = $oResult->content[0]->text ?? '';

		$this->assertFalse($oResult->isError ?? false, "{$sTool} answered an error: {$sText}");
		$this->assertInstanceOf(\stdClass::class, $oResult->structuredContent ?? null, "{$sTool} declares an output schema and sent no structuredContent");

		MCPExtensionCollector::CollectAll();
		$aErrors = (new SchemaValidator())->validateAgainstJsonSchema(
			$oResult->structuredContent,
			MCPRegistry::GetTools()[$sTool]->getOutputSchema()
		);

		$this->assertSame([], $aErrors, sprintf('%s answered outside its own output schema: %s', $sTool, json_encode($aErrors)));

		return $oResult->structuredContent;
	}

	/**
	 * @param array<string, mixed> $aArguments
	 */
	private function call(string $sTool, array $aArguments): \stdClass
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => '2025-06-18',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
			],
		]);

		$oAnswer = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => ++$this->iRequestId,
			'method'  => 'tools/call',
			'params'  => ['name' => $sTool, 'arguments' => (object)$aArguments],
		], $oResponse['session']);

		$this->assertInstanceOf(\stdClass::class, $oAnswer['body']->result ?? null, "{$sTool} was answered with no result: ".json_encode($oAnswer['body']));

		return $oAnswer['body']->result;
	}

	/**
	 * @param array<string, mixed> $aPayload
	 *
	 * @return array{session: string, body: \stdClass}
	 */
	private function ask(array $aPayload, ?string $sSessionId = null): array
	{
		MCPExtensionCollector::CollectAll();
		$oServer = (new ReflectionMethod(MCPService::class, 'createServer'))->invoke(null, AccessPolicy::Unrestricted());

		$oFactory = new Psr17Factory();
		$oRequest = $oFactory
			->createServerRequest('POST', 'https://itop.example.com/extensions/altioo-mcp/index.php')
			->withHeader('Host', 'itop.example.com')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode($aPayload)));

		if ($sSessionId !== null && $sSessionId !== '') {
			$oRequest = $oRequest->withHeader('Mcp-Session-Id', $sSessionId);
		}

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));

		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}

		// Not decoded to arrays: `{}` and `[]` are the difference under test.
		$oDecoded = json_decode((string)$oBody);
		$this->assertInstanceOf(\stdClass::class, $oDecoded, 'the server answered something that is not a JSON object');

		return ['session' => $oResponse->getHeaderLine('Mcp-Session-Id'), 'body' => $oDecoded];
	}
}
