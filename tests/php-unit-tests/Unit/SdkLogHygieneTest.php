<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TestIssueLog;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the SDK itself logs for a tool call, read through the module's log bridge.
 *
 * LogAPILoggerTest holds the bridge with calls written by hand. What the SDK passes it is the SDK's to change: 0.10
 * moved the `Executing tool` record after the schema check and added `Calling tool` at info, and a minor of a pre-1.0
 * SDK may add another key that carries what the caller sent. So this runs the real server at every level through the
 * bridge, on both protocol eras and on every way a call can end, and requires that none of what the caller sent - a
 * password in a field, a value in a nested object - reaches the log. A record that names the tool is fine; one that
 * carries the call's data is not (guide section 6.7).
 *
 * It asks for the log at its most talkative: the bridge is given every level, as an instance with
 * `log_level_min => Debug` is.
 */
class SdkLogHygieneTest extends TestCase
{
	private const SECRET = 'hunter2-the-callers-own-text';

	protected function setUp(): void
	{
		parent::setUp();
		TestIssueLog::Reset();
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
	 */
	public static function calls(): array
	{
		$aHandshake = ['MCP-Protocol-Version' => '2025-11-25'];
		$aModern = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call'];
		$aMeta = [
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientInfo'         => ['name' => 'phpunit', 'version' => '1'],
			'io.modelcontextprotocol/clientCapabilities' => [],
		];
		$fCall = static fn (string $sName, array $aArguments, array $aExtra = []): array => [
			'jsonrpc' => '2.0',
			'id'      => 7,
			'method'  => 'tools/call',
			'params'  => ['name' => $sName, 'arguments' => $aArguments] + $aExtra,
		];
		$aNested = ['fields' => ['private_log' => self::SECRET, 'nested' => ['deeper' => self::SECRET]]];

		return [
			'handshake era, a call that succeeds'                => [$fCall('store', $aNested), $aHandshake],
			'handshake era, a tool that throws its own refusal'  => [$fCall('refuse', $aNested), $aHandshake],
			'handshake era, a tool that fails unexpectedly'      => [$fCall('crash', $aNested), $aHandshake],
			'handshake era, arguments the schema refuses'        => [$fCall('store', ['fields' => self::SECRET]), $aHandshake],
			'handshake era, a tool that does not exist'          => [$fCall('nowhere', $aNested), $aHandshake],
			'modern era, a call that succeeds'                   => [$fCall('store', $aNested, ['_meta' => $aMeta]), $aModern + ['Mcp-Name' => 'store']],
			'modern era, a tool that throws its own refusal'     => [$fCall('refuse', $aNested, ['_meta' => $aMeta]), $aModern + ['Mcp-Name' => 'refuse']],
			'modern era, a tool that fails unexpectedly'         => [$fCall('crash', $aNested, ['_meta' => $aMeta]), $aModern + ['Mcp-Name' => 'crash']],
			'modern era, arguments the schema refuses'           => [$fCall('store', ['fields' => self::SECRET], ['_meta' => $aMeta]), $aModern + ['Mcp-Name' => 'store']],
			'modern era, a tool that does not exist'             => [$fCall('nowhere', $aNested, ['_meta' => $aMeta]), $aModern + ['Mcp-Name' => 'nowhere']],
		];
	}

	/**
	 * @dataProvider calls
	 *
	 * @param array<string, mixed>  $aBody
	 * @param array<string, string> $aHeaders
	 */
	public function testNothingTheCallerSentReachesTheLog(array $aBody, array $aHeaders): void
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
			->setServerInfo('log-hygiene', '1.0.0')
			->setLogger(new LogAPILogger(TestIssueLog::class))
			->addTool(
				static fn (array $fields): string => 'stored',
				'store',
				null,
				'Takes fields',
				null,
				['type' => 'object', 'properties' => ['fields' => ['type' => 'object']], 'required' => ['fields']]
			)
			->addTool(static function (array $fields): string {
				throw new ToolCallException('The tool refuses this call.');
			}, 'refuse', null, 'Refuses', null, ['type' => 'object', 'properties' => ['fields' => ['type' => 'object']], 'required' => ['fields']])
			->addTool(static function (array $fields): string {
				throw new \RuntimeException('unexpected');
			}, 'crash', null, 'Crashes', null, ['type' => 'object', 'properties' => ['fields' => ['type' => 'object']], 'required' => ['fields']])
			->build();

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));
		$this->assertContains($oResponse->getStatusCode(), [200, 400], 'the call was not answered at all');
		$this->assertNotEmpty(TestIssueLog::$aRecorded, 'the SDK logged nothing, so this test would pass on a logger that was never reached');

		$sLogged = (string)json_encode(TestIssueLog::$aRecorded, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('hunter2', $sLogged, 'the data of a call reached the log: '.$sLogged);
	}
}
