<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A relation walk must not be the way around a credential without bulk read.
 *
 * The search tools refuse a class the caller lacks UR_ACTION_BULK_READ on.
 * core_object_get_related returns a set too - one reachable object, a relation
 * and a depth - so gating it on UR_ACTION_READ alone left "this assistant may
 * not sweep the CMDB" meaning nothing: the objects the search refused come back
 * through the graph instead.
 *
 * The rule is per class and starts at the second object, so the ordinary "what
 * does this depend on" answer still works for a caller holding only
 * UR_ACTION_READ. That boundary is the part worth pinning: moved to zero it
 * breaks impact analysis for every non-bulk caller, and removed it reopens the
 * hole.
 */
class RelatedBulkReadContractTest extends TestCase
{
	public function testTheGraphAsksForBulkReadAtAll(): void
	{
		$this->assertStringContainsString(
			'UR_ACTION_BULK_READ',
			$this->gateBody(),
			'A relation walk returns a set; without this check it returns one the search tools would refuse.'
		);
	}

	/**
	 * Per class, not once for the whole graph: a caller may hold bulk read on
	 * one class of the walk and not on another, and the answer differs.
	 */
	public function testTheCheckIsMadePerClass(): void
	{
		$sBody = $this->gateBody();

		$this->assertMatchesRegularExpression(
			'/foreach\s*\(\s*\$aStats\s+as\s+\$\w+\s*=>\s*\$\w+\s*\)/',
			$sBody,
			'The per-class tally is what the check has to walk.'
		);
	}

	/**
	 * One related object of a class is a single read and stays one.
	 */
	public function testASingleObjectOfAClassIsStillASingleRead(): void
	{
		$this->assertMatchesRegularExpression(
			'/\$\w+\s*>\s*1\s*&&/',
			$this->gateBody(),
			'The gate must start at the second object of a class, or impact analysis dies for every non-bulk caller.'
		);
	}

	/**
	 * Refused, not trimmed. A trimmed graph keeps edges pointing at objects it
	 * no longer carries, and reads as complete.
	 */
	public function testTheCallIsRefusedRatherThanTrimmed(): void
	{
		$this->assertStringContainsString(
			'throw new ToolCallException',
			$this->gateBody(),
			'Dropping the surplus would hand back a graph that reads as complete.'
		);
	}

	/**
	 * The same wording the search tools use. A caller that hits the ceiling
	 * through the graph and through a search should not have to learn that
	 * these are the same refusal.
	 */
	public function testTheRefusalIsWordedAsTheSearchToolsWordIt(): void
	{
		$this->assertStringContainsString(
			"Bulk read access denied to class '{",
			$this->gateBody()
		);
		$this->assertStringContainsString(
			"Bulk read access denied to class '{",
			$this->methodBody(ObjectSearchByClass::class, 'execute')
		);
	}

	/** The gate is reached from the code that assembles the payload. */
	public function testTheGateIsActuallyCalled(): void
	{
		$this->assertStringContainsString(
			'assertBulkReadWhereTheGraphReadsInBulk',
			$this->methodBody(ObjectGetRelated::class, 'serializeGraph'),
			'An uncalled gate is not a gate.'
		);
	}

	private function gateBody(): string
	{
		return $this->methodBody(ObjectGetRelated::class, 'assertBulkReadWhereTheGraphReadsInBulk');
	}

	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());

		return implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));
	}
}
