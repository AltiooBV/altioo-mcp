<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TestIssueLog;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

class LogAPILoggerTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		TestIssueLog::Reset();
	}

	private function logger(?string $sChannel = null): LogAPILogger
	{
		return new LogAPILogger(TestIssueLog::class, $sChannel);
	}

	/** @return array{level: string, message: string, channel: ?string, context: array} */
	private function lastCall(): array
	{
		$this->assertNotEmpty(TestIssueLog::$aRecorded, 'nothing was forwarded to LogAPI');

		return TestIssueLog::$aRecorded[count(TestIssueLog::$aRecorded) - 1];
	}

	public function testIsAPsr3Logger(): void
	{
		$this->assertInstanceOf(LoggerInterface::class, $this->logger());
	}

	public function testRejectsAClassThatIsNotALogApi(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be a subclass of LogAPI');

		new LogAPILogger(\NotALogAPI::class);
	}

	public function testRejectsAnUnknownLevel(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not a valid PSR-3 log level');

		$this->logger()->log('chatty', 'message');
	}

	/**
	 * PSR-3 defines eight levels, LogAPI six. emergency/alert/critical/error all
	 * collapse onto Error, and notice joins info.
	 *
	 * @dataProvider levelMappingProvider
	 */
	public function testMapsPsr3LevelsOntoLogApiLevels(string $sPsrLevel, string $sExpected): void
	{
		$this->logger()->log($sPsrLevel, 'message');

		$this->assertSame($sExpected, $this->lastCall()['level']);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function levelMappingProvider(): array
	{
		return [
			'emergency' => [LogLevel::EMERGENCY, \LogAPI::LEVEL_ERROR],
			'alert' => [LogLevel::ALERT, \LogAPI::LEVEL_ERROR],
			'critical' => [LogLevel::CRITICAL, \LogAPI::LEVEL_ERROR],
			'error' => [LogLevel::ERROR, \LogAPI::LEVEL_ERROR],
			'warning' => [LogLevel::WARNING, \LogAPI::LEVEL_WARNING],
			'notice' => [LogLevel::NOTICE, \LogAPI::LEVEL_INFO],
			'info' => [LogLevel::INFO, \LogAPI::LEVEL_INFO],
			'debug' => [LogLevel::DEBUG, \LogAPI::LEVEL_DEBUG],
		];
	}

	public function testPsr3ShorthandMethodsReachLogApi(): void
	{
		$this->logger()->error('went wrong');

		$this->assertSame(\LogAPI::LEVEL_ERROR, $this->lastCall()['level']);
		$this->assertSame('went wrong', $this->lastCall()['message']);
	}

	public function testInterpolatesPlaceholders(): void
	{
		$this->logger()->info('User {user} called {tool}', ['user' => 'jdoe', 'tool' => 'ObjectGet']);

		$this->assertSame('User jdoe called ObjectGet', $this->lastCall()['message']);
	}

	public function testLeavesUnmatchedPlaceholdersAlone(): void
	{
		$this->logger()->info('User {user} called {tool}', ['user' => 'jdoe']);

		$this->assertSame('User jdoe called {tool}', $this->lastCall()['message']);
	}

	/**
	 * PSR-3 says values must be castable to string; anything else is skipped
	 * rather than fatal.
	 */
	public function testSkipsNonStringableContextValues(): void
	{
		$this->logger()->info('payload {data}', ['data' => ['not', 'stringable']]);

		$this->assertSame('payload {data}', $this->lastCall()['message']);
	}

	public function testStringableObjectsAreInterpolated(): void
	{
		$oValue = new class {
			public function __toString(): string
			{
				return 'stringified';
			}
		};

		$this->logger()->info('payload {data}', ['data' => $oValue]);

		$this->assertSame('payload stringified', $this->lastCall()['message']);
	}

	public function testContextIsForwardedIntact(): void
	{
		$this->logger()->info('hello {who}', ['who' => 'world', 'extra' => 'kept']);

		$this->assertSame(['who' => 'world', 'extra' => 'kept'], $this->lastCall()['context']);
	}

	public function testChannelDefaultsToNullSoLogApiPicksItsOwn(): void
	{
		$this->logger()->info('message');

		$this->assertNull($this->lastCall()['channel']);
	}

	public function testInstanceChannelIsUsed(): void
	{
		$this->logger('MyModule')->info('message');

		$this->assertSame('MyModule', $this->lastCall()['channel']);
	}

	public function testContextChannelOverridesTheInstanceChannel(): void
	{
		$this->logger('MyModule')->info('message', ['channel' => 'PerCall']);

		$this->assertSame('PerCall', $this->lastCall()['channel']);
	}

	public function testNonStringContextChannelIsIgnored(): void
	{
		$this->logger('MyModule')->info('message', ['channel' => 42]);

		$this->assertSame('MyModule', $this->lastCall()['channel']);
	}

	public function testWithChannelReturnsANewInstanceAndLeavesTheOriginalAlone(): void
	{
		$oOriginal = $this->logger('Original');
		$oClone = $oOriginal->withChannel('Clone');

		$this->assertNotSame($oOriginal, $oClone);

		$oClone->info('from clone');
		$this->assertSame('Clone', $this->lastCall()['channel']);

		$oOriginal->info('from original');
		$this->assertSame('Original', $this->lastCall()['channel']);
	}

	public function testMessageWithoutPlaceholdersTakesTheFastPath(): void
	{
		$this->logger()->info('no braces here', ['unused' => 'value']);

		$this->assertSame('no braces here', $this->lastCall()['message']);
	}

	/**
	 * Review 2026-11-18, RT-2: the SDK logs an exception under `exception`, and LogAPI wrote the
	 * object, trace and all, into log/error.log - with PHP's default exception settings the
	 * trace carries the PSR-7 request built from $_SERVER, a Basic password included, and a
	 * few megabytes per call.
	 */
	public function testAnExceptionIsLoggedAsItsClassCodeAndMessageNeverAsAnObject(): void
	{
		$oException = new \RuntimeException('Tool not found: nope', 7);

		$this->logger()->error('Tool not found', ['name' => 'nope', 'exception' => $oException]);

		$aContext = $this->lastCall()['context'];
		$this->assertArrayNotHasKey('exception', $aContext, 'the exception object reaches the log');
		$this->assertSame(\RuntimeException::class, $aContext['exception_class']);
		$this->assertSame(7, $aContext['exception_code']);
		$this->assertSame('Tool not found: nope', $aContext['exception_message']);
		$this->assertSame('nope', $aContext['name']);
		foreach ($aContext as $mValue) {
			$this->assertFalse(is_object($mValue) || is_array($mValue), 'only scalars are handed to LogAPI');
		}
	}

	public function testAnExceptionLineCarriesAReferenceTheAuditRowCarriesToo(): void
	{
		ErrorReport::ForgetReferences();

		$this->logger()->error('Unhandled error during tool execution', ['exception' => new \LogicException('x')]);

		$sReference = $this->lastCall()['context']['error_reference'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sReference);
		$this->assertStringStartsWith('['.$sReference.'] ', $this->lastCall()['message']);
		$this->assertSame([$sReference], ErrorReport::MintedReferences());
		ErrorReport::ForgetReferences();
	}

	public function testATraceWithArgumentsNeverReachesTheLog(): void
	{
		$sSecret = 'PHP_AUTH_PW-hunter2-'.str_repeat('x', 100000);
		$fThrow = static function (string $sPassword): void {
			throw new \RuntimeException('boom');
		};
		try {
			$fThrow($sSecret);
		} catch (\RuntimeException $e) {
			$this->logger()->error('Unhandled error during tool execution', ['exception' => $e]);
		}

		$sLogged = json_encode(TestIssueLog::$aRecorded, JSON_PARTIAL_OUTPUT_ON_ERROR);
		$this->assertStringNotContainsString('hunter2', (string) $sLogged);
		$this->assertLessThan(2000, strlen((string) $sLogged), 'a call must not write megabytes');
	}

	/**
	 * At debug the SDK logs the arguments of every tool call: the caller's own data.
	 */
	public function testToolArgumentsAreNeverLogged(): void
	{
		$this->logger()->debug('Executing tool', ['name' => 'core_object_create', 'arguments' => ['values' => ['private_log' => 'secret text']]]);
		$this->logger()->debug('Executing tool', ['name' => 'x', 'params' => 'secret text', 'request' => 'secret text', 'body' => 'secret text']);

		$this->assertSame('Executing tool', TestIssueLog::$aRecorded[0]['message']);
		$this->assertSame(['name' => 'core_object_create'], TestIssueLog::$aRecorded[0]['context']);
		$this->assertSame(['name' => 'x'], TestIssueLog::$aRecorded[1]['context']);
	}

	public function testOtherNonScalarValuesBecomeALabel(): void
	{
		$this->logger()->warning('w', ['rows' => [1, 2, 3], 'who' => new \stdClass()]);

		$this->assertSame(['rows' => '[array of 3]', 'who' => '[stdClass]'], $this->lastCall()['context']);
	}

	public function testALongValueAndALongMessageAreCutWithoutSplittingACharacter(): void
	{
		$this->logger()->error(str_repeat('é', 3000).' {name}', ['name' => str_repeat('é', 3000)]);

		$aCall = $this->lastCall();
		$this->assertLessThan(400, strlen($aCall['context']['name']));
		$this->assertTrue(mb_check_encoding($aCall['context']['name'], 'UTF-8'));
		$this->assertLessThan(1100, strlen($aCall['message']));
		$this->assertTrue(mb_check_encoding($aCall['message'], 'UTF-8'));
	}
}
