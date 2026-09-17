<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The schema reports every right the tools refuse on, and reports it as three
 * answers.
 *
 * The class schema now carries what the caller may do, so that a model picks a
 * call that can succeed instead of learning the answer from the refusal. That
 * is only true while the block stays level with the gates: an action some tool
 * checks and the schema does not grade is a refusal the model still has no way
 * to see coming, and the day it appears is the day someone adds a gate, not the
 * day anyone reads this file.
 *
 * Both halves are source scans. rights() needs MetaModel and the UR_ACTION_*
 * constants, neither of which exists in a suite that boots no iTop - but what
 * actually goes wrong here is a set of constants drifting apart from another
 * set of constants, and that is a shape, readable for the cost of reading the
 * sources.
 *
 * @see AttributeRightsContractTest for the neighbouring rule - that none of
 *      these answers is ever read as a boolean - which covers the call this
 *      change added to DatamodelReader::attributes() by scanning src/ whole.
 */
class DatamodelRightsContractTest extends TestCase
{
	private const SOURCE_DIR = __DIR__.'/../../../src';

	private const READER = self::SOURCE_DIR.'/Helper/DatamodelReader.php';

	/**
	 * Every action the module gates on is one the schema grades.
	 *
	 * One direction only. Grading an action nothing checks costs a key in a
	 * payload; checking an action nothing grades costs the model the round trip
	 * this whole block exists to save.
	 */
	public function testEveryGatedActionIsGraded(): void
	{
		$aGated = [];
		foreach ($this->sourceFiles() as $sPath) {
			if (realpath($sPath) === realpath(self::READER)) {
				continue; // the answer itself, not a question asked of it
			}
			foreach ($this->actionsIn(file_get_contents($sPath)) as $sAction) {
				$aGated[$sAction] = $sAction;
			}
		}
		$aGraded = $this->actionsIn($this->body('rights'));

		$this->assertNotEmpty($aGated, 'no UR_ACTION_* gate found in src/ - the scan is looking in the wrong place');

		$aUngraded = array_values(array_diff($aGated, $aGraded));
		sort($aUngraded);
		$this->assertSame(
			[],
			$aUngraded,
			'DatamodelReader::rights() does not grade '.implode(', ', $aUngraded)
			.', which the tools refuse on. A model reading the schema cannot see that refusal coming.'
		);
	}

	/**
	 * UR_ALLOWED_DEPENDS keeps a word of its own.
	 *
	 * Folded into either neighbour it becomes an answer nobody gave: the addon
	 * said "ask again, with the object", and the model is told yes or no. This
	 * is the same failure AttributeRightsContractTest guards inside the tools,
	 * one surface further out - there it is a right silently granted, here a
	 * right silently reported.
	 */
	public function testTheThirdAnswerSurvivesTheGrading(): void
	{
		$sBody = $this->body('grade');

		$this->assertStringContainsString('UR_ALLOWED_NO', $sBody, 'grade() no longer names the refusal');
		$this->assertStringContainsString('UR_ALLOWED_YES', $sBody, 'grade() no longer names the grant');

		preg_match_all("/'([a-z]+)'/", $sBody, $aMatches);
		$aWords = array_unique($aMatches[1]);
		sort($aWords);

		$this->assertSame(
			['depends', 'no', 'yes'],
			$aWords,
			'grade() answers with '.count($aWords).' word(s). UR_ALLOWED_* is three answers and the schema has to carry all three.'
		);
	}

	/**
	 * The words themselves, against the constants that produce them.
	 *
	 * Skipped without iTop, like its neighbour in AttributeRightsContractTest:
	 * the constants come with the product. It is the half that would catch a
	 * grading built on the wrong numbers, which no source scan can see.
	 */
	public function testTheConstantsMapToTheWordsTheDescriptionPromises(): void
	{
		if (!defined('UR_ALLOWED_DEPENDS')) {
			$this->markTestSkipped('UR_ALLOWED_* are defined by iTop.');
		}

		$oGrade = new ReflectionMethod(DatamodelReader::class, 'grade');

		$this->assertSame('no', $oGrade->invoke(null, UR_ALLOWED_NO));
		$this->assertSame('yes', $oGrade->invoke(null, UR_ALLOWED_YES));
		$this->assertSame('depends', $oGrade->invoke(null, UR_ALLOWED_DEPENDS));
	}

	/**
	 * A bool where an int was expected is a TypeError under strict_types, and
	 * UserRights does not declare what it returns. Both readings have to land
	 * on the same grade, which is what the missing type hint buys.
	 */
	public function testABooleanAnswerIsGradedRatherThanFatal(): void
	{
		if (!defined('UR_ALLOWED_DEPENDS')) {
			$this->markTestSkipped('UR_ALLOWED_* are defined by iTop.');
		}

		$oGrade = new ReflectionMethod(DatamodelReader::class, 'grade');

		$this->assertSame('yes', $oGrade->invoke(null, true));
		$this->assertSame('no', $oGrade->invoke(null, false));
	}

	/**
	 * What the model is told the block means. 'no' is the half it can act on
	 * without qualification, because the tools raise on it before they fetch
	 * anything; saying so is what stops a model treating the whole block as a
	 * hint and calling anyway.
	 */
	public function testTheToolDescriptionExplainsTheThreeAnswers(): void
	{
		$sDescription = (new \Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema())->getDescription();

		foreach (['rights', 'yes', 'no', 'depends'] as $sWord) {
			$this->assertStringContainsString($sWord, $sDescription, "the schema tool never mentions '{$sWord}'");
		}
	}

	/**
	 * The attribute-level answer is its own key. readOnly is the datamodel
	 * refusing everybody; modify is this caller being refused, which somebody
	 * can grant. Collapsed into one, the model raises the wrong one with the
	 * user.
	 */
	public function testTheAttributeKeepsBothAnswers(): void
	{
		$sBody = $this->body('attributes');

		$this->assertStringContainsString("'readOnly'", $sBody, 'the datamodel answer is gone');
		$this->assertStringContainsString("'modify'", $sBody, 'the rights answer is gone');
	}

	/** @return array<int, string> */
	private function actionsIn(string $sSource): array
	{
		$aActions = [];
		foreach (explode("\n", $sSource) as $sLine) {
			// A comment naming a constant is not a gate: ObjectBulkCreate says
			// in as many words that UR_ACTION_BULK_CREATE does not exist.
			if (preg_match('/^\s*(\*|\/\/)/', $sLine)) {
				continue;
			}
			if (preg_match_all('/UR_ACTION_[A-Z_]+[A-Z]/', $sLine, $aMatches)) {
				foreach ($aMatches[0] as $sAction) {
					$aActions[$sAction] = $sAction;
				}
			}
		}

		return array_values($aActions);
	}

	/** The body of one DatamodelReader method, as written. */
	private function body(string $sMethod): string
	{
		$aLines = file(self::READER, FILE_IGNORE_NEW_LINES);
		$iStart = null;
		foreach ($aLines as $iIndex => $sLine) {
			if (preg_match('/function '.preg_quote($sMethod, '/').'\s*\(/', $sLine)) {
				$iStart = $iIndex;
				break;
			}
		}
		$this->assertNotNull($iStart, "DatamodelReader::{$sMethod}() is gone");

		$aBody = [];
		for ($i = $iStart; $i < count($aLines); $i++) {
			$aBody[] = $aLines[$i];
			if ($i > $iStart && $aLines[$i] === "\t}") {
				break;
			}
		}

		return implode("\n", $aBody);
	}

	/** @return array<int, string> */
	private function sourceFiles(): array
	{
		$aPaths = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE_DIR));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() === 'php') {
				$aPaths[] = $oFile->getPathname();
			}
		}
		sort($aPaths);

		return $aPaths;
	}
}
