<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory as HistoryTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The three gates that make the change log safe to serve.
 *
 * CMDBChangeOp is granted per profile, as a class, and the grant says nothing
 * about the object a row points at nor about the attribute it names: objkey is
 * an integer column. So a caller holding it could otherwise read the history
 * of objects its silo hides, and the former values of attributes it may not
 * read today. Each gate below is the difference between this tool and an OQL
 * query a model could write for itself, which is the reason the tool exists.
 *
 * A source scan for the same reason as CurrentUserContactTest: UserRights and
 * MetaModel do not exist without iTop, and a unit suite that boots neither can
 * still hold the invariant in place.
 */
class ObjectHistoryContractTest extends TestCase
{
	/**
	 * A work note's history row says what was written.
	 *
	 * CMDBChangeOpSetAttributeCaseLog declares lastentry - an integer - and no
	 * oldvalue or newvalue, so a row about a case log said who wrote one and
	 * when, and nothing about what it said. That is not the wrong column being
	 * read: iTop never writes the text there, which is why the console renders
	 * those entries from the object instead.
	 *
	 * So the text is read from the object, after MayReadAttribute() has
	 * allowed the attribute the entry belongs to, and matched on date and user
	 * rather than on lastentry - an index that later entries push along, and
	 * that an edited log leaves pointing at somebody else's words. A wrong
	 * attribution on the tab an auditor reads is far worse than a missing one,
	 * so no match answers null.
	 */
	public function testACaseLogRowCarriesTheEntryThatWasWritten(): void
	{
		$sBody = (string) file_get_contents(
			(new \ReflectionClass(\Altioo\iTop\Extension\MCP\Helper\ObjectHistory::class))->getFileName()
		);

		$this->assertStringContainsString('CMDBChangeOpSetAttributeCaseLog', $sBody, 'the one operation with no value of its own is not recognised');
		$this->assertStringContainsString('GetAsArray', $sBody, 'the text is not read from the object that holds it');
		$this->assertStringNotContainsString("Get('lastentry')", $sBody, 'the entry is matched on an index that moves');
		$this->assertMatchesRegularExpression(
			'/MayReadAttribute.*withCaseLogEntry/s',
			$sBody,
			'the text is read before the attribute rights have allowed it'
		);
		$this->assertStringContainsString('catch (\\Throwable', $sBody, 'one unreadable entry costs the whole history');
	}

	/**
	 * The object is the gate, and there is no second one.
	 *
	 * ActivityPanelHelper reads these same rows for whatever object is on
	 * screen and asks UserRights nothing about CMDBChangeOp: in the console,
	 * seeing the ticket is seeing its history. A class grant required here
	 * would make this stricter than the UI it mirrors, on an instance where
	 * nobody had granted something iTop never asks for - which is what it did,
	 * until a get on a real ticket came back with the object and no audit
	 * block.
	 */
	public function testTheChangeLogNeedsNoGrantOfItsOwn(): void
	{
		foreach ([[HistoryTool::class, 'execute'], [ObjectHistory::class, 'AttributionFor']] as [$sClass, $sMethod]) {
			$this->assertStringNotContainsString(
				"IsActionAllowed('CMDBChangeOp'",
				$this->bodyOf($sClass, $sMethod),
				"{$sClass}::{$sMethod}() asks for a right the console does not"
			);
			$this->assertStringNotContainsString(
				'IsReadable',
				$this->bodyOf($sClass, $sMethod),
				"{$sClass}::{$sMethod}() still gates on a class grant"
			);
		}
	}

	/**
	 * Newest first means by id, not by date.
	 *
	 * One change writes several CMDBChangeOp rows carrying that change's
	 * timestamp, so a date sort leaves their order to the database. iTop says
	 * so where it runs the same query, and orders by the id instead.
	 */
	public function testTheOrderIsByIdBecauseTimestampsRepeat(): void
	{
		$sSource = file_get_contents((new ReflectionClass(ObjectHistory::class))->getFileName());

		$this->assertStringContainsString("['id' => false]", $sSource, 'newest-first is not ordered by id');
		$this->assertStringNotContainsString("['date' =>", $sSource, 'rows are still ordered by a timestamp several of them share');
	}

	/**
	 * The object gate, applied with the object set rather than the class alone
	 * - a silo answers per object, and this is where that answer is asked for.
	 */
	public function testTheObjectMustBeOneTheCallerCouldHaveRead(): void
	{
		$sBody = $this->bodyOf(HistoryTool::class, 'execute');

		$this->assertStringContainsString('UR_ACTION_READ, $oSet', $sBody);
		$this->assertStringContainsString('not found.', $sBody, 'a refused object must not be distinguishable from an absent one');
	}

	/**
	 * Today's rights, not the ones in force when the row was written. A
	 * revoked permission that stayed readable through the log would be a
	 * revocation in name only.
	 */
	public function testEveryRowIsFilteredByTodaysAttributeRights(): void
	{
		$this->assertStringContainsString(
			'MayReadAttribute',
			$this->bodyOf(ObjectHistory::class, 'For'),
			'rows naming an attribute are served without asking whether this caller may read it'
		);
	}

	/**
	 * iTop files a row under get_class($this), so a leaf object's history is
	 * recorded under the leaf. Querying by the name the caller happened to ask
	 * about would match nothing and report "no history" for an object with
	 * plenty.
	 */
	public function testTheLogIsQueriedUnderTheObjectsOwnClass(): void
	{
		$this->assertStringContainsString(
			'get_class($oObject)',
			$this->bodyOf(ObjectHistory::class, 'For')
		);
	}

	public function testItIsItsOwnToolsetSoAnOperatorCanWithholdIt(): void
	{
		$this->assertSame('history', (new HistoryTool())->getToolset());
	}

	/**
	 * A ceiling, and a low default. priv_change is the one table an old
	 * instance holds tens of millions of rows in.
	 */
	public function testThePagingIsBoundedOnBothSides(): void
	{
		$aSchema = (new HistoryTool())->getInputSchema();

		$this->assertSame(ObjectHistory::MAX_LIMIT, $aSchema['properties']['limit']['maximum']);
		$this->assertSame(ObjectHistory::DEFAULT_LIMIT, $aSchema['properties']['limit']['default']);
		$this->assertLessThan(ObjectHistory::MAX_LIMIT, ObjectHistory::DEFAULT_LIMIT);
		$this->assertSame(['class', 'id'], $aSchema['required']);
	}

	/**
	 * Cheap enough to be unconditional for one object; the search tools ask,
	 * because there the number of these reads is the page size.
	 */
	public function testASingleObjectReadIsAttributedWithoutBeingAsked(): void
	{
		$this->assertStringContainsString('AttributionFor', $this->bodyOf(ObjectGet::class, 'execute'));

		foreach ([ObjectSearchByOQL::class, ObjectSearchByClass::class] as $sTool) {
			$aSchema = (new $sTool())->getInputSchema();
			$this->assertArrayHasKey('audit', $aSchema['properties'], $sTool.' cannot be asked for attribution');
			$this->assertFalse($aSchema['properties']['audit']['default'], $sTool.' attributes every page by default');
		}
	}

	/**
	 * A page too large to attribute is refused rather than served slowly, and
	 * the ceiling has to be below the paging one or it never bites.
	 */
	public function testAnUnattributablePageIsRefused(): void
	{
		$this->assertLessThan(ObjectSearchByOQL::MAX_LIMIT, ObjectHistory::MAX_AUDIT_PAGE);

		foreach ([ObjectSearchByOQL::class, ObjectSearchByClass::class] as $sTool) {
			$this->assertStringContainsString(
				'refuseUnattributablePage',
				$this->bodyOf($sTool, 'execute'),
				$sTool.' serves an unbounded number of attribution reads'
			);
		}
	}

	/**
	 * The change log is not an object the object tools serve.
	 *
	 * CMDBChange and CMDBChangeOp are ordinary DBObjects, so every generic
	 * tool would otherwise treat them as ordinary objects - and reach the rows
	 * without the object gate and the attribute gate this surface applies one
	 * layer above them. The console draws the same line: history is a tab on
	 * an object, never a class you search or a form you fill.
	 *
	 * Stated over the toolsets rather than over a list of tools, so that a
	 * tool added to any of them later is covered by this test on the day it is
	 * registered rather than on the day someone remembers.
	 *
	 * @dataProvider objectFacingToolProvider
	 */
	public function testNoObjectToolWillTouchTheChangeLog(string $sName, string $sClass): void
	{
		$this->assertStringContainsString(
			'IsReserved',
			$this->sourceWithParents($sClass),
			"{$sName} accepts a class without asking whether it is the change log"
		);
	}

	/**
	 * Every registered tool whose toolset is about objects, their documents or
	 * their relations. The datamodel and server toolsets describe rather than
	 * fetch, and the history toolset is the surface itself.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function objectFacingToolProvider(): array
	{
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();

		$aCases = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			if (in_array($oTool->getToolset(), ['objects', 'documents', 'relations'], true)) {
				$aCases[$sName] = [$sName, get_class($oTool)];
			}
		}
		MCPRegistry::Clear();

		self::assertNotEmpty($aCases, 'no object-facing tool was found; the scan has stopped working');

		return $aCases;
	}

	/** Reserved roots cover their subclasses, which is what a list would not. */
	public function testTheReservationCoversSubclasses(): void
	{
		$this->assertTrue(ObjectHistory::IsReserved('CMDBChangeOp'));
		$this->assertTrue(ObjectHistory::IsReserved('CMDBChange'));
		$this->assertFalse(ObjectHistory::IsReserved('UserRequest'));
		// Case is not what decides it: OQL is parsed before this is asked.
		$this->assertTrue(ObjectHistory::IsReserved('cmdbchangeop'));
	}

	/** The source of a class and of every ancestor it inherits a guard from. */
	private function sourceWithParents(string $sClass): string
	{
		$sSource = '';
		for ($oClass = new ReflectionClass($sClass); $oClass !== false; $oClass = $oClass->getParentClass()) {
			$sFile = $oClass->getFileName();
			if ($sFile !== false) {
				$sSource .= file_get_contents($sFile);
			}
		}

		return $sSource;
	}

	/**
	 * The body of one method, comments stripped - otherwise a call named only
	 * in a doc comment would satisfy the search.
	 */
	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
