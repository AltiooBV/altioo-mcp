<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a client pays to be told what this endpoint offers, so that growth is a
 * decision and not a side effect.
 *
 * Every tool's description and input schema, and the server's instructions,
 * are put in front of a model before its first question. They were measured
 * (review of 2026-11-05, tools/measure-surface.php): on a full-scope token the
 * whole surface is about 66 KB, which is a sixth of a small model's window. A
 * sentence added to a description costs that on every connection of every
 * client, and nothing else here would notice.
 *
 * The budgets are the measured sizes, rounded up a little. A tool that grows
 * past its budget fails here, and the change that grows it raises the number in
 * the same commit, which is where a reviewer sees it. This is a size check and
 * not a snapshot of the surface: what the words say is ADR 0008's, and is
 * deferred. The output schemas and the answers a tool gives are measured in the
 * report and are not budgeted here.
 *
 * Sizes are of the PHP strings, in the console's mode: the portal's are
 * smaller, and the live numbers (JSON-encoded, with the date formats the
 * instructions carry on an instance) are somewhat larger.
 */
class ContextBudgetTest extends TestCase
{
	/**
	 * Bytes of description, and of the JSON of the input schema, per core tool.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private const BUDGET = [
		'ClassList'           => [600, 1260],
		'ClassSchema'         => [1860, 900],
		'CurrentUser'         => [740, 40],
		'Instructions'        => [780, 40],
		'ObjectApplyStimulus' => [520, 1020],
		'ObjectAttach'        => [400, 1610],
		'ObjectBulkCreate'    => [420, 920],
		'ObjectBulkDelete'    => [560, 1240],
		'ObjectBulkUpdate'    => [420, 1500],
		'ObjectCount'         => [260, 1080],
		'ObjectCreate'        => [580, 850],
		'ObjectDelete'        => [430, 1000],
		'ObjectFindByName'    => [910, 1180],
		'ObjectGet'           => [730, 740],
		'ObjectGetDocument'   => [680, 470],
		'ObjectGetRelated'    => [800, 1390],
		'ObjectHistory'       => [710, 740],
		'ObjectSearchByClass' => [240, 3350],
		'ObjectSearchByOQL'   => [220, 2690],
		'ObjectUpdate'        => [580, 1230],
		'PortalList'          => [700, 900],
		'SetObsoleteData'     => [530, 410],
	];

	/** The whole of what the budgets above add up to, with a little room. */
	private const TOTAL_DESCRIPTIONS = 13550;
	private const TOTAL_INPUT_SCHEMAS = 24400;

	/** The server's instructions for an unrestricted caller, before an instance adds its date formats. */
	private const INSTRUCTIONS = 4500;

	/** @return array<string, string> Short class name => class. */
	private static function coreTools(): array
	{
		$aTools = [];
		foreach (glob(dirname(__DIR__, 3).'/src/Core/Tools/*.php') ?: [] as $sFile) {
			$sName = basename($sFile, '.php');
			if ($sName === 'AbstractPortalTool') {
				continue;
			}
			$aTools[$sName] = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sName;
		}

		return $aTools;
	}

	public function testEveryCoreToolHasABudget(): void
	{
		$this->assertEqualsCanonicalizing(
			array_keys(self::BUDGET),
			array_keys(self::coreTools()),
			'a core tool was added or removed: give it a budget here, which is the decision to add to every client\'s context'
		);
	}

	/**
	 * @dataProvider toolProvider
	 */
	public function testAToolDoesNotGrowPastItsBudget(string $sName, string $sClass): void
	{
		$oTool = new $sClass();
		$iDescription = strlen((string)$oTool->getDescription());
		$iSchema = strlen((string)json_encode($oTool->getInputSchema()));

		$this->assertLessThanOrEqual(self::BUDGET[$sName][0], $iDescription, $sName.' description is '.$iDescription.' bytes; raise its budget on purpose, or cut it');
		$this->assertLessThanOrEqual(self::BUDGET[$sName][1], $iSchema, $sName.' input schema is '.$iSchema.' bytes; raise its budget on purpose, or cut it');
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function toolProvider(): array
	{
		$aRows = [];
		foreach (self::coreTools() as $sName => $sClass) {
			$aRows[$sName] = [$sName, $sClass];
		}

		return $aRows;
	}

	public function testTheWholeSurfaceDoesNotGrowPastItsBudget(): void
	{
		$iDescriptions = 0;
		$iSchemas = 0;
		foreach (self::coreTools() as $sClass) {
			$oTool = new $sClass();
			$iDescriptions += strlen((string)$oTool->getDescription());
			$iSchemas += strlen((string)json_encode($oTool->getInputSchema()));
		}

		$this->assertLessThanOrEqual(self::TOTAL_DESCRIPTIONS, $iDescriptions, 'the descriptions together grew: '.$iDescriptions);
		$this->assertLessThanOrEqual(self::TOTAL_INPUT_SCHEMAS, $iSchemas, 'the input schemas together grew: '.$iSchemas);
	}

	public function testTheInstructionsDoNotGrowPastTheirBudget(): void
	{
		$iBytes = strlen(ServerInstructions::Text(AccessPolicy::Unrestricted()));

		$this->assertLessThanOrEqual(self::INSTRUCTIONS, $iBytes, 'the instructions are '.$iBytes.' bytes; raise the budget on purpose, or cut it');
	}
}
