<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Read\NameSuggestion;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/** Review 2026-11-23, C12: a suggestion, never an acceptance, among served names alone. */
class NameSuggestionTest extends TestCase
{
	private const CLASSES = ['UserRequest', 'Incident', 'Person', 'Organization', 'Server', 'Team', 'Ticket'];

	public function testTheSameNameInAnotherCaseIsTheClosest(): void
	{
		$this->assertSame(['UserRequest'], NameSuggestion::Closest('userrequest', self::CLASSES));
		$this->assertSame(['Person'], NameSuggestion::Closest('PERSON', self::CLASSES));
	}

	public function testATypoAndAShortcutAreNearTheirName(): void
	{
		$this->assertSame(['UserRequest'], NameSuggestion::Closest('UserReqest', self::CLASSES));
		$this->assertSame(['Incident'], NameSuggestion::Closest('Incdent', self::CLASSES));
		$this->assertSame(['status'], NameSuggestion::Closest('state', ['status', 'priority', 'agent_id', 'title']));
	}

	/** Retest of 2026-11-28: `tto_deadline` is eleven letters from `tto_escalation_deadline`, and is what was meant. */
	public function testANameThatHoldsEveryWordInOrderIsNear(): void
	{
		$aCodes = ['tto_escalation_deadline', 'ttr_escalation_deadline', 'tto', 'sla_tto_passed', 'deadline_of_tto'];

		$this->assertSame(['tto_escalation_deadline'], NameSuggestion::Closest('tto_deadline', $aCodes));
		$this->assertSame([], NameSuggestion::Closest('deadline', ['tto_escalation_deadline']), 'one word is not enough to guess from');
		$this->assertSame([], NameSuggestion::Closest('deadline_tto', ['tto_escalation_deadline']), 'the words are in their order');
		$this->assertSame(
			['org_nam', 'org_short_legal_name'],
			NameSuggestion::Closest('org_name', ['org_short_legal_name', 'org_nam']),
			'a name near by distance comes first'
		);
	}

	public function testNothingIsNearAWordThatIsNoName(): void
	{
		$this->assertSame([], NameSuggestion::Closest('ticketing_system', self::CLASSES));
		$this->assertSame([], NameSuggestion::Closest('x', ['status', 'priority']));
	}

	public function testTheNameItselfIsNeverItsOwnSuggestion(): void
	{
		$this->assertSame([], NameSuggestion::Closest('Person', ['Person']));
	}

	public function testAtMostThreeClosestFirstThenAlphabetically(): void
	{
		$aNear = NameSuggestion::Closest('item', ['item1', 'item12', 'items', 'itex', 'itemxyz'], 3);

		$this->assertCount(3, $aNear);
		$this->assertSame(['item1', 'items', 'itex'], $aNear, 'by distance, then by name');
	}

	public function testOnlyAValueShapedLikeANameIsComparedOrQuoted(): void
	{
		foreach (["UserRequest' OR 1=1 --", '<b>Person</b>', "Person\n", '', str_repeat('a', 200), '9Person'] as $sValue) {
			$this->assertSame([], NameSuggestion::Closest($sValue, self::CLASSES), json_encode($sValue));
			$this->assertSame('', NameSuggestion::ForClass($sValue));
			$this->assertSame('', NameSuggestion::ForAttribute('UserRequest', $sValue));
		}
	}

	public function testTheSentenceSaysCaseOnlyWhenCaseIsTheDifference(): void
	{
		$this->assertSame(" Did you mean 'UserRequest'? Names are case-sensitive.", NameSuggestion::Sentence(['UserRequest'], 'userrequest'));
		$this->assertSame(" Did you mean 'UserRequest'?", NameSuggestion::Sentence(['UserRequest'], 'UserReqest'));
		$this->assertSame(" Did you mean 'a', 'b' or 'c'?", NameSuggestion::Sentence(['a', 'b', 'c'], 'x'));
		$this->assertSame('', NameSuggestion::Sentence([], 'x'));
	}

	/**
	 * The shape that keeps an excluded name from showing: the nearest are taken from the served names alone, so an excluded
	 * neighbour cannot crowd a slot out. Held on the pure part by giving it the served list, and on the
	 * instance by NameSuggestionIntegrationTest.
	 */
	public function testWhatIsSuggestedIsAFunctionOfTheStringAndTheServedNames(): void
	{
		$aServed = ['Person', 'Server'];
		$aAll = [...$aServed, 'Team'];

		// 'Tean' is one edit from the excluded 'Team' and two from nothing served.
		$this->assertSame([], NameSuggestion::Closest('Tean', $aServed));
		$this->assertSame(['Team'], NameSuggestion::Closest('Tean', $aAll), 'the control: the same string with the name served');
	}
}
