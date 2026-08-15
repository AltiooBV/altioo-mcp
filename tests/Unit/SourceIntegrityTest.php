<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static checks over the module's own PHP sources.
 *
 * These catch the class of defect that a unit test exercising behaviour cannot:
 * a class referenced without an import inside a namespaced file resolves to the
 * *current* namespace, so it only explodes when that particular line runs. Six
 * such references shipped undetected because they sat on error paths - or, in
 * three of the tools, on a path no test ever reached.
 *
 * Scope is the module's hand-written code only: vendor/ follows its own
 * conventions and is excluded (see the house guide, "Review scope").
 */
class SourceIntegrityTest extends TestCase
{
	private const SOURCE_DIR = __DIR__.'/../../src';

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
	 * Significant tokens (whitespace and comments dropped) for a file.
	 *
	 * @return array<int, array{0: int, 1: string, 2: int}|string>
	 */
	private function significantTokens(string $sPath): array
	{
		$aOut = [];
		foreach (token_get_all(file_get_contents($sPath)) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$aOut[] = $mToken;
		}

		return $aOut;
	}

	/**
	 * A bare `Foo::bar()` or `new Foo` inside `namespace A\B;` resolves to
	 * `A\B\Foo`, never to the global `\Foo`. Without a matching `use`, the call
	 * fatals the moment it executes.
	 */
	#[DataProvider('sourceFileProvider')]
	public function testEveryClassReferenceResolves(string $sPath): void
	{
		$aTokens = $this->significantTokens($sPath);
		$sNamespace = '';
		$aImports = [];
		$aReferences = [];
		$aDeclaredHere = [];

		for ($i = 0, $iCount = count($aTokens); $i < $iCount; $i++) {
			$mToken = $aTokens[$i];
			if (!is_array($mToken)) {
				continue;
			}
			[$iId, $sText] = $mToken;

			if ($iId === T_NAMESPACE && isset($aTokens[$i + 1]) && is_array($aTokens[$i + 1])) {
				$sNamespace = $aTokens[$i + 1][1];
			}

			if ($iId === T_CLASS || $iId === T_INTERFACE || $iId === T_TRAIT) {
				if (isset($aTokens[$i + 1]) && is_array($aTokens[$i + 1]) && $aTokens[$i + 1][0] === T_STRING) {
					$aDeclaredHere[$aTokens[$i + 1][1]] = true;
				}
			}

			if ($iId === T_USE && isset($aTokens[$i + 1]) && is_array($aTokens[$i + 1])) {
				$mNext = $aTokens[$i + 1];
				if (in_array($mNext[0], [T_NAME_QUALIFIED, T_STRING], true)) {
					$sFq = $mNext[1];
					$bAliased = isset($aTokens[$i + 2]) && is_array($aTokens[$i + 2])
						&& strtolower($aTokens[$i + 2][1]) === 'as';
					$sAlias = $bAliased
						? $aTokens[$i + 3][1]
						: substr(strrchr('\\'.$sFq, '\\'), 1);
					$aImports[$sAlias] = true;
				}
			}

			// Positions where a bare T_STRING denotes a class name.
			if ($iId === T_STRING && preg_match('/^[A-Z]/', $sText)) {
				$mPrev = $aTokens[$i - 1] ?? null;
				$mNext = $aTokens[$i + 1] ?? null;
				$bClassPosition = false;
				if (is_array($mPrev) && in_array($mPrev[0], [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)) {
					$bClassPosition = true;
				}
				if (is_array($mNext) && $mNext[0] === T_DOUBLE_COLON) {
					$bClassPosition = true;
				}
				if ($mPrev === '(' && isset($aTokens[$i - 2]) && is_array($aTokens[$i - 2]) && $aTokens[$i - 2][0] === T_CATCH) {
					$bClassPosition = true;
				}
				if ($bClassPosition) {
					$aReferences[$sText] = true;
				}
			}
		}

		if ($sNamespace === '') {
			$this->addToAssertionCount(1);

			return;
		}

		$aUnresolved = [];
		foreach (array_keys($aReferences) as $sName) {
			if (in_array($sName, ['self', 'static', 'parent'], true)) {
				continue;
			}
			if (isset($aImports[$sName]) || isset($aDeclaredHere[$sName])) {
				continue;
			}
			// Same namespace, different file: resolves fine at runtime.
			if (class_exists($sNamespace.'\\'.$sName) || interface_exists($sNamespace.'\\'.$sName)) {
				continue;
			}
			$aUnresolved[] = $sName;
		}

		$this->assertSame(
			[],
			$aUnresolved,
			sprintf(
				'%s: %s referenced without an import; inside "namespace %s;" these resolve to %s\\<name> and fatal at runtime.',
				self::relative($sPath),
				implode(', ', $aUnresolved),
				$sNamespace,
				$sNamespace
			)
		);
	}

	#[DataProvider('sourceFileProvider')]
	public function testNoClosingTag(string $sPath): void
	{
		$this->assertStringNotContainsString(
			'?>',
			file_get_contents($sPath),
			self::relative($sPath).': PHP files must not carry a closing tag (stray output after it breaks headers).'
		);
	}

	#[DataProvider('sourceFileProvider')]
	public function testNoTrailingWhitespace(string $sPath): void
	{
		$aOffenders = [];
		foreach (file($sPath, FILE_IGNORE_NEW_LINES) as $iIndex => $sLine) {
			if (rtrim($sLine) !== $sLine) {
				$aOffenders[] = $iIndex + 1;
			}
		}

		$this->assertSame([], $aOffenders, self::relative($sPath).': trailing whitespace on line(s) '.implode(', ', $aOffenders));
	}

	/**
	 * Combodo indents PHP with tabs. Continuation lines of a block comment
	 * legitimately start with a space before the '*'.
	 */
	#[DataProvider('sourceFileProvider')]
	public function testIndentsWithTabs(string $sPath): void
	{
		$aOffenders = [];
		foreach (file($sPath, FILE_IGNORE_NEW_LINES) as $iIndex => $sLine) {
			if (!str_starts_with($sLine, ' ')) {
				continue;
			}
			if (preg_match('/^ \*/', $sLine)) {
				continue;
			}
			$aOffenders[] = $iIndex + 1;
		}

		$this->assertSame([], $aOffenders, self::relative($sPath).': space indentation on line(s) '.implode(', ', $aOffenders));
	}

	#[DataProvider('sourceFileProvider')]
	public function testIsUtf8WithoutBomAndLfEndings(string $sPath): void
	{
		$sContent = file_get_contents($sPath);

		$this->assertStringStartsNotWith("\xEF\xBB\xBF", $sContent, self::relative($sPath).': UTF-8 BOM present.');
		$this->assertStringNotContainsString("\r", $sContent, self::relative($sPath).': CRLF line endings.');
		// preg with the /u modifier validates UTF-8 without requiring ext-mbstring.
		$this->assertSame(1, preg_match('//u', $sContent), self::relative($sPath).': not valid UTF-8.');
		$this->assertStringEndsWith("\n", $sContent, self::relative($sPath).': missing final newline.');
	}
}
