<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Abstract\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The paging contract the two search tools have to keep identical.
 *
 * Offset paging is only paging if the order is total. Both tools order by the
 * requested attribute and then by id, and both have to offer the same four
 * arguments under the same names - a model that learns "order_by" on one tool
 * will send it to the other.
 *
 * Reading schemas and signatures needs no iTop, which is what makes this a
 * unit test rather than an integration one.
 */
class SearchPagingContractTest extends TestCase
{
	/**
	 * An ORDER BY in the query is answered with the parameter that replaces it.
	 *
	 * OQL has no ORDER BY and every other query language a model knows does,
	 * so it is the mistake that actually arrives. What iTop's parser says back
	 * is that something is unexpected at position n, which leaves a caller
	 * rewriting the same query - the two parameters that do the job are on the
	 * same tool, and the server instructions mention them only to a caller
	 * that read them.
	 *
	 * The direction values are read from the constants the enum is built from,
	 * so the advice cannot name a string order_direction would refuse.
	 */
	public function testAnOrderByInTheQueryIsToldWhereToPutIt(): void
	{
		$oHint = new \ReflectionMethod(ObjectSearchByOQL::class, 'orderByHint');

		$sHint = (string) $oHint->invoke(null, 'SELECT UserRequest ORDER BY start_date DESC');
		$this->assertStringContainsString('order_by', $sHint);
		$this->assertStringContainsString('"'.AbstractObjectSearch::SORT_DESC.'"', $sHint);

		$this->assertSame(
			'',
			(string) $oHint->invoke(null, 'SELECT UserRequest WHERE status = "open"'),
			'a query with no ORDER BY gets no advice about one'
		);
	}

	/**
	 * A filter value the datamodel cannot hold is refused, not answered.
	 *
	 * An unknown attribute code was always refused by name; an unknown value
	 * for a known code was not, so filters {"status": "bogus_status"} came back
	 * total: 0 - indistinguishable from a state nobody is in, and read by an
	 * agent as "no ticket is in that state". That is a wrong answer rather than
	 * a missing one, which is the only kind worth a refusal.
	 *
	 * Read off the source, because the check needs a datamodel to run: what is
	 * asserted here is that the call exists on the filter loop, that it names
	 * the valid codes, and that it skips the two cases where asking would cost
	 * more than it is worth.
	 */
	public function testAnInvalidFilterValueIsRefusedWithTheValidOnes(): void
	{
		$sFile = (string) file_get_contents(
			(new \ReflectionClass(ObjectSearchByClass::class))->getFileName()
		);

		$this->assertStringContainsString('refusalForValue', $sFile, 'the filter loop never checks the value');
		$this->assertStringContainsString('array_keys($aValues)', $sFile, 'the refusal does not name the valid codes');
		$this->assertStringContainsString('IsExternalKey', $sFile, 'an external key would enumerate its whole target table');
		$this->assertStringContainsString(
			'MAX_ALLOWED_VALUES',
			$sFile,
			'an unbounded enumeration is not one a refusal can list'
		);
	}

	/**
	 * Archived objects are a per-call choice, not a property of the URL.
	 *
	 * iTop takes archive mode from `with_archive` on the request and DBSearch
	 * reads it at construction, so "exclude" would otherwise mean "exclude,
	 * unless the client's URL says otherwise" - a default nobody can see, and
	 * one that makes two identical calls answer differently depending on how
	 * the session was connected. Set on every path, so the parameter is the
	 * authority.
	 *
	 * @dataProvider searchToolProvider
	 */
	public function testArchivedIsAPerCallChoiceAndDefaultsToExcluding(AbstractObjectSearch $oTool): void
	{
		$aProperty = $oTool->getInputSchema()['properties']['archived'];

		$this->assertSame(
			[AbstractObjectSearch::ARCHIVED_EXCLUDE, AbstractObjectSearch::ARCHIVED_INCLUDE, AbstractObjectSearch::ARCHIVED_ONLY],
			$aProperty['enum']
		);
		$this->assertSame(AbstractObjectSearch::ARCHIVED_EXCLUDE, $aProperty['default'], 'a search that shows soft-deleted objects by default is a search that lies');

		$aParameters = array_map(
			static fn (\ReflectionParameter $oParameter): string => $oParameter->getName(),
			(new ReflectionMethod($oTool, 'execute'))->getParameters()
		);
		$this->assertContains('archived', $aParameters, 'the schema offers what the signature cannot take');
	}

	/**
	 * "only" on a class that has no archived state is refused.
	 *
	 * It would otherwise answer with an empty set, and "no archived ones" read
	 * as "this class cannot have any" is the silent wrong answer this surface
	 * keeps having to close - archivability is declared per hierarchy, so most
	 * classes on a stock instance have no such state at all.
	 */
	public function testAskingForOnlyArchivedOnAClassWithNoneIsRefused(): void
	{
		$sBody = (string) file_get_contents(
			(new \ReflectionClass(AbstractObjectSearch::class))->getFileName()
		);

		$this->assertStringContainsString('has no archived state', $sBody, 'the impossible ask is answered rather than refused');
		$this->assertStringContainsString('SetArchiveMode', $sBody, 'the choice never reaches the search');
		$this->assertStringContainsString(
			"AddCondition(self::ARCHIVE_FLAG, 1, '=')",
			$sBody,
			'"only" widens the search without narrowing it back to the archived ones'
		);
	}

	/**
	 * The searches ask the user about obsolete objects, as the console does.
	 *
	 * "Show obsolete data" is a preference on the account, not a session flag:
	 * utils::ShowObsoleteData() reads appUserPreferences and falls back to
	 * obsolescence.show_obsolete_data. A search honours it only if the surface
	 * asks - DBSearch starts with m_bShowObsoleteData true, which is why
	 * iTop's own export service and portal call UpdateContextFromUser().
	 *
	 * These two did not ask, so one user got obsolete objects from the OQL
	 * search, none from core_object_find_by_name, which has always asked, and
	 * none in the console. Three answers to one question.
	 *
	 * Held on the shared helper, since both tools reach the search through it.
	 */
	public function testTheSearchesHonourTheUsersObsoleteDataPreference(): void
	{
		$sBody = (string) file_get_contents(
			(new \ReflectionClass(AbstractObjectSearch::class))->getFileName()
		);

		$this->assertStringContainsString(
			'SetShowObsoleteData(utils::ShowObsoleteData())',
			$sBody,
			'the set searches decide for themselves what the user already decided'
		);

		$sFindByName = (string) file_get_contents(
			(new \ReflectionClass(\Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName::class))->getFileName()
		);
		$this->assertStringContainsString(
			'SetShowObsoleteData(utils::ShowObsoleteData())',
			$sFindByName,
			'the three set-returning tools no longer answer the same question three ways'
		);
	}

	/**
	 * An unknown class is answered with a suggestion, not with the datamodel.
	 *
	 * CheckOQL() parses, checks, and then throws the exception away, keeping
	 * getMessage() - which for an unknown class ends in every class the
	 * instance has. A single typo answered with an alphabetical list of a few
	 * hundred names is an expensive way to say no, and it says nothing about
	 * which one was meant.
	 *
	 * iTop already knows: UnknownClassOqlException::GetUserFriendlyDescription()
	 * runs FindClosestString() over candidates its constructor has already
	 * filtered by read rights, so the suggestion cannot name a class the caller
	 * may not see. Keeping the exception rather than its message is the whole
	 * fix, which is why this asserts the parse is done here rather than through
	 * CheckOQL().
	 */
	public function testAnUnknownClassIsAnsweredWithASuggestion(): void
	{
		$sBody = (string) file_get_contents(
			(new \ReflectionClass(ObjectSearchByOQL::class))->getFileName()
		);

		$this->assertStringContainsString('GetUserFriendlyDescription', $sBody, 'the suggestion iTop already computes is discarded');
		$this->assertStringContainsString('new \\OqlInterpreter', $sBody, 'the exception is still being thrown away by CheckOQL()');
		$this->assertStringNotContainsString('CheckOQL($oql', $sBody, 'the message with the full class list is back');
		$this->assertStringContainsString('catch (\\OQLException', $sBody, 'a parse failure is no longer told apart from a broken datamodel');
	}

	/** @return array<string, array{0: AbstractObjectSearch}> */
	public static function searchToolProvider(): array
	{
		return [
			'by OQL'   => [new ObjectSearchByOQL()],
			'by class' => [new ObjectSearchByClass()],
		];
	}

	/**
	 * @dataProvider searchToolProvider
	 */
	public function testBothToolsOfferTheSamePagingAndOrderingArguments(AbstractObjectSearch $oTool): void
	{
		$aProperties = $oTool->getInputSchema()['properties'];

		foreach (['limit', 'offset', 'order_by', 'order_direction'] as $sArgument) {
			$this->assertArrayHasKey($sArgument, $aProperties, get_class($oTool).": no {$sArgument} argument");
		}
	}

	/**
	 * A property with no matching parameter is dropped by the SDK, which binds
	 * by name: the argument would be accepted, advertised, and ignored.
	 *
	 * @dataProvider searchToolProvider
	 */
	public function testTheOrderingArgumentsReachExecute(AbstractObjectSearch $oTool): void
	{
		$aParameters = [];
		foreach ((new ReflectionMethod($oTool, 'execute'))->getParameters() as $oParameter) {
			$aParameters[$oParameter->getName()] = $oParameter;
		}

		foreach (['order_by', 'order_direction'] as $sArgument) {
			$this->assertArrayHasKey($sArgument, $aParameters, get_class($oTool).": execute() takes no {$sArgument}");
			$this->assertTrue($aParameters[$sArgument]->isOptional(), get_class($oTool).": {$sArgument} has no default");
		}
	}

	/**
	 * Sorting is a refinement, never a precondition: "search this" has to stay
	 * one call away.
	 *
	 * @dataProvider searchToolProvider
	 */
	public function testOrderingIsOptional(AbstractObjectSearch $oTool): void
	{
		$aSchema = $oTool->getInputSchema();

		$this->assertNotContains('order_by', $aSchema['required']);
		$this->assertNotContains('order_direction', $aSchema['required']);
		$this->assertSame('', $aSchema['properties']['order_by']['default']);
	}

	/**
	 * @dataProvider searchToolProvider
	 */
	public function testTheDirectionIsConstrainedToTheTwoITopUnderstands(AbstractObjectSearch $oTool): void
	{
		$aDirection = $oTool->getInputSchema()['properties']['order_direction'];

		$this->assertSame(
			[AbstractObjectSearch::SORT_ASC, AbstractObjectSearch::SORT_DESC],
			$aDirection['enum']
		);
		$this->assertSame(AbstractObjectSearch::DEFAULT_SORT, $aDirection['default']);
	}

	/**
	 * The tiebreaker is the whole point of the change, and offset is where a
	 * model is told that paging can be trusted.
	 *
	 * @dataProvider searchToolProvider
	 */
	public function testTheOffsetArgumentDocumentsThatPagingIsStable(AbstractObjectSearch $oTool): void
	{
		$this->assertStringContainsString(
			'id',
			$oTool->getInputSchema()['properties']['offset']['description']
		);
	}

	/**
	 * @return array<int, array{0: int, 1: int, 2: int, 3: bool, 4: int|null}>
	 */
	public static function pagingFooterProvider(): array
	{
		//        total, limit, offset, has_more, next_offset
		return [
			'first of three pages'   => [120, 50, 0, true, 50],
			'middle page'            => [120, 50, 50, true, 100],
			'last, partly filled'    => [120, 50, 100, false, null],
			'exactly one full page'  => [50, 50, 0, false, null],
			'one over a full page'   => [51, 50, 0, true, 50],
			'nothing matched'        => [0, 50, 0, false, null],
			'past the end'           => [10, 50, 50, false, null],
		];
	}

	/**
	 * @dataProvider pagingFooterProvider
	 */
	public function testTheFooterSaysWhetherAnotherPageExists(int $iTotal, int $iLimit, int $iOffset, bool $bHasMore, ?int $iNext): void
	{
		$aFooter = self::pagingFooter($iTotal, $iLimit, $iOffset);

		$this->assertSame($bHasMore, $aFooter['has_more']);
		$this->assertSame($iNext, $aFooter['next_offset']);
	}

	/**
	 * The reason the footer is computed from limit and not from the number of
	 * objects returned: object-level rights drop rows from a page after the
	 * database has already skipped limit of them, so a short page is not the
	 * last page and a caller counting rows stops early.
	 */
	public function testAPageShortenedByRightsStillOffersTheNextOne(): void
	{
		$aFooter = self::pagingFooter(120, 50, 0);

		$this->assertTrue($aFooter['has_more']);
		$this->assertSame(50, $aFooter['next_offset'], 'the next page starts where the database stopped, not where the results did');
	}

	/**
	 * next_offset is null at the end rather than an offset past it, so that
	 * "call again with this" cannot be read into "there is nothing more".
	 */
	public function testTheEndIsSaidWithNullRatherThanANumber(): void
	{
		$this->assertNull(self::pagingFooter(10, 50, 0)['next_offset']);
	}

	/**
	 * @return array{has_more: bool, next_offset: int|null}
	 */
	private static function pagingFooter(int $iTotal, int $iLimit, int $iOffset): array
	{
		// No setAccessible(): protected methods have been reachable through
		// reflection since PHP 8.1, and the call is deprecated from 8.5.
		return (new \ReflectionMethod(AbstractObjectSearch::class, 'pagingFooter'))
			->invoke(null, $iTotal, $iLimit, $iOffset);
	}

	/**
	 * A free-text needle matches itself, not a pattern.
	 *
	 * AddCondition_FullText() binds the needle as a parameter wrapped in %...%,
	 * so nothing here is about injection - the needle never reaches the query
	 * text. It is about the answer being right. iTop escapes _ inside that
	 * method because it is the single-character wildcard, and leaves % alone,
	 * so "100%" matched every object of every readable class.
	 *
	 * A human sees that instantly. A model cannot: it gets a full page of
	 * unrelated objects with no signal that they are unrelated, and reports
	 * them as matches.
	 *
	 * @dataProvider needleProvider
	 */
	public function testAPercentIsMatchedLiterally(string $sNeedle, string $sExpected): void
	{
		$oLiteral = new \ReflectionMethod(ObjectFindByName::class, 'literal');

		$this->assertSame($sExpected, $oLiteral->invoke(null, $sNeedle));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function needleProvider(): array
	{
		return [
			'percent is escaped'         => ['100% off', '100\% off'],
			'a bare percent'             => ['%', '\%'],
			'underscore is left to iTop' => ['srv_01', 'srv_01'],
			'ordinary text is untouched' => ['web server', 'web server'],
			'empty stays empty'          => ['', ''],
		];
	}
}
