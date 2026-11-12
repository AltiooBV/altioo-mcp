<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\CoreElement;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a client is served, compared with a committed copy (doc/adr/0008).
 *
 * Nothing else notices when a tool description is reworded, a schema gains a
 * property or `mcp/sdk` changes how it renders an annotation: every call still
 * succeeds, and the agent on the other end simply behaves differently. So the
 * answers a client sees first - the handshake, `tools/list`, the resources,
 * the resource templates and the prompts - are written down, one file per
 * capability grade and one for portal mode, and any difference fails here until
 * a person has read it and accepted it by regenerating the file.
 *
 * What is compared is the module's own elements: those under src/Core/, the
 * ones {@see CoreElement::IsOwn()} recognises. A pack installed on the instance
 * adds its own and is none of this file's business, and the class lists a
 * datamodel produces are not in any description, so the copy is the same on
 * every iTop branch the matrix installs - the matrix is what proves that.
 * The server instructions are the exception to "own elements only": they name
 * the prompts the caller is served, so an instance with a pack that adds a
 * prompt renders a different block. The copy is taken on an instance with this
 * module and nothing else, which is what CI installs.
 *
 * Not in it, deliberately: the module version (it moves every release and says
 * nothing about what an agent does), the order the registry happens to scan
 * (elements are sorted by name; a model does not read a list for its order),
 * and the `advisory` modifier, which changes what a write does, not what is
 * listed.
 *
 * To accept a change: MCP_UPDATE_SNAPSHOTS=1, see CONTRIBUTING.md.
 *
 * @group integration
 */
class ToolSurfaceSnapshotTest extends ItopDataTestCaseAlias
{
	private const UPDATE_VARIABLE = 'MCP_UPDATE_SNAPSHOTS';

	/** The protocol version a current client offers; the server answers with its own. */
	private const CLIENT_PROTOCOL_VERSION = '2025-06-18';

	/** @var string What a portal-mode caller is served is read for this portal. */
	private const PORTAL = 'itop-portal';

	protected function setUp(): void
	{
		parent::setUp();
		MCPExtensionCollector::CollectAll();
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		AccessPolicy::Forget();
		PortalMode::Forget();
		parent::tearDown();
	}

	/**
	 * Each grade a token can carry, and portal mode. The scopes are the ones an
	 * administrator ticks (TokenScopes), so the file is named for what they
	 * would say, not for how the policy object is built.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: bool}>
	 */
	public static function surfaces(): array
	{
		return [
			'all'    => ['all', ['MCP'], false],
			'read'   => ['read', ['MCP-read'], false],
			'write'  => ['write', ['MCP-write'], false],
			'delete' => ['delete', ['MCP-delete'], false],
			'portal' => ['portal', ['MCP'], true],
		];
	}

	/**
	 * @dataProvider surfaces
	 *
	 * @param array<int, string> $aScopes
	 */
	public function testTheServedSurfaceIsTheCommittedOne(string $sName, array $aScopes, bool $bPortal): void
	{
		$aActual = $this->surface($aScopes, $bPortal);
		$sPath = $this->pathOf($sName);

		if (getenv(self::UPDATE_VARIABLE) === '1') {
			$this->write($sPath, $aActual);
			$this->markTestSkipped("{$sPath} regenerated; read the diff before committing it.");
		}

		$this->assertFileExists($sPath, "No committed surface for '{$sName}'. ".$this->howToAccept());

		$aExpected = json_decode((string)file_get_contents($sPath), false, 512, JSON_THROW_ON_ERROR);
		$aDiff = self::Diff(self::Flatten($aExpected->surface), self::Flatten($aActual['surface']));

		$this->assertSame(
			[],
			$aDiff,
			"The surface served to '{$sName}' is not the committed one (".count($aDiff)." difference(s)):\n\n"
			.implode("\n", array_map(static fn (string $s): string => '  '.$s, array_slice($aDiff, 0, 40)))
			.(count($aDiff) > 40 ? "\n  ... and ".(count($aDiff) - 40).' more' : '')
			."\n\n".$this->howToAccept()
		);
	}

	/**
	 * A comparison that cannot fail proves nothing: the grades have to differ
	 * from each other, or all five files could be the same empty answer.
	 */
	public function testTheGradesAreServedDifferentThings(): void
	{
		$aToolNames = [];
		foreach (self::surfaces() as $sName => [, $aScopes, $bPortal]) {
			$aToolNames[$sName] = array_column($this->surface($aScopes, $bPortal)['surface']['tools'], 'name');
			$this->assertNotSame([], $aToolNames[$sName], "{$sName} is served no tool, so its copy would compare nothing");
		}

		$this->assertLessThan(count($aToolNames['all']), count($aToolNames['read']), 'a read-only token is served every tool');
		$this->assertLessThan(count($aToolNames['all']), count($aToolNames['portal']), 'portal mode is served every tool');
	}

	private function howToAccept(): string
	{
		return "If the change is intended, regenerate the copy and commit it with the change:\n"
			."  ".self::UPDATE_VARIABLE."=1 ITOP_ROOT=/path/to/itop/web composer test:integration -- --filter ToolSurfaceSnapshotTest\n"
			."  (in the local CI runner: ".self::UPDATE_VARIABLE."=1 tools/ci/local/run.sh integration)\n"
			."A reworded description or an mcp/sdk bump is a change an agent can see: run tools/evals too (CONTRIBUTING.md).";
	}

	/**
	 * What a client is served, for a token with these scopes.
	 *
	 * @param array<int, string> $aScopes
	 *
	 * @return array{surface: array<string, mixed>}
	 */
	private function surface(array $aScopes, bool $bPortal): array
	{
		RequestScope::Close();
		AccessPolicy::Forget();
		PortalMode::Forget();
		if ($bPortal) {
			PortalMode::Remember(self::PORTAL);
		}
		$oPolicy = AccessPolicy::FromScopes($aScopes);
		AccessPolicy::Remember($oPolicy);
		ServedElements::RememberServed();

		$oServer = (new ReflectionMethod(MCPService::class, 'createServer'))->invoke(null, $oPolicy);

		$oInit = $this->ask($oServer, 1, 'initialize', [
			'protocolVersion' => self::CLIENT_PROTOCOL_VERSION,
			'capabilities'    => new \stdClass(),
			'clientInfo'      => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
		], null);
		$sSession = $oInit['headers'];
		$aInit = $oInit['body']->result;

		$aOwn = $this->ownIdentifiers();

		return ['surface' => [
			'protocolVersion'   => $aInit->protocolVersion ?? null,
			'capabilities'      => $aInit->capabilities ?? null,
			'serverName'        => $aInit->serverInfo->name ?? null,
			'instructions'      => $aInit->instructions ?? null,
			'tools'             => $this->listing($oServer, 'tools/list', 'tools', 'name', $aOwn['tools'], $sSession),
			'resources'         => $this->listing($oServer, 'resources/list', 'resources', 'uri', $aOwn['resources'], $sSession),
			'resourceTemplates' => $this->listing($oServer, 'resources/templates/list', 'resourceTemplates', 'uriTemplate', $aOwn['templates'], $sSession),
			'prompts'           => $this->listing($oServer, 'prompts/list', 'prompts', 'name', $aOwn['prompts'], $sSession),
		]];
	}

	/**
	 * The identifiers of the elements this module defines, by kind.
	 *
	 * @return array{tools: array<int, string>, resources: array<int, string>, templates: array<int, string>, prompts: array<int, string>}
	 */
	private function ownIdentifiers(): array
	{
		$aOwn = static function (array $aElements): array {
			$aKeep = [];
			foreach ($aElements as $sIdentifier => $oElement) {
				if (CoreElement::IsOwn($oElement)) {
					$aKeep[] = (string)$sIdentifier;
				}
			}

			return $aKeep;
		};

		return [
			'tools'     => $aOwn(MCPRegistry::GetTools()),
			'resources' => $aOwn(MCPRegistry::GetResources()),
			'templates' => $aOwn(MCPRegistry::GetResourceTemplates()),
			'prompts'   => $aOwn(MCPRegistry::GetPrompts()),
		];
	}

	/**
	 * One listing, restricted to the module's own elements and sorted.
	 *
	 * @param array<int, string> $aOwn
	 *
	 * @return array<int, object>
	 */
	private function listing(Server $oServer, string $sMethod, string $sKey, string $sIdField, array $aOwn, string $sSession): array
	{
		$oBody = $this->ask($oServer, 2, $sMethod, new \stdClass(), $sSession)['body'];
		$this->assertObjectNotHasProperty('error', $oBody, "{$sMethod} returned a JSON-RPC error");
		$this->assertObjectNotHasProperty('nextCursor', $oBody->result, "{$sMethod} came back paginated, so the copy would hold only the first page");

		$aItems = array_values(array_filter(
			$oBody->result->$sKey ?? [],
			static fn (object $oItem): bool => in_array((string)$oItem->$sIdField, $aOwn, true)
		));
		usort($aItems, static fn (object $a, object $b): int => strcmp((string)$a->$sIdField, (string)$b->$sIdField));

		return $aItems;
	}

	/**
	 * One exchange. The first returns the session id in `headers`, which every
	 * later call carries; a stateless server accepts any, but a request
	 * without one is refused before it reaches a handler.
	 *
	 * @param array<string, mixed>|object $mParams
	 *
	 * @return array{headers: string, body: object}
	 */
	private function ask(Server $oServer, int $iId, string $sMethod, array|object $mParams, ?string $sSession): array
	{
		$oFactory = new Psr17Factory();
		$oRequest = $oFactory
			->createServerRequest('POST', 'https://itop.example.com/extensions/altioo-mcp/index.php')
			->withHeader('Host', 'itop.example.com')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode(['jsonrpc' => '2.0', 'id' => $iId, 'method' => $sMethod, 'params' => $mParams])));
		if ($sSession !== null) {
			$oRequest = $oRequest->withHeader('Mcp-Session-Id', $sSession);
		}

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));
		$this->assertSame(200, $oResponse->getStatusCode(), "{$sMethod} was not answered");

		return ['headers' => $oResponse->getHeaderLine('Mcp-Session-Id'), 'body' => $this->decode($oResponse)];
	}

	private function decode(ResponseInterface $oResponse): object
	{
		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}
		$oDecoded = json_decode((string)$oBody, false);
		$this->assertIsObject($oDecoded, 'the server answered something that is not JSON');

		return $oDecoded;
	}

	private function pathOf(string $sName): string
	{
		return (getenv('MCP_SNAPSHOT_DIR') ?: __DIR__.'/Snapshots').'/surface-'.$sName.'.json';
	}

	/**
	 * @param array{surface: array<string, mixed>} $aActual
	 */
	private function write(string $sPath, array $aActual): void
	{
		if (!is_dir(dirname($sPath))) {
			mkdir(dirname($sPath), 0775, true);
		}
		$aDocument = [
			'_about'    => 'What a client is served, compared by tests/php-unit-tests/Integration/ToolSurfaceSnapshotTest.php. Regenerate, read the diff, commit: see CONTRIBUTING.md.',
			'generated' => date('Y-m-d'),
			'sdk'       => class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('mcp/sdk')
				? \Composer\InstalledVersions::getPrettyVersion('mcp/sdk')
				: null,
			'surface'   => $aActual['surface'],
		];
		file_put_contents($sPath, json_encode($aDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
	}

	/**
	 * A decoded document as path => scalar, so that a difference names where it
	 * is. Lists of elements are keyed by their identifier rather than their
	 * position: removing one tool must not read as every tool after it changing.
	 *
	 * @return array<string, string>
	 */
	public static function Flatten(mixed $mValue, string $sPath = ''): array
	{
		if (is_object($mValue) || is_array($mValue)) {
			$aOut = [];
			$aPairs = (array)$mValue;
			if ($aPairs === []) {
				return [$sPath => is_array($mValue) ? '[]' : '{}'];
			}
			foreach ($aPairs as $mKey => $mChild) {
				$sKey = (string)$mKey;
				if (is_array($mValue) && (is_object($mChild)) && (isset($mChild->name) || isset($mChild->uri) || isset($mChild->uriTemplate))) {
					$sKey = (string)($mChild->name ?? $mChild->uri ?? $mChild->uriTemplate);
				}
				$aOut += self::Flatten($mChild, $sPath === '' ? $sKey : $sPath.'.'.$sKey);
			}

			return $aOut;
		}

		return [$sPath => json_encode($mValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
	}

	/**
	 * @param array<string, string> $aExpected
	 * @param array<string, string> $aActual
	 *
	 * @return array<int, string> One line per added, removed or changed path.
	 */
	public static function Diff(array $aExpected, array $aActual): array
	{
		$aLines = [];
		$clip = static fn (string $s): string => mb_strlen($s) > 240 ? mb_substr($s, 0, 240).'…' : $s;
		foreach ($aExpected as $sPath => $sValue) {
			if (!array_key_exists($sPath, $aActual)) {
				$aLines[] = "- {$sPath}: {$clip($sValue)}";
			} elseif ($aActual[$sPath] !== $sValue) {
				$aLines[] = "~ {$sPath}:\n      was {$clip($sValue)}\n      now {$clip($aActual[$sPath])}";
			}
		}
		foreach ($aActual as $sPath => $sValue) {
			if (!array_key_exists($sPath, $aExpected)) {
				$aLines[] = "+ {$sPath}: {$clip($sValue)}";
			}
		}

		return $aLines;
	}
}
