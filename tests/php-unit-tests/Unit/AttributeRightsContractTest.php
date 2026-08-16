<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Attribute rights are read as three answers, never as two.
 *
 * UserRights::IsActionAllowedOnAttribute() returns UR_ALLOWED_NO (0),
 * UR_ALLOWED_YES (1) or UR_ALLOWED_DEPENDS (2). Used as a boolean, DEPENDS is
 * truthy - so an addon answering "ask me again, with the object" was read as
 * "yes", and every attribute it grades per object was served to everyone. The
 * shipped addon never answers DEPENDS for attributes, which is exactly why the
 * mistake is invisible on a stock install and stays invisible until the one
 * deployment that grades per object.
 *
 * A static check rather than a behavioural one: reproducing it needs a custom
 * rights addon and a live database, and what actually goes wrong is a call
 * being written `!IsActionAllowedOnAttribute(...)` by someone who reasonably
 * assumed it returned a bool. That is a shape, and a shape can be checked here
 * for the cost of reading the sources.
 */
class AttributeRightsContractTest extends TestCase
{
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
	public function testTheAnswerIsNeverUsedAsABoolean(string $sPath): void
	{
		$aOffenders = [];

		foreach (file($sPath, FILE_IGNORE_NEW_LINES) as $iIndex => $sLine) {
			if (!str_contains($sLine, 'IsActionAllowedOnAttribute')) {
				continue;
			}
			// A comment discussing the call is not a call.
			if (preg_match('/^\s*(\*|\/\/)/', $sLine)) {
				continue;
			}
			// A correct use either compares the answer against one of the
			// constants, or parks it in a variable that a later line does. Any
			// other use is reading three answers as two.
			$bCompared = str_contains($sLine, 'UR_ALLOWED_');
			$bAssigned = (bool)preg_match('/\$\w+\s*=\s*UserRights::IsActionAllowedOnAttribute/', $sLine);
			if (!$bCompared && !$bAssigned) {
				$aOffenders[] = $iIndex + 1;
			}
		}

		$this->assertSame(
			[],
			$aOffenders,
			self::relative($sPath).': IsActionAllowedOnAttribute() compared as a boolean on line(s) '
			.implode(', ', $aOffenders)
			.'. UR_ALLOWED_DEPENDS is 2 and therefore truthy: compare against UR_ALLOWED_YES with an '
			.'object in hand, or against UR_ALLOWED_NO without one.'
		);
	}

	/**
	 * The two readings are not interchangeable, and the constants are what say
	 * so. If iTop ever renumbered them, the rule above would still hold but its
	 * reasoning would not.
	 */
	public function testDependsIsTruthyWhichIsTheWholeProblem(): void
	{
		if (!defined('UR_ALLOWED_DEPENDS')) {
			// The unit suite runs without iTop; the constants come with it.
			$this->markTestSkipped('UR_ALLOWED_* are defined by iTop.');
		}

		$this->assertSame(0, UR_ALLOWED_NO);
		$this->assertSame(1, UR_ALLOWED_YES);
		$this->assertSame(2, UR_ALLOWED_DEPENDS);
		$this->assertTrue((bool)UR_ALLOWED_DEPENDS, 'DEPENDS read as a boolean is a yes');
	}
}
