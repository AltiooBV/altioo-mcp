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
	 * Every bulk tool has a key of its own in the block.
	 *
	 * The other direction of the rule above, and the one that would have caught
	 * bulkCreate missing. Grading the raw UR_ACTION_* constants is not the same
	 * as answering the question a model asks, because a model calls tools, not
	 * rights - and a bulk tool is gated on two of them at once. Two of the three
	 * pairs can be guessed from their names. Bulk creation cannot: iTop has no
	 * UR_ACTION_BULK_CREATE, so it is gated on UR_ACTION_BULK_MODIFY, and a
	 * model reading 'create' and 'bulkModify' has no way to learn that those
	 * two together are the answer.
	 *
	 * So each verb a bulk tool declares has to come back as bulk<Verb>. A tool
	 * added later with a verb nobody graded fails here rather than in the field.
	 */
	public function testEveryBulkToolHasAKeyOfItsOwn(): void
	{
		$aVerbs = [];
		foreach ($this->sourceFiles() as $sPath) {
			preg_match_all(
				"/checkBulkAllowed\([^;]*?,\s*'(\w+)'\s*\)/",
				file_get_contents($sPath),
				$aMatches
			);
			foreach ($aMatches[1] as $sVerb) {
				$aVerbs[$sVerb] = $sVerb;
			}
		}
		$sRights = $this->body('rights');

		$this->assertNotEmpty($aVerbs, 'no checkBulkAllowed() call site found - the scan is looking in the wrong place');

		$aMissing = [];
		foreach ($aVerbs as $sVerb) {
			$sKey = 'bulk'.ucfirst($sVerb);
			if (!str_contains($sRights, "'{$sKey}'")) {
				$aMissing[] = $sKey.' (for the bulk '.$sVerb.' tool)';
			}
		}
		sort($aMissing);

		$this->assertSame(
			[],
			$aMissing,
			'DatamodelReader::rights() has no key for '.implode(', ', $aMissing)
			.'. A model cannot work the gate out from the raw rights: it calls the tool, not the action.'
		);
	}

	/**
	 * And bulkCreate is that conjunction, not a right read off iTop.
	 *
	 * Pinned because the temptation, the next time somebody tidies this, is to
	 * look for UR_ACTION_BULK_CREATE and wire it up. There is no such constant,
	 * and a key that silently became one right instead of two would report a
	 * permission the tool does not grant on.
	 */
	public function testBulkCreateIsTheConjunctionAndNotAnInventedRight(): void
	{
		$sRights = $this->body('rights');

		$this->assertMatchesRegularExpression(
			"/'bulkCreate'\s*=>\s*self::stricter\(/",
			$sRights,
			'bulkCreate is no longer derived from two grades'
		);
		$this->assertStringNotContainsString(
			'UR_ACTION_BULK_CREATE',
			$sRights,
			'UR_ACTION_BULK_CREATE does not exist in iTop - see ObjectBulkCreate'
		);
	}

	/**
	 * The conjunction itself, which needs no iTop: both halves have to allow
	 * the call, so the worse grade is the answer. 'depends' beating 'yes' is
	 * the case worth writing down - a pair is settled only when both halves
	 * are, and one half still asking for the object leaves the pair asking.
	 */
	public function testStricterTakesTheWorseOfTwoGrades(): void
	{
		$oStricter = new ReflectionMethod(DatamodelReader::class, 'stricter');

		$this->assertSame('yes', $oStricter->invoke(null, 'yes', 'yes'));

		$this->assertSame('depends', $oStricter->invoke(null, 'yes', 'depends'));
		$this->assertSame('depends', $oStricter->invoke(null, 'depends', 'yes'));
		$this->assertSame('depends', $oStricter->invoke(null, 'depends', 'depends'));

		foreach ([['no', 'yes'], ['yes', 'no'], ['no', 'depends'], ['depends', 'no'], ['no', 'no']] as $aPair) {
			$this->assertSame(
				'no',
				$oStricter->invoke(null, $aPair[0], $aPair[1]),
				"({$aPair[0]}, {$aPair[1]}) has a refusal in it and must grade 'no'"
			);
		}
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
	 * And the resource template says it too.
	 *
	 * Both surfaces run the same Describe(), so the payloads cannot drift - but
	 * the descriptions are written twice and can. A client reading the template
	 * gets the rights block either way; what it loses is any reason to look for
	 * it, which for a block whose whole purpose is to be consulted before the
	 * call is the whole of it.
	 */
	public function testTheResourceTemplateDescribesTheSamePayload(): void
	{
		$sDescription = (new \Altioo\iTop\Extension\MCP\Core\ResourceTemplates\ClassDetail())->getDescription();

		foreach (['rights', 'bulkCreate', 'depends'] as $sWord) {
			$this->assertStringContainsString(
				$sWord,
				$sDescription,
				"itop://core/class/{class} serves the rights block but never mentions '{$sWord}'"
			);
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
