<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBSearch;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-12: on a portal that serves history, `total` counted the rows it then dropped for attributes the portal's
 * form hides - so paging showed how many times staff had touched a field the user is not shown - and a changed key (`agent_id`)
 * gave a raw id where the portal shows a name. Asked at the service the tool calls, with the closures the portal gives it.
 */
class PortalHistoryTest extends ItopDataTestCaseAlias
{
	private int $iTicket;
	private int $iAgent;
	private string $sAgentName;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidAttCode('UserRequest', 'agent_id')) {
			$this->markTestSkipped('Needs UserRequest.agent_id.');
		}
		$iOrg = (int)$this->CreateOrganization('PortalHistory '.bin2hex(random_bytes(4)))->GetKey();
		$oCaller = $this->CreatePerson(1, $iOrg);
		$oAgent = $this->CreatePerson(2, $iOrg);
		$this->iAgent = (int)$oAgent->GetKey();
		$this->sAgentName = (string)$oAgent->GetName();
		$oTicket = $this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $oCaller->GetKey(), 'title' => 'first']);
		$this->iTicket = (int)$oTicket->GetKey();
		foreach (['second', 'third', 'fourth'] as $sTitle) {
			$oTicket->Set('title', $sTitle);
			$oTicket->DBUpdate();
		}
		$oTicket->Set('agent_id', $this->iAgent);
		$oTicket->DBUpdate();
	}

	/** @return array<string, mixed> */
	private function history(?\Closure $fNameOf): array
	{
		$oTicket = MetaModel::GetObject('UserRequest', $this->iTicket);

		return ObjectHistory::Within(
			$oTicket,
			static fn (DBObjectSearch $oSearch): ?DBSearch => $oSearch,
			static fn (string $sAttCode): bool => $sAttCode !== 'title', // the portal's form shows no title
			'',
			50,
			0,
			$fNameOf
		);
	}

	public function testTheTotalCountsOnlyTheEntriesThatAreReturned(): void
	{
		$aHistory = $this->history(null);

		$this->assertNotSame([], $aHistory['entries']);
		$this->assertSame(count($aHistory['entries']), $aHistory['total'], 'the rows about the hidden title are not counted either');
		foreach ($aHistory['entries'] as $aEntry) {
			$this->assertNotSame('title', $aEntry['attribute']);
		}
	}

	public function testAChangedKeyCarriesTheNameTheUserIsShown(): void
	{
		$aHistory = $this->history(fn (string $sTarget, int $iId): ?string => $sTarget === 'Contact' || $sTarget === 'Person' ? (string)MetaModel::GetObject($sTarget, $iId)->GetName() : null);

		$aRows = array_values(array_filter($aHistory['entries'], static fn (array $a): bool => $a['attribute'] === 'agent_id'));
		$this->assertCount(1, $aRows);
		$this->assertSame((string)$this->iAgent, $aRows[0]['to'], 'the id stays');
		$this->assertSame($this->sAgentName, $aRows[0]['to_name']);
	}

	public function testNoNameIsGivenWhereThePortalGaveNoWayToSayIt(): void
	{
		$aRows = array_values(array_filter($this->history(null)['entries'], static fn (array $a): bool => $a['attribute'] === 'agent_id'));

		$this->assertArrayNotHasKey('to_name', $aRows[0]);
	}

	public function testANameTheUserIsNotShownIsNull(): void
	{
		$aRows = array_values(array_filter($this->history(static fn (): ?string => null)['entries'], static fn (array $a): bool => $a['attribute'] === 'agent_id'));

		$this->assertNull($aRows[0]['to_name']);
	}

	/** Review 2026-11-23, C16: a portal user is not given the change's key or its origin, and no tool or reason without a way to say. */
	public function testAPortalUserIsGivenNeitherTheChangeKeyNorItsOrigin(): void
	{
		\Altioo\iTop\Extension\MCP\Helper\PortalMode::Remember('itop-portal');
		try {
			$aHistory = $this->history(null);
		} finally {
			\Altioo\iTop\Extension\MCP\Helper\PortalMode::Forget();
		}

		$this->assertNotSame([], $aHistory['entries']);
		foreach ($aHistory['entries'] as $aEntry) {
			$this->assertNull($aEntry['change_id']);
			$this->assertNull($aEntry['origin']);
			$this->assertArrayNotHasKey('tool', $aEntry);
			$this->assertArrayNotHasKey('comment', $aEntry);
			$this->assertArrayNotHasKey('_change', $aEntry);
		}
		$this->assertArrayHasKey('has_more', $aHistory);
	}
}
