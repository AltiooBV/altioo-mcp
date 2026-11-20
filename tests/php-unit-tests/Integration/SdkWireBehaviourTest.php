<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\SettingNames;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use MetaModel;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the SDK does with a request once the module has built the server for its caller - the parts of an `mcp/sdk` minor that
 * can change what a caller is served or told without a line of this module changing (review 2026-11-20, SDK 0.10).
 *
 * The server is the real one, built by MCPService for a policy, and asked on both protocol eras: the handshake leg and the
 * stateless 2026-07-28 leg are different dispatchers over the same registry, and a check made on one says nothing of the other.
 *
 * - `server/discover`, which 0.10 answers, says no more than `initialize` and `tools/list` say to the same caller.
 * - A tool the caller is not served is answered as a tool that does not exist (ADR 0012, 0022), in the SDK's own words, on both eras.
 * - A nested argument reaches a tool as an array at every level, since 0.10 hands a `stdClass` to anything not typed `array`.
 *
 * @group integration
 */
class SdkWireBehaviourTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';

	private const HANDSHAKE = '2025-11-25';

	private const MODERN = '2026-07-28';

	protected function setUp(): void
	{
		parent::setUp();
		MCPExtensionCollector::CollectAll();
	}

	protected function tearDown(): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, SettingNames::MODULE_SETTING_DISABLED, []);
		RequestScope::Close();
		AccessPolicy::Forget();
		PortalMode::Forget();
		parent::tearDown();
	}

	/**
	 * Every way a caller's served set can be narrowed that is decided in ServedElements::isHidden().
	 *
	 * @return array<string, array{0: array<int, string>, 1: bool, 2: array<int, string>}>
	 */
	public static function callers(): array
	{
		return [
			'every grade'              => [['MCP'], false, []],
			'read only'                => [['MCP-read'], false, []],
			'write, not delete'        => [['MCP-write'], false, []],
			'read, advisory'           => [['MCP-read', 'MCP-advisory'], false, []],
			'a tool the operator turned off' => [['MCP'], false, ['core_object_count']],
			'portal mode'              => [['MCP'], true, []],
		];
	}

	/**
	 * @param array<int, string> $aScopes
	 * @param array<int, string> $aDisabled
	 */
	private function serveAs(array $aScopes, bool $bPortal, array $aDisabled): Server
	{
		RequestScope::Close();
		AccessPolicy::Forget();
		PortalMode::Forget();
		if ($bPortal) {
			PortalMode::Remember(self::PORTAL);
		}
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, SettingNames::MODULE_SETTING_DISABLED, $aDisabled);
		$oPolicy = AccessPolicy::FromScopes($aScopes);
		AccessPolicy::Remember($oPolicy);
		ServedElements::RememberServed();

		return (new ReflectionMethod(MCPService::class, 'createServer'))->invoke(null, $oPolicy);
	}

	/**
	 * One exchange, on one era. Handshake: the method as it is, with the protocol header. Modern: the revision travels in `_meta`
	 * and the method, and the subject of a call, are mirrored in headers.
	 *
	 * @param array<string, mixed>|object $mParams
	 *
	 * @return array{status: int, body: object}
	 */
	private function ask(Server $oServer, string $sEra, string $sMethod, array|object $mParams, ?string $sSubject = null): array
	{
		$aHeaders = ['MCP-Protocol-Version' => $sEra];
		if ($sEra === self::MODERN) {
			$aHeaders['Mcp-Method'] = $sMethod;
			if ($sSubject !== null) {
				$aHeaders['Mcp-Name'] = $sSubject;
			}
			$mParams = (object)((array)$mParams + ['_meta' => [
				'io.modelcontextprotocol/protocolVersion'    => self::MODERN,
				'io.modelcontextprotocol/clientInfo'         => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
				'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			]]);
		}

		$oFactory = new Psr17Factory();
		$oRequest = $oFactory
			->createServerRequest('POST', 'https://itop.example.com/extensions/altioo-mcp/index.php')
			->withHeader('Host', 'itop.example.com')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode(['jsonrpc' => '2.0', 'id' => 9, 'method' => $sMethod, 'params' => $mParams])));
		foreach ($aHeaders as $sName => $sValue) {
			$oRequest = $oRequest->withHeader($sName, $sValue);
		}

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));
		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}
		$oDecoded = json_decode((string)$oBody);
		$this->assertIsObject($oDecoded, "{$sMethod} on {$sEra} was answered with something that is not JSON: ".(string)$oBody);

		return ['status' => $oResponse->getStatusCode(), 'body' => $oDecoded];
	}

	/** @return array<int, string> */
	private function listedTools(Server $oServer, string $sEra): array
	{
		$aAnswer = $this->ask($oServer, $sEra, 'tools/list', new \stdClass());
		$this->assertSame(200, $aAnswer['status'], "tools/list on {$sEra}");
		$aNames = array_map(static fn (object $oTool): string => $oTool->name, $aAnswer['body']->result->tools ?? []);
		sort($aNames);

		return $aNames;
	}

	/**
	 * `server/discover` was not answered at all by 0.9. It carries the revisions, the capabilities and the instructions, and no
	 * element. It must say no more to a caller than initialize does, and name no tool tools/list withholds from it.
	 *
	 * @dataProvider callers
	 *
	 * @param array<int, string> $aScopes
	 * @param array<int, string> $aDisabled
	 */
	public function testDiscoverSaysNoMoreThanInitializeAndToolsListDo(array $aScopes, bool $bPortal, array $aDisabled): void
	{
		$oServer = $this->serveAs($aScopes, $bPortal, $aDisabled);

		$aInit = $this->ask($oServer, self::HANDSHAKE, 'initialize', [
			'protocolVersion' => self::HANDSHAKE,
			'capabilities'    => new \stdClass(),
			'clientInfo'      => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
		]);
		$oInit = $aInit['body']->result;

		$aDiscover = $this->ask($oServer, self::MODERN, 'server/discover', new \stdClass());
		$this->assertSame(200, $aDiscover['status'], 'server/discover was not answered: '.json_encode($aDiscover['body']));
		$this->assertObjectNotHasProperty('error', $aDiscover['body'], 'server/discover returned an error');
		$oDiscover = $aDiscover['body']->result;

		$this->assertContains(self::MODERN, $oDiscover->supportedVersions ?? [], 'discover names the revision it is asked on');
		$this->assertEquals($oInit->instructions ?? null, $oDiscover->instructions ?? null, 'discover carries the instructions this caller is given by initialize, no other block');
		// The one difference: the stateless leg offers `logging` to every caller (the level travels with each request there), the
		// handshake leg only to a server that keeps sessions. It names no element and does not depend on the caller.
		$oDiscoverCapabilities = clone $oDiscover->capabilities;
		unset($oDiscoverCapabilities->logging);
		$this->assertEquals($oInit->capabilities, $oDiscoverCapabilities, 'discover offers the capabilities initialize offers');

		$aHandshakeTools = $this->listedTools($oServer, self::HANDSHAKE);
		$this->assertNotSame([], $aHandshakeTools);
		$this->assertSame($aHandshakeTools, $this->listedTools($oServer, self::MODERN), 'the stateless leg lists the tools the handshake leg lists');

		// Not a tool the operator named in mcp_disabled_tools: the instructions are narrowed by toolset and grade, not tool by tool, so
		// they still name one (a public tool's name, with initialize saying the same: asserted equal above). That predates SDK 0.10.
		$aWithheld = array_values(array_diff(array_keys(MCPRegistry::GetTools()), $aHandshakeTools, $aDisabled));
		$sDiscovered = (string)json_encode($aDiscover['body']);
		foreach ($aWithheld as $sTool) {
			$this->assertStringNotContainsString($sTool, $sDiscovered, "server/discover names {$sTool}, which tools/list withholds from this caller");
		}
	}

	/**
	 * Hidden, turned off and absent are one answer, on both eras: the SDK's own tool-not-found, with only the name that was asked for
	 * in it. A different code or a different sentence would tell a caller which of the three it met.
	 *
	 * @dataProvider callers
	 *
	 * @param array<int, string> $aScopes
	 * @param array<int, string> $aDisabled
	 */
	public function testAToolThatIsNotServedIsAnsweredAsOneThatDoesNotExist(array $aScopes, bool $bPortal, array $aDisabled): void
	{
		$oServer = $this->serveAs($aScopes, $bPortal, $aDisabled);

		foreach ([self::HANDSHAKE, self::MODERN] as $sEra) {
			$aListed = $this->listedTools($oServer, $sEra);
			$aWithheld = array_values(array_diff(array_keys(MCPRegistry::GetTools()), $aListed));
			if ($aWithheld === []) {
				continue;
			}

			$aUnknown = $this->callByName($oServer, $sEra, 'no_such_tool_anywhere');
			$this->assertNotNull($aUnknown['body']->error ?? null, 'an unknown tool is answered as an error on '.$sEra);

			foreach ($aWithheld as $sTool) {
				$aHidden = $this->callByName($oServer, $sEra, $sTool);

				$this->assertSame($aUnknown['status'], $aHidden['status'], "{$sTool} on {$sEra}: another HTTP status than an unknown tool");
				$this->assertSame($aUnknown['body']->error->code ?? null, $aHidden['body']->error->code ?? null, "{$sTool} on {$sEra}: another code than an unknown tool");
				$this->assertSame(
					str_replace('no_such_tool_anywhere', 'X', (string)($aUnknown['body']->error->message ?? '')),
					str_replace($sTool, 'X', (string)($aHidden['body']->error->message ?? '')),
					"{$sTool} on {$sEra}: another sentence than an unknown tool"
				);
				$this->assertObjectNotHasProperty('result', $aHidden['body'], "{$sTool} on {$sEra} ran");
			}
		}
	}

	/** @return array{status: int, body: object} */
	private function callByName(Server $oServer, string $sEra, string $sTool): array
	{
		return $this->ask($oServer, $sEra, 'tools/call', ['name' => $sTool, 'arguments' => new \stdClass()], $sTool);
	}

	/**
	 * 0.10: a nested object arrives as a `stdClass` for any parameter not typed `array`, and as an array at every level for one that is.
	 * Every core tool takes its structured arguments typed `array` (the contract test below), so a check that reads `$fields` or
	 * `$filters` with `is_array()` sees what it did on 0.9. This asks the real SDK, through the real server, what a handler receives.
	 */
	public function testNestedArgumentsReachAnArrayTypedParameterAsArraysAtEveryLevel(): void
	{
		$oServer = $this->serveAs(['MCP'], false, []);
		$aPayloads = [
			'a link set of objects'  => ['fields' => ['contacts_list' => [['contact_id' => 5, 'role' => 'x'], ['contact_id' => 6]], 'title' => 't']],
			'a filter with operators' => ['filters' => ['status' => ['in' => ['new', 'closed']], 'org' => ['not' => ['x' => ['y' => 1]]]]],
			'an empty map'            => ['fields' => new \stdClass()],
			'a bulk of empty rows'    => ['objects' => [new \stdClass(), ['a' => new \stdClass()]]],
		];

		// What a core tool's execute() is declared as: a handler typed like it, and the one the module registers for every tool.
		$aSeen = [];
		MetaModel::GetConfig(); // keep the iTop test case's bootstrap in play; the probe itself needs none
		$oProbe = Server::builder()
			->setServerInfo('probe', '1.0.0')
			->addTool(
				static function (array $fields = [], array $filters = [], array $objects = []) use (&$aSeen): string {
					$aSeen = ['fields' => $fields, 'filters' => $filters, 'objects' => $objects];

					return 'ok';
				},
				'probe',
				null,
				'Records what it received',
				null,
				['type' => 'object', 'properties' => [
					'fields'  => ['type' => 'object'],
					'filters' => ['type' => 'object'],
					'objects' => ['type' => 'array', 'items' => ['type' => 'object']],
				]]
			)
			->build();

		foreach ([self::HANDSHAKE, self::MODERN] as $sEra) {
			foreach ($aPayloads as $sLabel => $aArguments) {
				$aSeen = [];
				$aAnswer = $this->ask($oProbe, $sEra, 'tools/call', ['name' => 'probe', 'arguments' => (object)$aArguments], 'probe');
				$this->assertObjectNotHasProperty('error', $aAnswer['body'], "{$sLabel} on {$sEra} was refused: ".json_encode($aAnswer['body']));
				$this->assertNotSame([], $aSeen, "{$sLabel} on {$sEra} did not reach the handler");
				$this->assertNoObject($aSeen, "{$sLabel} on {$sEra}");
			}
		}
		$this->assertInstanceOf(Server::class, $oServer);
	}

	/**
	 * What 0.10 changed in the codes a caller meets, pinned so that a client's own expectations are stated: an unknown tool and one
	 * the caller is not served are -32602 on both eras (as on 0.9), `arguments` that are not a JSON object are -32602 (a PHP client
	 * that encodes an empty array as `[]` used to be served), and an argument that cannot be cast to its parameter is -32602, where
	 * 0.9 answered -32603 or handed a string the word "Array".
	 */
	public function testTheCodesACallerMeets(): void
	{
		$oServer = $this->serveAs(['MCP'], false, []);

		foreach ([self::HANDSHAKE, self::MODERN] as $sEra) {
			$aUnknown = $this->callByName($oServer, $sEra, 'no_such_tool_anywhere');
			$this->assertSame(-32602, $aUnknown['body']->error->code ?? null, "an unknown tool on {$sEra}");

			$aList = $this->ask($oServer, $sEra, 'tools/call', ['name' => 'core_current_user', 'arguments' => []], 'core_current_user');
			$this->assertSame(-32602, $aList['body']->error->code ?? null, "arguments sent as a JSON list on {$sEra}: ".json_encode($aList['body']));

			$aCast = $this->ask($oServer, $sEra, 'tools/call', ['name' => 'core_class_schema', 'arguments' => ['class' => ['Person']]], 'core_class_schema');
			$this->assertSame(-32602, $aCast['body']->error->code ?? null, "a list for a string parameter on {$sEra}: ".json_encode($aCast['body']));
		}
	}

	private function assertNoObject(mixed $mValue, string $sWhere): void
	{
		if (is_object($mValue)) {
			$this->fail("{$sWhere}: a ".get_class($mValue).' reached an array-typed parameter, so an is_array() check would pass it by');
		}
		if (is_array($mValue)) {
			foreach ($mValue as $mChild) {
				$this->assertNoObject($mChild, $sWhere);
			}
		}
	}
}
