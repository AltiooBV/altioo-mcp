<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Test\Support\OQLConcatenationScanner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * No generic scanner in this project's CI knows what OQL is (see the
 * progpilot job in ci.yml, and doc/security-summary.md): string-built OQL is
 * this module's own signature risk, so this is the one repository-specific
 * check worth encoding, as a blocking test rather than a new tool or job.
 *
 * Scoped to `.`-concatenation, deliberately not interpolation. Interpolating
 * a class name into `FromOQL()`'s `FROM` clause is unavoidable - OQL takes no
 * bind parameter there - and every current call site does exactly that
 * (src/Helper/ObjectQuery.php, src/Core/Tools/ObjectSearchByClass.php). A
 * rule that also matched interpolation would fail all of them; measured
 * against this codebase, that shape produced five false positives where none
 * were real. testScannerIsConcatenationOnly below pins that distinction so it
 * cannot regress silently.
 */
class OQLConcatenationTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const SOURCE_DIR = __DIR__.'/../../../src';

	/** @return array<string, array{0: string}> */
	public static function sourceFileProvider(): array
	{
		$aCases = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE_DIR));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sPath = $oFile->getPathname();
			$aCases[self::relative($sPath)] = [$sPath];
		}
		ksort($aCases);

		return $aCases;
	}

	private static function relative(string $sPath): string
	{
		$sReal = realpath($sPath) ?: $sPath;
		$sRoot = realpath(self::SOURCE_DIR.'/..') ?: '';

		return ltrim(str_replace($sRoot, '', $sReal), '/');
	}

	/**
	 * @dataProvider sourceFileProvider
	 */
	public function testNoConcatenationIntoFromOQL(string $sPath): void
	{
		$aViolations = OQLConcatenationScanner::findViolations(file_get_contents($sPath));

		$aLines = array_column($aViolations, 1);
		$this->assertSame(
			[],
			$aLines,
			sprintf(
				'%s: FromOQL() called with the query text built by concatenation on line(s) %s. '.
				'Bind the value instead (see src/Helper/ObjectQuery.php); a class or key name that '.
				'must sit in the FROM/WHERE clause itself belongs interpolated and validated '.
				'(MetaModel::IsValidClass() / MetaModel::DBGetKey()), never concatenated in.',
				self::relative($sPath),
				implode(', ', $aLines)
			)
		);
	}

	/**
	 * The fixture that keeps testNoConcatenationIntoFromOQL from being a
	 * tautology: proof the scanner actually fires, on both spellings, and
	 * proof it does not fire on the interpolation every real call site uses.
	 */
	public function testScannerIsConcatenationOnly(): void
	{
		$sConcatenatedDBObjectSearch = <<<'PHP'
<?php
$oSearch = DBObjectSearch::FromOQL("SELECT Person WHERE id = " . $sId);
PHP;
		$sConcatenatedDBSearch = <<<'PHP'
<?php
$oSearch = DBSearch::FromOQL("SELECT Person WHERE id = " . $sId, $aBindings);
PHP;
		$sInterpolatedClassAndKey = <<<'PHP'
<?php
$oSearch = DBObjectSearch::FromOQL("SELECT {$sClass} WHERE {$sKey} = :id", [':id' => $iId]);
PHP;
		$sBoundValueOnly = <<<'PHP'
<?php
$oSearch = DBObjectSearch::FromOQL("SELECT Person WHERE id = :id", [':id' => $iId]);
PHP;
		$sUnrelatedConcatenation = <<<'PHP'
<?php
$sGreeting = "hello " . $sName;
$oSearch = DBObjectSearch::FromOQL("SELECT {$sClass}");
PHP;
		$sConcatenatedBindParameterName = <<<'PHP'
<?php
$oSearch = DBObjectSearch::FromOQL(
    "SELECT {$sClass} WHERE {$sKey} = :".self::ID_PARAMETER,
    [self::ID_PARAMETER => $iId]
);
PHP;

		$this->assertNotEmpty(
			OQLConcatenationScanner::findViolations($sConcatenatedDBObjectSearch),
			'DBObjectSearch::FromOQL() with a concatenated query string must be caught.'
		);
		$this->assertNotEmpty(
			OQLConcatenationScanner::findViolations($sConcatenatedDBSearch),
			'DBSearch::FromOQL() with a concatenated query string must be caught.'
		);
		$this->assertSame(
			[],
			OQLConcatenationScanner::findViolations($sInterpolatedClassAndKey),
			'Interpolating a validated class/key name is the safe, current pattern and must not be flagged.'
		);
		$this->assertSame(
			[],
			OQLConcatenationScanner::findViolations($sBoundValueOnly),
			'A "." inside the bind-values argument is not query text and must not be flagged.'
		);
		$this->assertSame(
			[],
			OQLConcatenationScanner::findViolations($sUnrelatedConcatenation),
			'Concatenation elsewhere in the file, unrelated to FromOQL(), must not be flagged.'
		);
		$this->assertSame(
			[],
			OQLConcatenationScanner::findViolations($sConcatenatedBindParameterName),
			'Concatenating a class constant (a fixed bind-parameter name, as in ObjectQuery::ById()) is not a variable landing in the query text and must not be flagged.'
		);
	}
}
