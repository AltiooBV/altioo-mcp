<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a caller is told when the target state wants attributes that are not
 * there.
 *
 * Two situations wear the same words unless something separates them: an empty
 * mandatory attribute this caller may set, which is a call it can correct by
 * passing `fields`, and one it may not set, which is a call that will never
 * work however many times it is retried. Reporting them as one list sends a
 * model round the loop setting what it can and being refused on what it
 * cannot, with nothing telling it which of the two it just hit.
 *
 * Deciding which bucket an attribute falls in needs a datamodel and a rights
 * addon. Composing the sentence does not, which is what makes this a unit test
 * rather than an integration one - and the sentence is the part a model acts
 * on.
 */
class StimulusMandatoryAttributesTest extends TestCase
{
	/**
	 * @param array<int, string>    $aFillable
	 * @param array<string, string> $aBlocked
	 */
	private function message(string $sStimulus, array $aFillable, array $aBlocked): string
	{
		// No setAccessible(): it has been a no-op since PHP 8.1 and is
		// deprecated in 8.5, which the suite reports as risky output.
		$oMethod = new ReflectionMethod(ObjectApplyStimulus::class, 'missingMandatoryMessage');

		return $oMethod->invoke(null, $sStimulus, $aFillable, $aBlocked);
	}

	public function testOnlyFillableReadsAsSomethingToCorrect(): void
	{
		$sMessage = $this->message('ev_resolve', ['solution', 'team_id'], []);

		$this->assertStringContainsString('Missing mandatory attribute(s)', $sMessage);
		$this->assertStringContainsString('solution, team_id', $sMessage);
		$this->assertStringNotContainsString('cannot be applied', $sMessage);
	}

	/**
	 * The point of the split: one call, both lists. Raising the fillable ones
	 * on this call and the blocked ones on the next means the caller sets
	 * attributes onto an object for a transition it was never going to be
	 * allowed to complete.
	 */
	public function testBothAreRaisedTogether(): void
	{
		$sMessage = $this->message(
			'ev_resolve',
			['solution'],
			['team_id' => 'write access denied for this user']
		);

		$this->assertStringContainsString('team_id', $sMessage);
		$this->assertStringContainsString('solution', $sMessage);
	}

	/**
	 * A caller that reads only the first sentence should come away knowing the
	 * call cannot succeed, rather than fixing the fillable half and arriving
	 * back here.
	 */
	public function testTheBlockedOnesLead(): void
	{
		$sMessage = $this->message(
			'ev_resolve',
			['solution'],
			['team_id' => 'write access denied for this user']
		);

		$this->assertLessThan(
			strpos($sMessage, 'solution'),
			strpos($sMessage, 'team_id'),
			'the attributes that make the call impossible are reported after the ones that do not'
		);
		$this->assertStringContainsString('Retrying will not help', $sMessage);
	}

	public function testEachBlockedAttributeCarriesItsReason(): void
	{
		$sMessage = $this->message('ev_close', [], [
			'team_id'  => 'write access denied for this user',
			'start_date' => 'not writable on this class',
		]);

		// The two reasons are different problems: one is a grant an
		// administrator can make, the other is a fact about the datamodel that
		// no grant changes. A caller has to know whether it has anyone to ask.
		$this->assertStringContainsString('team_id (write access denied for this user)', $sMessage);
		$this->assertStringContainsString('start_date (not writable on this class)', $sMessage);
	}

	public function testAFillableOnlyCallDoesNotClaimRetryingIsPointless(): void
	{
		$this->assertStringNotContainsString(
			'Retrying will not help',
			$this->message('ev_resolve', ['solution'], [])
		);
	}

	/**
	 * The read-only half of the check. It used to ask the write right and
	 * nothing else, so a mandatory attribute the datamodel declares unwritable
	 * was reported as one the caller should fill in - advice it could follow
	 * for ever.
	 */
	public function testTheDatamodelReadOnlyCaseIsCheckedAtAll(): void
	{
		$oMethod = new ReflectionMethod(ObjectApplyStimulus::class, 'whyItCannotBeSet');
		$aLines  = file($oMethod->getFileName());
		$sBody   = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$this->assertStringContainsString('IsActionAllowedOnAttribute', $sBody, 'the write right is no longer checked');
		$this->assertStringContainsString('IsWritable', $sBody, 'a mandatory attribute that is read-only on the class is reported as one the caller should fill in');
	}
}
