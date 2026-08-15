<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * How much of an object a read is allowed to return.
 *
 * A tool result is read into a context window, and "every attribute of every
 * match" is the shape that does not fit in one: fifty tickets times forty
 * attributes, most of them irrelevant to the question that was asked. The
 * paths that do not need a live iTop - what is advertised, what the defaults
 * are, and the guard on the one combination that cannot fit anywhere - are
 * pinned here.
 */
class OutputFieldsContractTest extends TestCase
{
	public function testEveryReadingToolOffersTheArgument(): void
	{
		foreach ([new ObjectSearchByOQL(), new ObjectSearchByClass(), new ObjectGet()] as $oTool) {
			$this->assertArrayHasKey(
				'output_fields',
				$oTool->getInputSchema()['properties'],
				get_class($oTool).' does not let the caller narrow the response'
			);
		}
	}

	/**
	 * A list defaults to the two fields iTop's REST API defaults to; one
	 * object asked for by id defaults to all of them, because that is what
	 * asking for one object by id means.
	 */
	public function testTheDefaultsDifferBetweenAListAndASingleObject(): void
	{
		foreach ([new ObjectSearchByOQL(), new ObjectSearchByClass()] as $oTool) {
			$this->assertSame(
				ObjectSerializer::DEFAULT_LIST_FIELDS,
				$oTool->getInputSchema()['properties']['output_fields']['default'],
				get_class($oTool).' does not default to the REST field list'
			);
		}

		$this->assertSame(
			ObjectSerializer::ALL_FIELDS,
			(new ObjectGet())->getInputSchema()['properties']['output_fields']['default']
		);
	}

	/**
	 * @dataProvider searchToolProvider
	 */
	public function testTheArgumentReachesExecuteWithTheSameDefault(string $sTool): void
	{
		$aDefaults = [];
		foreach ((new ReflectionMethod($sTool, 'execute'))->getParameters() as $oParameter) {
			$aDefaults[$oParameter->getName()] = $oParameter->isDefaultValueAvailable()
				? $oParameter->getDefaultValue()
				: null;
		}

		$this->assertArrayHasKey('output_fields', $aDefaults, "{$sTool}::execute() takes no output_fields");
		$this->assertSame(
			(new $sTool())->getInputSchema()['properties']['output_fields']['default'],
			$aDefaults['output_fields'],
			"{$sTool} advertises one default and applies another"
		);
	}

	/** @return array<string, array{0: string}> */
	public static function searchToolProvider(): array
	{
		return [
			'by OQL'   => [ObjectSearchByOQL::class],
			'by class' => [ObjectSearchByClass::class],
			'get'      => [ObjectGet::class],
		];
	}

	public function testAskingForEverythingMeansNoNarrowing(): void
	{
		// Neither path touches MetaModel, which is what keeps this a unit test.
		$this->assertNull(ObjectSerializer::ParseFieldList('UserRequest', ObjectSerializer::ALL_FIELDS));
		$this->assertNull(ObjectSerializer::ParseFieldList('UserRequest', ''));
		$this->assertNull(ObjectSerializer::ParseFieldList('UserRequest', '  *  '));
	}

	/**
	 * The combination that cannot fit anywhere is refused with something a
	 * model can act on, rather than answered with several megabytes.
	 */
	public function testEveryAttributeOfAThousandObjectsIsRefused(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/output_fields|limit/');

		$this->fieldsForPage('UserRequest', ObjectSerializer::ALL_FIELDS, AbstractObjectSearch::MAX_LIMIT);
	}

	public function testEveryAttributeOfASmallPageIsAllowed(): void
	{
		$this->assertNull(
			$this->fieldsForPage('UserRequest', ObjectSerializer::ALL_FIELDS, AbstractObjectSearch::MAX_LIMIT_ALL_FIELDS)
		);
	}

	/**
	 * @return array<int, string>|null
	 */
	private function fieldsForPage(string $sClass, string $sOutputFields, int $iLimit): ?array
	{
		return (new ReflectionMethod(AbstractObjectSearch::class, 'fieldsForPage'))
			->invoke(null, $sClass, $sOutputFields, $iLimit);
	}
}
