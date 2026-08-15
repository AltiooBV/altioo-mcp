<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
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
}
