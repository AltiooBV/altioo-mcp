<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ResourceRefusal;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;
use Altioo\iTop\Extension\MCP\Test\Support\PackWrittenAgainstSdk09;
use Mcp\Server\Exception\ResourceReadException;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * ADR 0024: the SDK's 0.9 exception names keep working until 3.0, and a pack throws the module's own refusals.
 *
 * The alias file is loaded by composer's `autoload.files`, so it is in force here as it is on an instance, before
 * any pack code. What is checked is that every name a pack could have used resolves to the class it moved to, that
 * a pack written against 0.9 behaves as it did (thrown and caught by the old names, through the real SDK), and
 * that the module's own `Because()` makes what the SDK catches.
 */
class SdkAliasesTest extends TestCase
{
	/**
	 * The 0.9 names, as 0.10's changelog lists them: 19 moved to `Server\`, five split three ways of which the pack's
	 * variant is the Server one. A name an SDK release moves next is added here and in sdk-aliases.php together.
	 */
	private const ALIASED = [
		'BadMethodCallException', 'ClientException', 'ConfigurationException', 'ContainerException', 'InvalidCursorException',
		'MissingRequiredClientCapabilityException', 'NotFoundExceptionInterface', 'PromptGetException', 'PromptNotFoundException',
		'RegistryException', 'RequestStateException', 'ResourceNotFoundException', 'ResourceReadException', 'ServiceNotFoundException',
		'SessionLockException', 'SessionLockTimeoutException', 'SessionStoreException', 'ToolCallException', 'ToolNotFoundException',
		'Exception', 'ExceptionInterface', 'InvalidArgumentException', 'LogicException', 'RuntimeException',
	];

	/** @return array<string, array{0: string}> */
	public static function names(): array
	{
		$aNames = [];
		foreach (self::ALIASED as $sName) {
			$aNames[$sName] = [$sName];
		}

		return $aNames;
	}

	/**
	 * @dataProvider names
	 */
	public function testAnOldNameResolvesToTheClassItMovedTo(string $sName): void
	{
		$sOld = 'Mcp\\Exception\\'.$sName;
		$sNew = 'Mcp\\Server\\Exception\\'.$sName;

		$this->assertTrue(class_exists($sOld, false) || interface_exists($sOld, false), "{$sOld} is not defined once the module's autoloader has run");
		$this->assertTrue(class_exists($sNew) || interface_exists($sNew), "{$sNew} is not in the SDK: the table is out of date");
		$this->assertSame($sNew, (new \ReflectionClass($sOld))->getName());
	}

	public function testTheAliasFileHoldsExactlyTheseNames(): void
	{
		$sSource = (string)file_get_contents(dirname(__DIR__, 3).'/sdk-aliases.php');
		preg_match_all("/^\t'([A-Za-z]+)',$/m", $sSource, $aMatches);

		$aInFile = $aMatches[1];
		$aExpected = self::ALIASED;
		sort($aInFile);
		sort($aExpected);

		$this->assertSame($aExpected, $aInFile);
	}

	public function testComposerLoadsTheAliasFileBeforeAnyPackCanRun(): void
	{
		$aComposer = json_decode((string)file_get_contents(dirname(__DIR__, 3).'/composer.json'), true);

		$this->assertSame(['sdk-aliases.php'], $aComposer['autoload']['files'] ?? null);
		$this->assertFileExists(dirname(__DIR__, 3).'/sdk-aliases.php');
	}

	/**
	 * Review 2026-11-20, N-5: requested on its own (a server that applies neither .htaccess nor web.config), the file raised one
	 * warning per name, each naming the install path. Run alone, in a process of its own with no autoloader, it does nothing and says nothing.
	 */
	public function testTheAliasFileRequestedOnItsOwnSaysNothing(): void
	{
		$sFile = dirname(__DIR__, 3).'/sdk-aliases.php';
		$aOutput = [];
		exec(escapeshellarg(PHP_BINARY).' -n -d display_errors=1 -d error_reporting=-1 -d html_errors=0 '.escapeshellarg($sFile).' 2>&1', $aOutput, $iStatus);

		$this->assertSame([], $aOutput, 'the file printed something when requested without the vendor autoloader: '.implode("\n", array_slice($aOutput, 0, 3)));
		$this->assertSame(0, $iStatus);
	}

	public function testTheAliasFileShips(): void
	{
		$sExcluded = (string)file_get_contents(dirname(__DIR__, 3).'/exclude.txt');

		$this->assertStringNotContainsString('sdk-aliases', $sExcluded, 'the aliases are loaded by autoload.files: an archive without the file dies on the first page');
	}

	public function testAPackWrittenAgainstSdk09ThrowsAndCatchesTheOldNames(): void
	{
		$aRefused = $this->call('throw');
		$this->assertTrue($aRefused['result']['isError'] ?? false, 'the old name is answered as a tool error, as on 0.9: '.json_encode($aRefused));
		$this->assertSame('The pack refuses this.', $aRefused['result']['content'][0]['text'] ?? null);

		$aCaught = $this->call('catch');
		$this->assertFalse($aCaught['result']['isError'] ?? true);
		$this->assertSame('caught by the old name: inner', $aCaught['result']['content'][0]['text'] ?? null, 'a refusal the module made is caught by the old name');
	}

	public function testTheModulesRefusalsAreWhatTheSdkCatches(): void
	{
		$oThrown = ToolRefusal::Because('Say why.', 3);
		$this->assertInstanceOf(ToolCallException::class, $oThrown);
		$this->assertSame('Say why.', $oThrown->getMessage());
		$this->assertSame(3, $oThrown->getCode());
		$this->assertTrue(ToolRefusal::Is($oThrown));
		$this->assertFalse(ToolRefusal::Is(new \RuntimeException('no')));

		$oRead = ResourceRefusal::Because('Cannot read.');
		$this->assertInstanceOf(ResourceReadException::class, $oRead);
		$this->assertTrue(ResourceRefusal::Is($oRead));
		$this->assertFalse(ResourceRefusal::Is($oThrown));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function call(string $sMode): array
	{
		$oFactory = new Psr17Factory();
		$oTool = new PackWrittenAgainstSdk09();
		$oRequest = $oFactory->createServerRequest('POST', 'https://itop.example.com/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withHeader('MCP-Protocol-Version', '2025-11-25')
			->withBody($oFactory->createStream((string)json_encode([
				'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'pack', 'arguments' => ['mode' => $sMode]],
			])));

		$oServer = Server::builder()
			->setServerInfo('alias-test', '1.0.0')
			->addTool(\Closure::fromCallable([$oTool, 'execute']), 'pack', null, 'A pack tool', null, ['type' => 'object', 'properties' => ['mode' => ['type' => 'string']]])
			->build();

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));

		return (array)json_decode((string)$oResponse->getBody(), true);
	}
}
