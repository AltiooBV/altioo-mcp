<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The guards on the tools with the largest blast radius in the module.
 *
 * The permission checks themselves need a live iTop and are exercised by the
 * integration suite; what is pinned here is everything that decides whether a
 * call is attempted at all - the batch ceiling, the id list, and the fact that
 * none of the three does anything on a first call.
 */
class BulkToolContractTest extends TestCase
{
	/** @return array<string, array{0: object}> */
	public static function bulkToolProvider(): array
	{
		return [
			'create' => [new ObjectBulkCreate()],
			'update' => [new ObjectBulkUpdate()],
			'delete' => [new ObjectBulkDelete()],
		];
	}

	/**
	 * A bulk call is the one a model should never make by accident on its
	 * first attempt, so all three withhold the action until asked twice - the
	 * rule core_object_delete already followed alone.
	 *
	 * @dataProvider bulkToolProvider
	 */
	public function testNothingHappensOnAFirstCall(object $oTool): void
	{
		$aSchema = $oTool->getInputSchema();

		$this->assertArrayHasKey('simulate', $aSchema['properties'], get_class($oTool).' has no dry run');
		$this->assertTrue($aSchema['properties']['simulate']['default'], get_class($oTool).' acts by default');
		$this->assertNotContains('simulate', $aSchema['required']);

		$aSimulate = array_values(array_filter(
			(new ReflectionMethod($oTool, 'execute'))->getParameters(),
			static fn (\ReflectionParameter $oParameter): bool => $oParameter->getName() === 'simulate'
		));
		$this->assertCount(1, $aSimulate, get_class($oTool).'::execute() takes no simulate');
		$this->assertTrue($aSimulate[0]->getDefaultValue(), get_class($oTool).' defaults to acting');
	}

	/**
	 * @dataProvider bulkToolProvider
	 */
	public function testTheBatchCeilingIsAdvertisedToTheClient(object $oTool): void
	{
		$aProperties = $oTool->getInputSchema()['properties'];
		$aList = $aProperties['ids'] ?? $aProperties['objects'];

		$this->assertSame(AbstractBulkTool::MAX_OBJECTS, $aList['maxItems']);
		$this->assertSame(1, $aList['minItems']);
	}

	public function testTheDeleteToolIsAnnotatedDestructiveAndNotIdempotent(): void
	{
		$aAnnotations = (new ObjectBulkDelete())->getAnnotations()->jsonSerialize();

		$this->assertTrue($aAnnotations['destructiveHint']);
		$this->assertFalse($aAnnotations['readOnlyHint']);
		$this->assertFalse($aAnnotations['idempotentHint'], 'the second call finds nothing left to delete');
	}

	// --- the id list ------------------------------------------------------

	public function testIdsAreDeduplicated(): void
	{
		// Acting twice on one object is at best a wasted write and at worst a
		// second case log entry.
		$this->assertSame([7, 9], $this->checkIds([7, 9, 7]));
	}

	public function testDigitStringsAreAcceptedAsIds(): void
	{
		// Some clients send every argument as a string.
		$this->assertSame([7], $this->checkIds(['7']));
	}

	/**
	 * @dataProvider badIdProvider
	 */
	public function testAnIdListThatCannotBeActedOnIsRefusedOutright(array $aIds): void
	{
		$this->expectException(ToolCallException::class);

		$this->checkIds($aIds);
	}

	/** @return array<string, array{0: array<int, mixed>}> */
	public static function badIdProvider(): array
	{
		return [
			'empty'          => [[]],
			'zero'           => [[0]],
			'negative'       => [[-1]],
			'not a number'   => [['all']],
			'an OQL query'   => [['SELECT UserRequest']],
			'past the limit' => [range(1, AbstractBulkTool::MAX_OBJECTS + 1)],
		];
	}

	/**
	 * The ceiling is a refusal rather than a silent truncation: taking the
	 * first hundred of a list of four hundred would report success on a
	 * quarter of the work.
	 */
	public function testTheCeilingRefusesRatherThanTruncates(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/batches/');

		$this->checkIds(range(1, AbstractBulkTool::MAX_OBJECTS + 1));
	}

	public function testExactlyTheCeilingIsAllowed(): void
	{
		$this->assertCount(
			AbstractBulkTool::MAX_OBJECTS,
			$this->checkIds(range(1, AbstractBulkTool::MAX_OBJECTS))
		);
	}

	/**
	 * @param array<int, mixed> $aIds
	 *
	 * @return array<int, int>
	 */
	private function checkIds(array $aIds): array
	{
		return (new ReflectionMethod(AbstractBulkTool::class, 'checkIds'))->invoke(null, $aIds);
	}
}
