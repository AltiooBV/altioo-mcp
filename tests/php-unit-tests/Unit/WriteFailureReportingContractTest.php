<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
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

		$this->assertStringContainsString('CommittedId', $sHandler, 'The handler must ask whether the row exists.');
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
		$this->assertMatchesRegularExpression(
			'/\(int\)\s*\$mId\s*>\s*0\s*\?/',
			$this->methodBody(WritePlan::class, 'AsId'),
			'A temporary key is negative; treating it as an id reports failures as successes.'
		);
		$this->assertStringContainsString(
			'catch (Throwable',
			$this->methodBody(WritePlan::class, 'CommittedId'),
			'This runs on the failure path and must not replace the failure with one of its own.'
		);
	}

	/**
	 * The same question, asked by the bulk path.
	 *
	 * Observed on a live instance: two bulk-created lnkContactToTicket rows
	 * that duplicated one another came back "succeeded: 0, failed: 2", and one
	 * of the two was in the database. The second row's refusal was the
	 * uniqueness rule, which runs in DoCheckToWrite() before any insert and
	 * named itself properly; what threw *after* the first row committed is
	 * recorded only in that instance's log. DBInsert() commits and then
	 * reloads, so the second half of it has always been able to fail with the
	 * row written - which reason it was does not change what the report has to
	 * say. A caller reading "failed" for a row that exists either retries,
	 * writing a second one, or tells a user nothing was created.
	 *
	 * Pinned per tool rather than centrally because each write path decides for
	 * itself what "did this one write" means: a creation can answer from the
	 * key, and an update cannot, which is why only the two create paths carry
	 * this.
	 */
	public function testACommittedBulkRowIsReportedAsOneDespiteTheFailure(): void
	{
		$sBody = $this->methodBody(ObjectBulkCreate::class, 'createOne');
		$iCatch = strpos($sBody, 'catch (\Throwable');
		$this->assertNotFalse($iCatch, 'The write has to be wrapped to be reported.');
		$sHandler = substr($sBody, $iCatch);

		$this->assertStringContainsString('WritePlan::CommittedId', $sHandler, 'The handler must ask whether the row exists.');
		$this->assertMatchesRegularExpression(
			'/if\s*\(\$iCommittedId !== null\)\s*\{.*self::outcome\(\$iCommittedId, \$iRow, true/s',
			$sHandler,
			'A committed row is an entry that succeeded, carrying its id.'
		);
		$this->assertStringContainsString("'warning'", $sHandler, 'The failure is attached, not discarded.');
	}

	/**
	 * A write describes the object it left behind, not the one it was handed.
	 *
	 * `changes` is taken before the write and has to be - DBInsert() clears the
	 * pending values - so it reports what was asked for and what
	 * DoComputeValues() rewrote, and nothing the write itself did: an
	 * AfterInsert hook, an event listener, the ref a ticket is given, the
	 * lifecycle filling a field in. And obsolescence_flag is not a stored
	 * column at all but an expression the database evaluates when the row is
	 * queried, so nothing in memory carries it.
	 *
	 * The case that makes it matter: a status the datamodel counts as obsolete.
	 * The write succeeds, the object leaves every search that account makes,
	 * and the report said nothing - so the agent looks for what it wrote, finds
	 * nothing, and concludes the write failed.
	 *
	 * One read, on the real path only, for both halves.
	 */
	public function testEveryFieldChangingWriteReportsTheObjectItLeftBehind(): void
	{
		foreach (['ObjectCreate', 'ObjectUpdate', 'ObjectApplyStimulus'] as $sTool) {
			$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool;
			$sSource = (string) file_get_contents((new ReflectionClass($sClass))->getFileName());

			$this->assertStringContainsString('WritePlan::AfterSchemaProperty()', $sSource, "{$sTool} does not declare it");
			$this->assertStringContainsString('WritePlan::After(', $sSource, "{$sTool} does not report it");
		}

		$sAfter = $this->methodBody(WritePlan::class, 'After');

		$this->assertStringContainsString('MetaModel::GetObject', $sAfter, 'nothing is re-read, so a trigger stays invisible');
		$this->assertStringContainsString('!$bSimulated', $sAfter, 'a dry run reads a row it has not written');
		$this->assertStringContainsString('ObjectSerializer::Serialize', $sAfter, 'values bypass the read rights and the masking');
		$this->assertStringContainsString('hidden_from_searches', $sAfter, 'the one consequence a caller cannot see for itself');
		$this->assertStringContainsString('catch (Throwable', $sAfter, 'describing a write that succeeded must not fail it');
	}

	/**
	 * The TypeError that started this: DBInsert() returns the key as a string,
	 * and a ?int parameter under strict_types rejects it *after* the commit.
	 * Every create path hands this method an id straight out of the ORM.
	 */
	public function testTheIdentityHelperTakesTheIdAsITopReportsIt(): void
	{
		$oMethod = new ReflectionMethod(WritePlan::class, 'Identity');
		$aParams = $oMethod->getParameters();
		$sType = (string) $aParams[1]->getType();

		$this->assertStringContainsString('string', $sType, sprintf(
			'DBInsert() returns the key as a string, so a %s parameter throws a TypeError once the row is already written.',
			$sType
		));
	}

	/** Every id that came out of a write goes through the one normaliser. */
	public function testNoCreatePathCastsTheIdItself(): void
	{
		$aOffenders = [];

		foreach ($this->phpFiles() as $sPath) {
			$sSource = (string) file_get_contents($sPath);
			if (preg_match('/Identity\([^,]+,\s*\(int\)/', $sSource) === 1) {
				$aOffenders[] = substr($sPath, strlen(self::SRC) + 1);
			}
		}

		$this->assertSame([], $aOffenders, sprintf(
			'A cast at the call site is the fix the next create tool would be written without: %s',
			implode(', ', $aOffenders)
		));
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
