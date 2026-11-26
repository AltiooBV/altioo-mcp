<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, B3, the counts: the portal's ticket lists are on Ticket, whose status and priority are the
 * columns of UserRequest and Incident. A count per such a column is made class by class, each group says its class,
 * and nothing a list does not show is counted.
 *
 * Two portal users in one organisation, each with requests and incidents of their own, so that "the other user's
 * tickets" is a number that would show up.
 */
class PortalCountPerClassTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';
	private const ONGOING = 'ongoing-tickets-for-portal-user';

	private string $sSuffix;
	private int $iOrg;

	/** @var array<string, array{login: string, contact: int}> */
	private array $aUsers = [];

	/**
	 * What the first user holds, by class: how many tickets and how many of them urgent. Incident is there when
	 * the instance has it (the ITIL ticket options; iTop's simple ticket management, which the CI installs, has
	 * the requests only), and the stock portal's scope lets a user see these two kinds and no other.
	 *
	 * @var array<string, array{n: int, urgent: int}>
	 */
	private array $aPlan = [];

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')
			|| !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')
			|| !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs request management and the stock portal on iTop 3.2.2 or later.');
		}

		$this->sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('PerClass '.$this->sSuffix)->GetKey();

		// First user: three requests (two urgent) and, where the class exists, two incidents (one urgent).
		// Second user: one of each, not urgent.
		$this->aPlan = ['UserRequest' => ['n' => 3, 'urgent' => 2]];
		if (MetaModel::IsValidClass('Incident')) {
			$this->aPlan['Incident'] = ['n' => 2, 'urgent' => 1];
		}
		$this->aUsers['first'] = $this->portalUser('first', 31);
		$this->aUsers['second'] = $this->portalUser('second', 32);
		$iNum = 0;
		foreach ($this->aPlan as $sClass => $aHeld) {
			for ($i = 0; $i < $aHeld['n']; $i++) {
				$this->ticket($sClass, $this->aUsers['first'], ++$iNum, $i < $aHeld['urgent'] ? 'urgent' : 'calm');
			}
			$this->ticket($sClass, $this->aUsers['second'], ++$iNum, 'calm');
		}

		// Two changes, of a kind the portal's scope does not show a portal user, and that share the code "new" of requests.
		if (self::changeClass() !== '') {
			for ($i = 1; $i <= 2; $i++) {
				$this->createObject(self::changeClass(), [
					'ref'         => 'PerClass_'.$this->sSuffix.'_c'.$i,
					'title'       => 'Per class change '.$i,
					'description' => 'Per class change',
					'org_id'      => $this->iOrg,
				]);
			}
		}

		$this->enter('first');
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		RequestScope::Close();
		parent::tearDown();
	}

	/** @return array{login: string, contact: int} */
	private function portalUser(string $sName, int $iNum): array
	{
		$iContact = (int)$this->CreatePerson($iNum, $this->iOrg)->GetKey();
		$sLogin = 'perclass-'.$sName.'-'.$this->sSuffix;
		$oProfile = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :n'), [], ['n' => 'Portal user']))->Fetch();
		$this->CreateUser($sLogin, (int)$oProfile->GetKey(), 'Per-Class-'.$this->sSuffix.'-1!', $iContact);

		return ['login' => $sLogin, 'contact' => $iContact];
	}

	/** An open ticket of the user's; "urgent" and "calm" differ in priority, which iTop computes from impact and urgency. */
	private function ticket(string $sClass, array $aUser, int $iNum, string $sKind): void
	{
		$iLevel = $sKind === 'urgent' ? 1 : 3;
		$this->createObject($sClass, [
			'ref'         => 'PerClass_'.$this->sSuffix.'_'.$iNum,
			'title'       => 'Per class '.$iNum,
			'description' => 'Per class '.$sClass,
			'org_id'      => $this->iOrg,
			'caller_id'   => $aUser['contact'],
			'impact'      => $iLevel,
			'urgency'     => $iLevel,
		]);
	}

	private function enter(string $sUser): void
	{
		RequestScope::Close();
		UserRights::Login($this->aUsers[$sUser]['login']);
		RequestScope::EnterPortal(self::PORTAL);
		PortalPolicy::Forget();
	}

	private function exclude(mixed $aClasses, mixed $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		ExclusionPolicy::Forget();
		PortalPolicy::Forget();
	}

	/** @return array<string, mixed> */
	private function counted(array $aArguments): array
	{
		$aArguments += ['class' => '', 'filters' => [], 'oql' => '', 'group_by' => [], 'archived' => 'exclude', 'list' => self::ONGOING, 'tab' => '', 'parent_id' => 0];

		return ToolOutput::Decode(ObjectCount::execute(...$aArguments));
	}

	/**
	 * @param array<string, mixed> $aAnswer
	 *
	 * @return array<string, array<string, int>> Class => value of the column => count.
	 */
	private static function byClass(array $aAnswer, string $sAttCode): array
	{
		$aOut = [];
		foreach ($aAnswer['groups'] as $aGroup) {
			$aOut[$aGroup['class']][(string)$aGroup['value'][$sAttCode]] = $aGroup['count'];
		}
		ksort($aOut);

		return $aOut;
	}

	/** The concrete kind of change this iTop has: simple change management has Change itself, the ITIL options its subclasses. */
	private static function changeClass(): string
	{
		foreach (['RoutineChange', 'Change'] as $sClass) {
			if (MetaModel::IsValidClass($sClass) && !MetaModel::IsAbstract($sClass)) {
				return $sClass;
			}
		}

		return '';
	}

	private function listTotal(): int
	{
		return (int)ToolOutput::Decode(PortalList::execute(self::ONGOING))['total'];
	}

	/**
	 * What a count per status must say of the first user's tickets, by class.
	 *
	 * @param array<int, string> $aWithout Classes the count leaves out.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function expectedStatus(array $aWithout = []): array
	{
		$aOut = [];
		foreach ($this->aPlan as $sClass => $aHeld) {
			if (!in_array($sClass, $aWithout, true)) {
				$aOut[$sClass] = ['new' => $aHeld['n']];
			}
		}
		ksort($aOut);

		return $aOut;
	}

	/**
	 * @param array<int, string> $aWithout
	 *
	 * @return array<string, array<string, int>>
	 */
	private function expectedPriority(array $aWithout = []): array
	{
		$aOut = [];
		foreach ($this->aPlan as $sClass => $aHeld) {
			if (!in_array($sClass, $aWithout, true)) {
				$aOut[$sClass] = ['1' => $aHeld['urgent'], '3' => $aHeld['n'] - $aHeld['urgent']];
			}
		}
		ksort($aOut);

		return $aOut;
	}

	/** @param array<int, string> $aWithout */
	private function expectedTotal(array $aWithout = []): int
	{
		return array_sum(array_map(fn (string $sClass): int => $this->aPlan[$sClass]['n'], array_diff(array_keys($this->aPlan), $aWithout)));
	}

	private function requireIncident(): void
	{
		if (!isset($this->aPlan['Incident'])) {
			$this->markTestSkipped('Needs a second kind of ticket the stock portal shows: Incident, of the ITIL ticket options, which the CI install (simple ticket management) lacks.');
		}
	}

	public function testStatusIsCountedPerClassAndNeverMergedAcrossThem(): void
	{
		$aCount = $this->counted(['group_by' => ['status']]);

		// The lifecycles of the two classes share the code "new": two groups, one per class.
		$this->assertSame($this->expectedStatus(), self::byClass($aCount, 'status'));
		$this->assertSame('Ticket', $aCount['class']);
		$this->assertSame(self::ONGOING, $aCount['list']);
		$this->assertFalse($aCount['truncated']);
		foreach ($aCount['groups'] as $aGroup) {
			$this->assertSame(['class', 'value', 'label', 'count'], array_keys($aGroup));
			$this->assertNotSame('', (string)$aGroup['label']['status'], 'the label iTop gives the state');
		}
		$this->assertArrayHasKey('untrusted_text', $aCount);
	}

	public function testPriorityIsCountedPerClass(): void
	{
		$aCount = $this->counted(['group_by' => ['priority']]);

		$this->assertSame($this->expectedPriority(), self::byClass($aCount, 'priority'));
	}

	public function testTheTotalIsTheListsOwnAndTheGroupsAddUpToIt(): void
	{
		foreach ([['status'], ['priority'], ['status', 'priority'], ['caller_id', 'status']] as $aGroupBy) {
			$aCount = $this->counted(['group_by' => $aGroupBy]);

			$this->assertSame($this->listTotal(), $aCount['total'], 'the count of '.implode(', ', $aGroupBy).' and the list read one query');
			$this->assertSame($this->expectedTotal(), $aCount['total'], 'the first user\'s tickets and not the second user\'s');
			$this->assertSame($aCount['total'], array_sum(array_column($aCount['groups'], 'count')), implode(', ', $aGroupBy));
		}
	}

	public function testTwoColumnsOfTheKindsAndOneOfTheListsClassWork(): void
	{
		$aCount = $this->counted(['group_by' => ['status', 'priority']]);
		$this->assertCount(2 * count($this->aPlan), $aCount['groups'], 'each class, urgent and calm');
		foreach ($aCount['groups'] as $aGroup) {
			$this->assertSame(['status', 'priority'], array_keys($aGroup['value']));
		}

		// caller_id is a column of Ticket itself, status a column of its kinds.
		$aMixed = $this->counted(['group_by' => ['caller_id', 'status']]);
		$this->assertCount(count($this->aPlan), $aMixed['groups'], 'one caller, each class');
		foreach ($aMixed['groups'] as $aGroup) {
			$this->assertSame($this->aUsers['first']['contact'], $aGroup['value']['caller_id']);
			$this->assertSame(['class', 'value', 'label', 'count'], array_keys($aGroup));
		}
	}

	public function testAListWhoseClassHasTheColumnIsAnsweredAsBefore(): void
	{
		$aCount = $this->counted(['group_by' => ['caller_id']]);

		$this->assertSame(['list', 'class', 'total', 'groups', 'truncated', 'note', 'untrusted_text'], array_keys($aCount));
		$this->assertSame($this->expectedTotal(), $aCount['total']);
		$this->assertCount(1, $aCount['groups']);
		$this->assertSame(['value', 'label', 'count'], array_keys($aCount['groups'][0]), 'no class key: one class counted, as before');
		$this->assertSame($this->aUsers['first']['contact'], $aCount['groups'][0]['value']['caller_id']);

		$aTotal = $this->counted([]);
		$this->assertSame(['list', 'class', 'total', 'groups', 'truncated', 'note'], array_keys($aTotal));
		$this->assertSame($this->expectedTotal(), $aTotal['total']);
	}

	public function testASecondUserCountsTheirOwnTicketsAndNotTheFirstUsers(): void
	{
		$this->enter('second');

		$aCount = $this->counted(['group_by' => ['status']]);

		$this->assertSame(array_map(static fn (array $a): array => ['new' => 1], $this->aPlan), self::byClass($aCount, 'status'));
		$this->assertSame(count($this->aPlan), $aCount['total']);
		$this->assertSame($this->listTotal(), $aCount['total']);
	}

	public function testAClassTheOperatorExcludedIsAbsentAndNotInTheTotal(): void
	{
		$this->requireIncident();
		$this->exclude(['Incident'], []);

		$aCount = $this->counted(['group_by' => ['status']]);

		$this->assertSame($this->expectedStatus(['Incident']), self::byClass($aCount, 'status'));
		$this->assertSame($this->expectedTotal(['Incident']), $aCount['total'], 'the total is the list\'s, which has no incident in it');
		$this->assertSame($this->listTotal(), $aCount['total']);
		$this->assertStringNotContainsString('Incident', json_encode($aCount), 'not named anywhere, the note included');
	}

	public function testAClassWhoseColumnIsExcludedIsLeftOutWithoutAWord(): void
	{
		$this->requireIncident();
		$this->exclude([], ['Incident' => ['priority']]);

		$aCount = $this->counted(['group_by' => ['priority']]);

		$this->assertSame($this->expectedPriority(['Incident']), self::byClass($aCount, 'priority'));
		$this->assertSame($this->expectedTotal(['Incident']), $aCount['total'], 'the total is of what was counted');
		$this->assertStringNotContainsString('Incident', json_encode($aCount));
		$this->assertStringContainsString('can be less than the list', $aCount['note'], 'said whatever was left out, or not');

		$aStatus = $this->counted(['group_by' => ['status']]);
		$this->assertSame($this->expectedStatus(), self::byClass($aStatus, 'status'), 'the other column is not touched');
		$this->assertSame($aCount['note'], $aStatus['note'], 'one note, whether or not a class was left out');
	}

	public function testAColumnNoClassCanBeCountedByIsRefusedAsAbsent(): void
	{
		$this->exclude([], ['UserRequest' => ['priority'], 'Incident' => ['priority']]);
		if (!isset($this->aPlan['Incident'])) {
			$this->exclude([], ['UserRequest' => ['priority']]);
		}

		try {
			$this->counted(['group_by' => ['priority']]);
			$this->fail('a column excluded on every class was counted');
		} catch (ToolCallException $oException) {
			$this->assertStringStartsWith("Unknown attribute 'priority' on list '".self::ONGOING."'", $oException->getMessage());
		}
	}

	public function testAColumnTheListHidesIsStillRefusedAloneAndBesideOneItShows(): void
	{
		foreach ([['agent_id'], ['description'], ['private_log'], ['org_id'], ['status', 'agent_id'], ['agent_id', 'status'], ['status', 'description']] as $aGroupBy) {
			try {
				$this->counted(['group_by' => $aGroupBy]);
				$this->fail('counted per '.implode(', ', $aGroupBy));
			} catch (ToolCallException $oException) {
				$this->assertStringStartsWith("Unknown attribute '", $oException->getMessage(), implode(', ', $aGroupBy));
				$this->assertStringNotContainsString('subclass', $oException->getMessage(), 'a hidden column is not a subclass\'s');
			}
		}
	}

	public function testTwoColumnsThatNotEveryKindHasAreRefusedInPlainWords(): void
	{
		$this->requireIncident();
		if (!MetaModel::IsValidAttCode('UserRequest', 'user_satisfaction') || (MetaModel::IsValidClass('Incident') && MetaModel::IsValidAttCode('Incident', 'user_satisfaction'))) {
			$this->markTestSkipped('Needs a column of requests that incidents lack.');
		}
		$oPolicy = PortalPolicy::ForCurrentRequest();
		$oKinds = new \ReflectionMethod(ObjectCount::class, 'kindsToCount');

		try {
			$oKinds->invoke(null, $oPolicy, self::ONGOING, 'Ticket', ['status', 'user_satisfaction'], ['status', 'user_satisfaction']);
			$this->fail('a pair of columns with different kinds was counted');
		} catch (ToolCallException $oException) {
			$this->assertStringContainsString("Cannot count list '".self::ONGOING."' per 'status' and 'user_satisfaction' together", $oException->getMessage());
			$this->assertStringNotContainsString('UserRequest', $oException->getMessage(), 'no class is named');
		}

		$this->assertSame(['UserRequest'], $oKinds->invoke(null, $oPolicy, self::ONGOING, 'Ticket', ['user_satisfaction'], ['user_satisfaction']), 'alone, it is the one kind that has it');
	}

	public function testATabAndAParentNarrowTheCountAsTheyNarrowTheList(): void
	{
		$aTabbed = null;
		foreach (PortalPolicy::ForCurrentRequest()->Lists() as $aList) {
			if (!empty($aList['tabs']) && ($aList['class'] ?? '') === 'Ticket') {
				$aTabbed = $aList;
				break;
			}
		}
		if ($aTabbed === null) {
			$this->markTestSkipped('The stock portal has no ticket list with tabs on this iTop.');
		}

		foreach (array_column($aTabbed['tabs'], 'id') as $sTab) {
			$aRows = ToolOutput::Decode(PortalList::execute((string)$aTabbed['list'], (string)$sTab));
			$aCount = $this->counted(['list' => (string)$aTabbed['list'], 'tab' => (string)$sTab, 'group_by' => ['status']]);

			$this->assertSame($aRows['total'], $aCount['total'], 'tab '.$sTab);
			$this->assertSame($aCount['total'], array_sum(array_column($aCount['groups'], 'count')), 'tab '.$sTab);
		}

		// A parent_id on a list that has no parent is refused as the list refuses it, not counted wider.
		try {
			$this->counted(['parent_id' => 1, 'group_by' => ['status']]);
			$this->fail('parent_id on a list with no parent');
		} catch (ToolCallException $oException) {
			$this->assertStringContainsString('parent_id narrows a browse level', $oException->getMessage());
		}
	}

	public function testACountNeverNamesAClassTheListShowsNoRowOf(): void
	{
		$aCount = $this->counted(['group_by' => ['status', 'priority']]);

		foreach ($aCount['groups'] as $aGroup) {
			$this->assertContains($aGroup['class'], ['UserRequest', 'Incident'], 'the stock scope lets a portal user see these two and nothing else');
		}
		$this->assertStringNotContainsString('Change', json_encode($aCount));
		$this->assertStringNotContainsString('Problem', json_encode($aCount));
	}

	/**
	 * The merge of kinds, on two classes that the stock portal's scope never lets a user see together: the same code in
	 * two lifecycles stays two groups, the groups are ordered by size across the classes, and the total is their sum.
	 * Asked of the method with a search of every ticket of these tests, as the list would hand it.
	 */
	public function testTheKindsAreMergedBySizeAndNeverByCode(): void
	{
		if (self::changeClass() === '') {
			$this->markTestSkipped('Needs change management.');
		}
		$oSearch = DBObjectSearch::FromOQL_AllData("SELECT Ticket WHERE ref LIKE 'PerClass_{$this->sSuffix}%'");
		$oAnswer = new \ReflectionMethod(ObjectCount::class, 'answerPerClass');

		$aCount = ToolOutput::Decode($oAnswer->invoke(null, PortalPolicy::ForCurrentRequest(), 'x', 'Ticket', $oSearch, ['status'], [self::changeClass(), 'UserRequest']));

		$this->assertSame([self::changeClass() => ['new' => 2], 'UserRequest' => ['new' => $this->aPlan['UserRequest']['n'] + 1]], self::byClass($aCount, 'status'), 'one group for each class');
		$this->assertSame(['UserRequest', self::changeClass()], array_column($aCount['groups'], 'class'), 'largest first, across classes');
		$this->assertSame($aCount['total'], array_sum(array_column($aCount['groups'], 'count')));
		$this->assertFalse($aCount['truncated']);
	}

	/** The limit is the answer's: sixty groups in each of two classes are cut to a hundred together, and the answer says so. */
	public function testTheGroupLimitIsTheWholeAnswersNotEachClasss(): void
	{
		if (self::changeClass() === '') {
			$this->markTestSkipped('Needs change management.');
		}
		$sOther = self::changeClass();
		RequestScope::Close();
		UserRights::Login('admin');
		foreach (['UserRequest', $sOther] as $sClass) {
			for ($i = 0; $i < 60; $i++) {
				$this->createObject($sClass, ['ref' => 'PerClassMany_'.$this->sSuffix.'_'.$sClass.'_'.$i, 'title' => 'many', 'description' => 'many', 'org_id' => $this->iOrg]);
			}
		}
		$this->enter('first');
		$oSearch = DBObjectSearch::FromOQL_AllData("SELECT Ticket WHERE ref LIKE 'PerClassMany_{$this->sSuffix}%'");
		$oAnswer = new \ReflectionMethod(ObjectCount::class, 'answerPerClass');

		$aCount = ToolOutput::Decode($oAnswer->invoke(null, PortalPolicy::ForCurrentRequest(), 'x', 'Ticket', $oSearch, ['ref'], [$sOther, 'UserRequest']));

		$this->assertSame(120, $aCount['total'], 'the total is of everything counted, not of what is listed');
		$this->assertCount(ObjectCount::MAX_GROUPS, $aCount['groups']);
		$this->assertTrue($aCount['truncated']);
		$this->assertStringContainsString((string)ObjectCount::MAX_GROUPS, $aCount['note']);
	}
}
