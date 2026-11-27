<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Review of 2026-11-23, B4: the answer to a real write took `changes` before the write, so what the write itself
 * filled (a ticket's ref, a transition's state) read empty. `after.applied` is read back once the write is done and
 * holds what the write changed as well as what was sent.
 *
 * @group integration
 */
class AfterWriteReadBackTest extends ItopDataTestCaseAlias
{
	private function org(): int
	{
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}

		return (int)$this->CreateOrganization('ReadBack '.bin2hex(random_bytes(4)))->GetKey();
	}

	public function testARealCreateReadsBackWhatTheInsertFilled(): void
	{
		$iOrg = $this->org();
		$oCaller = $this->CreatePerson(1, $iOrg);

		$aDry = self::plain(ToolOutput::Decode(ObjectCreate::execute('UserRequest', ['org_id' => $iOrg, 'caller_id' => (int)$oCaller->GetKey(), 'title' => 'T', 'description' => 'D'], true)));
		$aReal = self::plain(ToolOutput::Decode(ObjectCreate::execute('UserRequest', ['org_id' => $iOrg, 'caller_id' => (int)$oCaller->GetKey(), 'title' => 'T', 'description' => 'D'], false)));

		$this->assertArrayNotHasKey('ref', $aDry['after']['applied'], 'a dry run has no row to read back');
		$this->assertNotSame('', (string)($aReal['after']['applied']['ref'] ?? ''), 'the ref the insert gave is not in the answer');
		$this->assertNotSame('', (string)($aReal['after']['applied']['start_date'] ?? ''), 'the start date the lifecycle filled is not in the answer');
		$this->assertSame('', (string)$aReal['changes']['ref'], 'changes is what it was before the write, and the schema says so');
	}

	public function testARealTransitionReadsBackTheStateItMoved(): void
	{
		$iOrg = $this->org();
		$iId = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();
		$sStimulus = isset(MetaModel::EnumTransitions('UserRequest', 'new')['ev_timeout']) ? 'ev_timeout' : null;
		if ($sStimulus === null) {
			$this->markTestSkipped('Needs a transition from new that asks for nothing.');
		}

		$aReal = self::plain(ToolOutput::Decode(ObjectApplyStimulus::execute('UserRequest', $iId, $sStimulus, [], false)));

		$this->assertSame($aReal['state'], $aReal['after']['applied']['status'] ?? null, 'the state the transition moved to is not read back');
	}

	/** Retest of 2026-11-27: the dry run listed {approver_id, status}, the real run only {approver_id}, and still said would_move_to. */
	public function testARealTransitionListsTheStateAmongItsChangesAsTheDryRunDoes(): void
	{
		$iOrg = $this->org();
		$iId = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();
		if (!isset(MetaModel::EnumTransitions('UserRequest', 'new')['ev_timeout'])) {
			$this->markTestSkipped('Needs a transition from new that asks for nothing.');
		}
		$aFields = ['title' => 'Moved with a field'];

		$aDry = self::plain(ToolOutput::Decode(ObjectApplyStimulus::execute('UserRequest', $iId, 'ev_timeout', $aFields, true)));
		$aReal = self::plain(ToolOutput::Decode(ObjectApplyStimulus::execute('UserRequest', $iId, 'ev_timeout', $aFields, false)));

		$this->assertSame($aDry['would_move_to'], $aReal['state'], 'the object is where the dry run said');
		$this->assertSame($aReal['state'], $aReal['changes']['status'] ?? null, 'the state is not among the real run\'s changes');
		$this->assertSame($aDry['changes']['status'], $aReal['changes']['status']);
		$this->assertSame(array_keys($aDry['changes']), array_keys($aReal['changes']), 'the dry run and the real one name different changes');
		$this->assertContains('status', $aReal['defaulted'], 'a change the caller did not send');

		$this->assertArrayHasKey('would_move_to', $aReal, 'the key stays: one shape for both calls');
		$this->assertNull($aReal['would_move_to'], 'nothing would move any more');
		$this->assertNotNull($aDry['would_move_to']);
	}

	/** @return array<string, mixed> The answer with `{}` as an array, so that it can be indexed. */
	private static function plain(mixed $aAnswer): array
	{
		return json_decode((string)json_encode($aAnswer), true);
	}
}
