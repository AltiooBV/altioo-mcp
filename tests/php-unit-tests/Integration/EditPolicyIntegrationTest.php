<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\EditPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObject;
use DBObjectSet;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a write may change, against a real datamodel: doc/adr/0004.
 *
 * Every rule here reads the compiled lifecycle - flags per state, what a
 * transition prompts for, a link set's edit_mode, an external key's filter -
 * so none of it can be unit tested. The facts each test leans on are the
 * stock ITIL request management's: a UserRequest starts in `new`, which hides
 * team_id and agent_id and makes priority read-only; ev_assign prompts for
 * both; agent_id is limited to the members of :this->team_id; tto and ttr are
 * stopwatches. A test whose fact is missing skips rather than passes.
 */
class EditPolicyIntegrationTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();

		if (!MetaModel::IsValidClass('UserRequest') || !MetaModel::IsValidAttCode('UserRequest', 'tto')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}

		$this->iOrg = (int) $this->CreateOrganization('EditPolicyTest '.bin2hex(random_bytes(4)))->GetKey();
	}

	public function testANewTicketStartsInItsInitialState(): void
	{
		$sRefusal = EditPolicy::RefusalFor(MetaModel::NewObject('UserRequest'), 'status', 'closed');

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('initial state', $sRefusal);
		$this->assertStringContainsString('core_object_apply_stimulus', $sRefusal);
	}

	public function testATicketChangesStateOnlyThroughAStimulus(): void
	{
		$sRefusal = EditPolicy::RefusalFor($this->ticket(), 'status', 'resolved');

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('core_object_apply_stimulus', $sRefusal);
	}

	/**
	 * Hidden and read-only are both outside the form; an ordinary field is not.
	 */
	public function testWhatTheStateLocksIsRefusedAndTheRestIsNot(): void
	{
		$oTicket = $this->ticket();
		$this->assertLockedInNew('agent_id', OPT_ATT_HIDDEN);
		$this->assertLockedInNew('priority', OPT_ATT_READONLY);

		$this->assertStringContainsString("state 'new'", (string) EditPolicy::RefusalFor($oTicket, 'agent_id', 1));
		$this->assertNotNull(EditPolicy::RefusalFor($oTicket, 'priority', '1'));
		$this->assertNull(EditPolicy::RefusalFor($oTicket, 'title', 'A new title'));
	}

	/**
	 * A creation is held to the initial state, so it cannot set what an
	 * update of the same new ticket could not.
	 */
	public function testACreationIsHeldToTheInitialState(): void
	{
		$this->assertLockedInNew('agent_id', OPT_ATT_HIDDEN);

		$sRefusal = EditPolicy::RefusalFor(MetaModel::NewObject('UserRequest'), 'agent_id', 1);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('when a UserRequest is created', $sRefusal);
		$this->assertNull(EditPolicy::RefusalFor(MetaModel::NewObject('UserRequest'), 'title', 'A title'));
	}

	/**
	 * agent_id is hidden in `new`, and ev_assign is how it gets set.
	 */
	public function testATransitionMayFillWhatItsFormPromptsFor(): void
	{
		$oTicket = $this->ticket();
		$aTransitions = MetaModel::EnumTransitions('UserRequest', 'new');
		if (!isset($aTransitions['ev_assign'], $aTransitions['ev_timeout'])) {
			$this->markTestSkipped('This datamodel has no ev_assign and ev_timeout from new.');
		}

		$this->assertNull(EditPolicy::RefusalFor($oTicket, 'agent_id', 1, 'ev_assign'));
		$this->assertNotNull(EditPolicy::RefusalFor($oTicket, 'agent_id', 1, 'ev_timeout'), 'a transition that does not prompt for it opens nothing');
		$this->assertNotNull(EditPolicy::RefusalFor($oTicket, 'status', 'assigned', 'ev_assign'), 'not even the transition writes the state by value');
	}

	public function testAStopwatchIsNeverWritten(): void
	{
		$sRefusal = EditPolicy::RefusalFor($this->ticket(), 'ttr', 0);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('stopwatch', $sRefusal);
	}

	public function testACaseLogTakesANewEntryAndNothingElse(): void
	{
		$oTicket = $this->ticket();

		$this->assertNull(EditPolicy::RefusalFor($oTicket, 'public_log', 'A reply'));
		$this->assertNull(EditPolicy::RefusalFor($oTicket, 'public_log', ['add_item' => ['message' => 'A reply']]));
		$this->assertNull(EditPolicy::RefusalFor($oTicket, 'public_log', ['add_item' => ['message' => 'A reply', 'format' => 'text']]));

		$this->assertNotNull(
			EditPolicy::RefusalFor($oTicket, 'public_log', ['add_item' => ['message' => 'A reply', 'date' => '2001-01-01 00:00:00']]),
			'a backdated entry'
		);
		$this->assertNotNull(
			EditPolicy::RefusalFor($oTicket, 'public_log', ['add_item' => ['message' => 'A reply', 'user_id' => 1, 'user_login' => 'admin']]),
			'an entry signed by somebody else'
		);
		$this->assertNotNull(
			EditPolicy::RefusalFor($oTicket, 'public_log', ['entries' => [['message' => 'The only entry left']]]),
			'a replacement of the whole log'
		);
	}

	/**
	 * Team::tickets_list has edit_mode none: the console never edits it from
	 * the team.
	 */
	public function testALinkSetTheHostNeverEditsIsRefused(): void
	{
		$oAttDef = MetaModel::GetAttributeDef('Team', 'tickets_list');
		if ($oAttDef->GetEditMode() !== LINKSET_EDITMODE_NONE) {
			$this->markTestSkipped('Team::tickets_list is editable on this datamodel.');
		}
		$oTicket = MetaModel::NewObject('UserRequest');
		$oTicket->Set('title', 'Added from the team');

		$sRefusal = EditPolicy::RefusalForLinkSet(
			MetaModel::NewObject('Team'),
			'tickets_list',
			DBObjectSet::FromArray($oAttDef->GetLinkedClass(), [$oTicket])
		);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('core_object_create', $sRefusal);
		$this->assertNull(
			EditPolicy::RefusalForLinkSet(MetaModel::NewObject('Team'), 'tickets_list', DBObjectSet::FromScratch($oAttDef->GetLinkedClass())),
			'a set that changes nothing is not a change'
		);
	}

	/**
	 * Location::person_list is add_only: emptying it would detach every
	 * person, which the console does not offer from the location.
	 */
	public function testAnAddOnlyLinkSetRefusesARemoval(): void
	{
		$oAttDef = MetaModel::GetAttributeDef('Location', 'person_list');
		if ($oAttDef->GetEditMode() !== LINKSET_EDITMODE_ADDONLY) {
			$this->markTestSkipped('Location::person_list is not add_only on this datamodel.');
		}
		$oLocation = $this->createObject('Location', ['name' => 'EditPolicyTest location', 'org_id' => $this->iOrg]);
		$this->createObject('Person', ['name' => 'Located', 'first_name' => 'Somebody', 'org_id' => $this->iOrg, 'location_id' => $oLocation->GetKey()]);
		$oLocation = MetaModel::GetObject('Location', $oLocation->GetKey());

		$sRefusal = EditPolicy::RefusalForLinkSet($oLocation, 'person_list', DBObjectSet::FromScratch('Person'));

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('only accepts additions', $sRefusal);
	}

	/**
	 * agent_id is limited to the members of the ticket's team, and the team
	 * is the one the same call sets.
	 *
	 * Only agent_id is judged: team_id has a filter of its own (the teams of
	 * the organisation's delivery model) that this team is not in, and
	 * refusing it is that rule working, not this one.
	 */
	public function testAnAgentOutsideTheTeamIsRefused(): void
	{
		$sFilter = MetaModel::GetAttributeDef('UserRequest', 'agent_id')->GetValuesDef()->GetFilterExpression();
		if (!str_contains($sFilter, ':this->team_id')) {
			$this->markTestSkipped('agent_id is not limited to the team on this datamodel.');
		}
		$oTeam = $this->createObject('Team', ['name' => 'EditPolicyTest team', 'org_id' => $this->iOrg]);
		$oMember = $this->createObject('Person', ['name' => 'Member', 'first_name' => 'Team', 'org_id' => $this->iOrg]);
		$oOutsider = $this->createObject('Person', ['name' => 'Outsider', 'first_name' => 'Not', 'org_id' => $this->iOrg]);
		$this->createObject('lnkPersonToTeam', ['person_id' => $oMember->GetKey(), 'team_id' => $oTeam->GetKey()]);

		$oTicket = $this->ticket();
		$oTicket->Set('team_id', $oTeam->GetKey());

		$oTicket->Set('agent_id', $oOutsider->GetKey());
		$aRefusals = EditPolicy::RefusalsForExternalKeys($oTicket, ['agent_id']);
		$this->assertArrayHasKey('agent_id', $aRefusals);
		$this->assertStringContainsString(':this->team_id', $aRefusals['agent_id'], 'the refusal says what the datamodel allows');

		$oTicket->Set('agent_id', $oMember->GetKey());
		$this->assertSame([], EditPolicy::RefusalsForExternalKeys($oTicket, ['agent_id']));
	}

	private function ticket(): DBObject
	{
		$oCaller = $this->CreatePerson(1, $this->iOrg);

		return $this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $oCaller->GetKey()]);
	}

	private function assertLockedInNew(string $sAttCode, int $iFlag): void
	{
		if ((MetaModel::GetAttributeFlags('UserRequest', 'new', $sAttCode) & $iFlag) === 0) {
			$this->markTestSkipped("UserRequest::{$sAttCode} is not flagged {$iFlag} in state new on this datamodel.");
		}
	}
}
