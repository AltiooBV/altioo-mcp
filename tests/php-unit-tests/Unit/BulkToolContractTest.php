<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Mcp\Server\Exception\ToolCallException;
use Altioo\iTop\Extension\MCP\Service\Write\DeletionCascade;
use Altioo\iTop\Extension\MCP\Service\Write\WriteOutcome;
use Altioo\iTop\Extension\MCP\Service\Write\WriteValues;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
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
	/**
	 * A bulk-created row separates what was sent from what was defaulted.
	 *
	 * The single-object create answers with applied and defaulted beside
	 * changes; the bulk one answered with changes and overridden only - so for
	 * the tool most likely to be creating objects from data a model assembled,
	 * there was no way to tell "what I set" from "what iTop filled in" without
	 * diffing against the other tool's shape to learn the distinction exists.
	 *
	 * The values are read from the object in hand rather than re-read: a
	 * hundred rows would be a hundred queries for a report nobody asked for
	 * row by row.
	 */
	public function testABulkCreatedRowSaysWhatItSuppliedAndWhatWasDefaulted(): void
	{
		$aRow = (new ObjectBulkCreate())->getOutputSchema()['properties']['objects']['items'];

		foreach (['applied', 'defaulted', 'changes', 'overridden'] as $sKey) {
			$this->assertArrayHasKey($sKey, $aRow['properties'], "a bulk-created row does not report {$sKey}");
			$this->assertContains($sKey, $aRow['required'], "a bulk-created row reports {$sKey} only sometimes");
		}

		$oSource = SourceCalls::OfClass(ObjectBulkCreate::class);
		$this->assertTrue($oSource->HasCallTo('suppliedValues'));
		$this->assertFalse(
			$oSource->HasCall(WriteOutcome::class.'::After'),
			'the bulk path re-reads a row per object, which is a hundred queries'
		);
	}

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

	/**
	 * The schema has to accept what checkIds() accepts.
	 *
	 * Observed on a live instance: core_object_bulk_delete was handed the ids
	 * a read had just reported - ["1", "2"] - and the call was refused as
	 * "Expected integer, but received string" before it reached the tool at
	 * all. iTop's key is a string all the way out of the ORM, so every read
	 * here answers "id": "4", and a caller passing back exactly what it was
	 * given was the one thing the id list would not take.
	 *
	 * testDigitStringsAreAcceptedAsIds() below covers the runtime and passed
	 * throughout: the disagreement was between the two, which is the kind only
	 * a test that reads both can see.
	 *
	 * @dataProvider bulkToolProvider
	 */
	public function testTheIdSchemaAcceptsTheIdsAReadReports(object $oTool): void
	{
		$aProperties = $oTool->getInputSchema()['properties'];
		if (!isset($aProperties['ids'])) {
			// bulk_create takes rows of fields, not ids.
			$this->assertArrayHasKey('objects', $aProperties);

			return;
		}

		$mType = $aProperties['ids']['items']['type'];

		$this->assertContains('string', (array) $mType, 'a read reports id as a string, so the id list has to take one');
		$this->assertContains('integer', (array) $mType, 'and a client sending a number is not wrong either');
		$this->assertSame('^[0-9]+$', $aProperties['ids']['items']['pattern'] ?? null, 'a string id is digits, not free text');
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

	/**
	 * Review 2026-11-18, HA-4 and PA-3: AbstractBulkTool kept its own copy of WriteValues::Validated() and it
	 * drifted - the copy never asked ExclusionPolicy, so core_object_bulk_create committed an attribute the operator
	 * had excluded (and answered "Unknown attribute" to a name that does not exist, an oracle on the dry run), for a
	 * narrow console user as for anyone. Two validators for one rule set is how a rule gets forgotten in one of
	 * them: the bulk tools, the core ones and a pack's, now go through the one.
	 */
	public function testTheBulkToolsValidateThroughTheOneValidatorEveryOtherWriteUses(): void
	{
		$oSource = SourceCalls::OfClass(AbstractBulkTool::class);

		$this->assertTrue($oSource->HasCall(WriteValues::class.'::Validated', 'validatedValues'), 'validatedValues() no longer delegates to WriteValues::Validated()');
		$this->assertSame([], $oSource->MethodsCalling('RestUtils::MakeValue'), 'AbstractBulkTool converts caller values itself again: that is a second validator');
		foreach (['EditPolicy', 'MentionPolicy', 'FormulaPolicy', 'SecretPolicy', 'ExternalKeyPolicy'] as $sPolicy) {
			$this->assertStringNotContainsString($sPolicy.'::', $oSource->Code(), "AbstractBulkTool asks {$sPolicy} itself: it belongs in WriteValues::Validated(), where every write path gets it");
		}

		foreach ([ObjectBulkCreate::class, ObjectBulkUpdate::class] as $sTool) {
			$this->assertTrue(
				SourceCalls::OfClass($sTool)->HasCallTo('validatedValues'),
				"{$sTool} writes without validating its values"
			);
		}
	}

	/**
	 * Review 2026-11-18, AC-2: an id that an earlier entry of the same call deleted by cascade read as "not found" on a real run,
	 * where the dry run had said it would be deleted. The tool asks what the earlier plans took along before it looks an entry up.
	 */
	public function testTheDeleteToolAsksTheCascadeBeforeItLooksAnEntryUp(): void
	{
		$oSource = SourceCalls::OfClass(ObjectBulkDelete::class);

		$this->assertTrue($oSource->CallsInSequence([
			DeletionCascade::class.'::CascadeOf',
			ObjectBulkDelete::class.'::objectFor',
		], 'execute'), 'execute() looks an entry up before it asks whether an earlier one took it along');
		$this->assertTrue($oSource->HasCall(DeletionCascade::class.'::NoteCascade', 'deleteOne'), 'a deletion no longer records what it takes with it');
	}

	/** AC-1: every bulk report says at its top whether every object was done. */
	public function testTheBulkReportCarriesAWholeCallFlagAndTheSchemaDeclaresIt(): void
	{
		$this->assertStringContainsString("'all_succeeded'", SourceCalls::OfClass(AbstractBulkTool::class)->Code('report'));
		foreach ([new ObjectBulkCreate(), new ObjectBulkUpdate(), new ObjectBulkDelete()] as $oTool) {
			$this->assertArrayHasKey('all_succeeded', $oTool->getOutputSchema()['properties'], get_class($oTool));
		}
	}
}
