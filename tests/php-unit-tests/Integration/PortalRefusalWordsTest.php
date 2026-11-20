<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Write\EditPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-13: a portal user's refusal quoted the datamodel's own OQL ("The datamodel limits it to: SELECT Team AS t
 * JOIN lnkDeliveryModelToContact ..."), staff vocabulary an end user cannot act on. The words are plain there and the query is in the
 * log, as ADR 0007 does for a portal that is refused. The console keeps the query: its reader can use it.
 * (The size refusals are held in PortalAttachmentsTest.)
 */
class PortalRefusalWordsTest extends ItopDataTestCaseAlias
{
	private function ticketWithATeamTheKeyDoesNotAccept(): \DBObject
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'team_id') || !MetaModel::IsValidClass('Team')) {
			$this->markTestSkipped('Needs UserRequest.team_id and Team.');
		}
		$iOrg = (int)$this->CreateOrganization('RefusalWords '.bin2hex(random_bytes(4)))->GetKey();
		$iCaller = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$oForeignOrg = $this->CreateOrganization('RefusalWords other '.bin2hex(random_bytes(4)));
		$oTeam = MetaModel::NewObject('Team');
		$oTeam->Set('name', 'RefusalWords team '.bin2hex(random_bytes(3)));
		$oTeam->Set('org_id', $oForeignOrg->GetKey());
		$oTeam->DBInsert();

		$oTicket = $this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $iCaller]);
		$oTicket->Set('team_id', $oTeam->GetKey());

		return $oTicket;
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	public function testThePortalsRefusalOfAKeyValueDoesNotQuoteTheDatamodelsQuery(): void
	{
		$oTicket = $this->ticketWithATeamTheKeyDoesNotAccept();
		$aConsole = EditPolicy::RefusalsForExternalKeys($oTicket, ['team_id']);
		if (!isset($aConsole['team_id'])) {
			$this->markTestSkipped('This datamodel accepts any team on a request: nothing to refuse.');
		}
		$this->assertStringContainsString('The datamodel limits it to: SELECT', $aConsole['team_id'], 'the console keeps the query');

		RequestScope::EnterPortal('itop-portal');
		$aPortal = EditPolicy::RefusalsForExternalKeys($oTicket, ['team_id']);

		$this->assertArrayHasKey('team_id', $aPortal);
		$this->assertStringContainsString("is not a value 'team_id' accepts on this UserRequest", $aPortal['team_id']);
		$this->assertStringNotContainsString('SELECT', $aPortal['team_id']);
		$this->assertStringNotContainsString('datamodel', $aPortal['team_id']);
	}
}
