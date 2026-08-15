<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
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
}
