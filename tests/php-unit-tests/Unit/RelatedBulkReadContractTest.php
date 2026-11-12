<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

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
 * not sweep the CMDB" meaning nothing: the objects search refused come back
 * through the graph instead.
 *
 * The class is withheld rather than the call refused, because a walk spans
 * classes the caller is graded differently on and the rest of the answer is
 * still owed. Three properties make that safe, and each is pinned below: the
 * boundary is the second object of a class, the caller is *told* what was
 * withheld, and the edges touching a withheld object go with it. Silence is
 * the one outcome ruled out - a graph quietly missing a class reads as a
 * complete one, which is the failure FindByNameRightsTest describes for search.
 */
class RelatedBulkReadContractTest extends TestCase
{
	public function testTheGraphAsksForBulkReadAtAll(): void
	{
		// Read from tokens: a comment or a string naming the constant is not the check.
		$this->assertTrue(
			$this->source()->HasConstant('UR_ACTION_BULK_READ', 'classesReadInBulkWithoutTheRight'),
			'A relation walk returns a set; without this check it returns one the search tools would refuse.'
		);
		$this->assertTrue(
			$this->source()->HasCallTo('IsActionAllowed', 'classesReadInBulkWithoutTheRight'),
			'The constant has to be handed to the rights check.'
		);
	}

	/**
	 * A partial graph says so where a caller will see it.
	 *
	 * `withheld` names the classes and explains itself, but a caller has to go
	 * looking to learn there is anything to look for - and a graph quietly
	 * missing a class reads exactly like a complete one, which is how "nothing
	 * related" reaches a user as fact. `truncated` sits beside `summary`, is
	 * always present, and is false on the ordinary answer.
	 */
	public function testAPartialGraphIsFlaggedBesideTheSummary(): void
	{
		$this->assertStringContainsString(
			"'truncated'=>\$aWithheld!==[]",
			$this->serializeBody(),
			'the flag is not derived from what was actually withheld'
		);
		$this->assertStringContainsString(
			'truncated',
			(string) (new \Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated())->getDescription(),
			'the description never mentions the flag a caller has to read'
		);
	}

	/**
	 * Per class, not once for the whole graph: a caller may hold bulk read on
	 * one class of the walk and not on another, and the answer differs.
	 */
	public function testTheCheckIsMadePerClass(): void
	{
		$this->assertMatchesRegularExpression(
			'/foreach\(\$aStatsas\$\w+=>\$\w+\)/',
			$this->gateBody(),
			'The per-class tally is what the check has to walk.'
		);
	}

	/**
	 * One related object of a class is a single read and stays one. Moved to
	 * zero, impact analysis dies for every caller holding only read.
	 */
	public function testASingleObjectOfAClassIsStillASingleRead(): void
	{
		$this->assertMatchesRegularExpression(
			'/\$\w+>1&&/',
			$this->gateBody(),
			'The gate must start at the second object of a class.'
		);
	}

	/**
	 * The withheld classes leave the payload. Counted but still returned would
	 * be a gate that reports itself and does nothing.
	 */
	public function testWithheldClassesAreRemovedFromTheObjects(): void
	{
		$this->assertTrue(
			$this->source()->HasCall('array_filter', 'serializeGraph'),
			'The objects of a withheld class have to leave the payload, not merely be noted.'
		);
	}

	/**
	 * An edge to an object the payload no longer carries is how a partial
	 * graph reads as a complete one.
	 */
	public function testEdgesTouchingAWithheldObjectAreDropped(): void
	{
		$this->assertMatchesRegularExpression(
			'/if\(!isset\(\$aObjects\[\$sFrom\]\)\|\|!isset\(\$aObjects\[\$sTo\]\)\)/',
			$this->serializeBody(),
			'Both ends of a returned edge must still be in the payload.'
		);
	}

	/**
	 * The caller is told. This is the whole difference between withholding and
	 * losing data silently.
	 */
	public function testTheCallerIsToldWhatWasWithheld(): void
	{
		$sBody = $this->serializeBody();

		$this->assertStringContainsString("\$aPayload['withheld']=", $sBody, 'Silence is the outcome ruled out.');
		$this->assertStringContainsString("'classes'=>\$aWithheld", $sBody, 'The classes are named.');
	}

	/**
	 * Named, never counted. The names describe this account's rights on classes
	 * it already holds read on; a count would be the datum the bulk right is
	 * withholding.
	 */
	public function testWhatIsWithheldIsNamedAndNotCounted(): void
	{
		$sWithheldBlock = $this->withheldBlock();

		$this->assertStringNotContainsString('count(', $sWithheldBlock);
		$this->assertStringNotContainsString('$iCount', $sWithheldBlock);
	}

	/** The gate is reached from the code that assembles the payload. */
	public function testTheGateIsActuallyCalled(): void
	{
		$this->assertTrue(
			$this->source()->HasCall(ObjectGetRelated::class.'::classesReadInBulkWithoutTheRight', 'serializeGraph'),
			'An uncalled gate is not a gate.'
		);
	}

	private function source(): SourceCalls
	{
		return SourceCalls::OfClass(ObjectGetRelated::class);
	}

	private function gateBody(): string
	{
		return $this->source()->Code('classesReadInBulkWithoutTheRight');
	}

	private function serializeBody(): string
	{
		return $this->source()->Code('serializeGraph');
	}

	/** Just the block that builds the withheld report. */
	private function withheldBlock(): string
	{
		$sBody = $this->serializeBody();
		// The `if` that builds the report, not the `truncated` flag above it.
		$iStart = strpos($sBody, 'if($aWithheld!==[]){');
		$this->assertNotFalse($iStart, 'The withheld report must exist to be checked.');

		return substr($sBody, $iStart);
	}
}
