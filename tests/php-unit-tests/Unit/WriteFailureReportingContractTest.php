<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A write that reports failure must not have written.
 *
 * Observed: core_object_create created a UserRequest and answered "Error while
 * executing tool". The row was there; the caller was told it was not. Creating
 * is not idempotent and nothing in the protocol says a failed write may have
 * written, so the reasonable next move - retry - is a second object.
 *
 * Two causes, both pinned here. An Error is not an Exception, so every catch
 * written as \Exception let one past into the SDK's own handler, which logs
 * "Unhandled error during tool execution" and answers with a fixed string: no
 * class, no message, and no reference to find it by, which is what makes the
 * endpoint's own audit event useless for the failures that need it most. And
 * DBInsert() commits before it returns - it reloads external values afterwards
 * - so catching around it is not the same as knowing nothing was written.
 */
class WriteFailureReportingContractTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const SRC = __DIR__.'/../../../src';

	/**
	 * \Exception does not catch \Error, and the difference is only ever
	 * noticed in production.
	 */
	public function testNothingInTheSourceCatchesExceptionRatherThanThrowable(): void
	{
		$aOffenders = [];

		foreach ($this->phpFiles() as $sPath) {
			if (str_contains((string) file_get_contents($sPath), 'catch (\Exception')) {
				$aOffenders[] = substr($sPath, strlen(self::SRC) + 1);
			}
		}

		$this->assertSame([], $aOffenders, sprintf(
			'These catch \Exception, so an Error passes them and reaches the SDK as an unhandled '
			.'failure - a fixed string with no reference: %s',
			implode(', ', $aOffenders)
		));
	}

	/**
	 * The committed row is reported, not swallowed by the failure.
	 */
	public function testACommittedCreateIsReportedAsOneDespiteTheFailure(): void
	{
		$sBody = $this->methodBody(ObjectCreate::class, 'execute');
		$iCatch = strpos($sBody, 'catch (\Throwable');
		$this->assertNotFalse($iCatch, 'The write has to be wrapped to be reported.');
		$sHandler = substr($sBody, $iCatch);

		$this->assertStringContainsString('committedId', $sHandler, 'The handler must ask whether the row exists.');
		$this->assertStringContainsString('ToolOutput::Structured', $sHandler, 'A committed row is answered as a result.');
		$this->assertStringContainsString("'warning'", $sHandler, 'The failure is attached, not discarded.');
	}

	/**
	 * Only when the row really is not there.
	 */
	public function testAFailedCreateStillFails(): void
	{
		$sHandler = substr(
			$this->methodBody(ObjectCreate::class, 'execute'),
			(int) strpos($this->methodBody(ObjectCreate::class, 'execute'), 'catch (\Throwable')
		);

		$this->assertMatchesRegularExpression(
			'/if\s*\(\$iCommittedId === null\)\s*\{[^}]*throw new ToolCallException/s',
			$sHandler,
			'Nothing written is still an error; only a committed row changes the answer.'
		);
	}

	/**
	 * iTop gives an unsaved object a negative temporary key on purpose. Reading
	 * that as an id would report every failed create as a success.
	 */
	public function testATemporaryKeyIsNotMistakenForACommittedOne(): void
	{
		$sBody = $this->methodBody(ObjectCreate::class, 'committedId');

		$this->assertMatchesRegularExpression(
			'/\(int\)\s*\$mKey\s*<=\s*0/',
			$sBody,
			'A temporary key is negative; treating it as an id reports failures as successes.'
		);
		$this->assertStringContainsString(
			'catch (\Throwable',
			$sBody,
			'This runs on the failure path and must not replace the failure with one of its own.'
		);
	}

	/** @return array<int, string> */
	private function phpFiles(): array
	{
		$aFiles = [];
		$oIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC));

		foreach ($oIterator as $oFile) {
			if ($oFile->isFile() && $oFile->getExtension() === 'php') {
				$aFiles[] = $oFile->getPathname();
			}
		}

		sort($aFiles);

		return $aFiles;
	}

	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());

		return implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));
	}
}
