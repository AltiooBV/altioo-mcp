<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * That a deletion is refused when its cascade reaches what the caller may not
 * touch, and that the refusal happens before anything is deleted.
 *
 * iTop does not check this. MakeDeletionPlan() walks the references with
 * rights off so that the plan is complete whoever asked for it, and the
 * console checks the delete right on the object the user clicked and on
 * nothing the cascade drags along. That is defensible for a person who saw the
 * impact analysis and confirmed it; it is not defensible for a language model
 * calling a tool, where cascade is the path by which "delete this ticket"
 * reaches classes an operator withheld on purpose, and where the audit row
 * afterwards says only that the ticket was deleted.
 *
 * So this endpoint is deliberately stricter than the console. That decision is
 * worth a test because it is invisible: removing the call brings the module
 * back in line with iTop, every existing test still passes, and the only thing
 * that changes is that a right stops being enforced.
 *
 * A real deletion cannot be driven from a unit test - it needs an iTop, a
 * datamodel and a database. What the tools can be held to here is the shape of
 * their own source, which is where the ordering lives.
 */
class DeletionRightsContractTest extends TestCase
{
	/**
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public function deletingMethodProvider(): array
	{
		return [
			'core_object_delete'      => [ObjectDelete::class, 'execute'],
			'core_object_bulk_delete' => [ObjectBulkDelete::class, 'deleteOne'],
		];
	}

	/**
	 * The body of one method, comments stripped - they discuss the very call
	 * being searched for and would satisfy the search on their own - and
	 * whitespace with them, so that a reindent does not fail a test about
	 * ordering.
	 *
	 * @param class-string $sClass
	 */
	private function body(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines  = file($oMethod->getFileName());
		$sBody   = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}

	/**
	 * @dataProvider deletingMethodProvider
	 *
	 * @param class-string $sClass
	 */
	public function testTheCascadeIsChecked(string $sClass, string $sMethod): void
	{
		$this->assertStringContainsString(
			'WritePlan::CheckDeletionRights',
			$this->body($sClass, $sMethod),
			'this tool deletes without checking the rights on what the cascade reaches, which iTop does not check either'
		);
	}

	/**
	 * @dataProvider deletingMethodProvider
	 *
	 * @param class-string $sClass
	 */
	public function testItIsCheckedBeforeAnythingIsDeleted(string $sClass, string $sMethod): void
	{
		$sCode = $this->body($sClass, $sMethod);

		$iCheck  = strpos($sCode, 'WritePlan::CheckDeletionRights');
		$iDelete = strpos($sCode, 'DBDelete');

		$this->assertIsInt($iCheck, 'the cascade check is gone');
		$this->assertIsInt($iDelete, 'this method no longer deletes, so the ordering below means nothing');
		$this->assertLessThan(
			$iDelete,
			$iCheck,
			'the cascade is checked after the objects are already gone, which is not a check'
		);
	}

	/**
	 * The dry run has to refuse what the real call would refuse, or it is not
	 * answering the question it exists to answer. The check sits on the shared
	 * path above the simulate branch rather than inside it.
	 *
	 * @dataProvider deletingMethodProvider
	 *
	 * @param class-string $sClass
	 */
	public function testTheDryRunIsHeldToTheSameRule(string $sClass, string $sMethod): void
	{
		$sCode = $this->body($sClass, $sMethod);

		$iCheck    = strpos($sCode, 'WritePlan::CheckDeletionRights');
		$iSimulate = strpos($sCode, 'if(!$simulate)');
		if ($iSimulate === false) {
			$iSimulate = strpos($sCode, 'if(!$bSimulate)');
		}

		$this->assertIsInt($iSimulate, 'the simulate branch was not found, so this assertion checks nothing');
		$this->assertLessThan(
			$iSimulate,
			$iCheck,
			'the cascade is only checked on the real call, so a dry run reports a deletion that would in fact be refused'
		);
	}

	/**
	 * One implementation, not one per tool. Both delete tools carried their own
	 * copy of the plan serialiser, which is how a rights rule ends up applied
	 * to one of them.
	 */
	public function testNeitherToolKeepsItsOwnCopyOfThePlanSerialiser(): void
	{
		foreach ([ObjectDelete::class, ObjectBulkDelete::class] as $sClass) {
			$aMethods = array_map(
				static fn (ReflectionMethod $oMethod): string => $oMethod->getName(),
				(new ReflectionClass($sClass))->getMethods()
			);

			$this->assertNotContains(
				'serializeDeletionPlan',
				$aMethods,
				$sClass.' serialises the plan itself instead of through WritePlan'
			);
		}
	}
}
