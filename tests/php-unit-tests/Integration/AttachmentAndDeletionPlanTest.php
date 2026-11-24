<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use Mcp\Server\Exception\ToolCallException;
use UserRights;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Review of 2026-11-23, T4: deleting an attachment as a non-administrator (B8, S9), the classes a delete plan names
 * (B7), and attaching to a class the attachments module does not serve (B9).
 *
 * @group integration
 */
class AttachmentAndDeletionPlanTest extends ItopDataTestCaseAlias
{
	protected function tearDown(): void
	{
		UserRights::Deimpersonate();
		parent::tearDown();
	}

	private function aTicket(): int
	{
		if (!MetaModel::IsValidClass('UserRequest') || !MetaModel::IsValidClass('Attachment')) {
			$this->markTestSkipped('Needs the ITIL request management and attachments modules.');
		}
		$iOrg = (int)$this->CreateOrganization('AttDel '.bin2hex(random_bytes(4)))->GetKey();

		return (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();
	}

	private function attachTo(int $iTicket, string $sName): int
	{
		$aDone = ToolOutput::Decode(ObjectAttach::execute('UserRequest', $iTicket, $sName, base64_encode("notes\n"), 'text/plain', null, false, 'test'));

		return (int)$aDone['id'];
	}

	private function loginAs(string $sProfile): void
	{
		$sLogin = 'att-del-'.bin2hex(random_bytes(3));
		$oProfile = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :n'), [], ['n' => $sProfile]))->Fetch();
		if ($oProfile === null) {
			$this->markTestSkipped("No profile {$sProfile}.");
		}
		$this->CreateUser($sLogin, (int)$oProfile->GetKey(), 'Att-Del-'.bin2hex(random_bytes(3)).'-1!', $this->CreatePerson(2, (int)$this->CreateOrganization('AttDel user '.bin2hex(random_bytes(3)))->GetKey())->GetKey());
		UserRights::Login($sLogin);
	}

	/**
	 * B8: iTop deletes the "attachment added" row with the attachment, silently, and the console asks nothing about it.
	 */
	public function testANonAdministratorWhoMayDeleteTheAttachmentDeletesIt(): void
	{
		$iTicket = $this->aTicket();
		$iAttachment = $this->attachTo($iTicket, 'gone.txt');
		$this->loginAs('Support Agent');
		if (!UserRights::IsActionAllowed('Attachment', UR_ACTION_DELETE)) {
			$this->markTestSkipped('This profile may not delete attachments here.');
		}
		$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('CMDBChangeOpAttachmentAdded', UR_ACTION_READ), 'the premise: this user reads no change log, or the test proves nothing');

		$aPlan = ToolOutput::Decode(ObjectDelete::execute('Attachment', $iAttachment, true));
		$this->assertTrue($aPlan['simulated']);
		$this->assertTrue($aPlan['valid']);

		$aDone = ToolOutput::Decode(ObjectDelete::execute('Attachment', $iAttachment, false));
		$this->assertFalse($aDone['simulated']);
		$this->assertNull(MetaModel::GetObject('Attachment', $iAttachment, false), 'the attachment is still there');
	}

	/**
	 * B8, red pass over the check it loosens: only the bookkeeping row of the attachment being deleted, planned
	 * silently, is not judged. Anything else a non-administrator may not read still stops the delete, whichever
	 * way it is asked.
	 *
	 * @dataProvider planProvider
	 */
	public function testTheExemptionIsNarrow(string $sCase, bool $bRefused): void
	{
		$iTicket = $this->aTicket();
		$iMine = $this->attachTo($iTicket, 'mine.txt');
		$iOther = $this->attachTo($iTicket, 'other.txt');
		$oMyRow = $this->bookkeepingOf($iMine);
		$oOtherRow = $this->bookkeepingOf($iOther);
		$this->loginAs('Support Agent');
		$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('CMDBChangeOpAttachmentAdded', UR_ACTION_READ), 'the premise: this user reads no change log');

		$oPlan = new \DeletionPlan();
		$oKey = MetaModel::GetAttributeDef('CMDBChangeOpAttachmentAdded', 'attachment_id');
		[$sRootClass, $aRows] = match ($sCase) {
			'its own row'                => ['Attachment', [$oMyRow]],
			'another attachment\'s row'  => ['Attachment', [$oMyRow, $oOtherRow]],
			'a ticket\'s cascade'        => ['UserRequest', [$oMyRow]],
			'its own row, to be deleted' => ['Attachment', []],
		};
		foreach ($aRows as $oRow) {
			$oPlan->AddToUpdate($oRow, $oKey);
		}
		if ($sCase === 'its own row, to be deleted') {
			$oPlan->AddToDelete($oMyRow, DEL_SILENT);
		}

		try {
			\Altioo\iTop\Extension\MCP\Service\Write\DeletionCascade::CheckDeletionRights($oPlan, "{$sRootClass}::{$iMine}", $sRootClass, $iMine);
			$this->assertFalse($bRefused, $sCase.': the plan went through');
		} catch (ToolCallException $e) {
			$this->assertTrue($bRefused, $sCase.': refused although it is the attachment\'s own bookkeeping: '.$e->getMessage());
			$this->assertStringContainsString('It also reaches related objects this user may not read.', $e->getMessage(), 'S9: a sentence starts with a capital');
		}
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function planProvider(): array
	{
		return [
			'its own row'                  => ['its own row', false],
			'another attachment\'s row'    => ['another attachment\'s row', true],
			'a ticket\'s cascade'          => ['a ticket\'s cascade', true],
			'its own row, to be deleted'   => ['its own row, to be deleted', true],
		];
	}

	private function bookkeepingOf(int $iAttachment): \DBObject
	{
		$oRow = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT CMDBChangeOpAttachmentAdded WHERE attachment_id = :id'), [], ['id' => $iAttachment]))->Fetch();
		$this->assertNotNull($oRow, 'no "attachment added" row to build the plan from');

		return $oRow;
	}

	/**
	 * B11: the change an attach leaves is summarised as the host object attached to.
	 */
	public function testTheChangeOfAnAttachNamesTheHostObject(): void
	{
		$iTicket = $this->aTicket();
		$iAttachment = $this->attachTo($iTicket, 'summary.txt');
		$oOp = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT CMDBChangeOpAttachmentAdded WHERE attachment_id = :id'), [], ['id' => $iAttachment]))->Fetch();

		$this->assertSame("UserRequest::{$iTicket} attached", \Altioo\iTop\Extension\MCP\Service\Audit\ChangeSummary::OfChange((int)$oOp->Get('change')));
	}

	/**
	 * S8: iTop's refusal of a file an image attribute does not take quotes the start of the file; the answer does not.
	 */
	public function testARefusedFilesBytesAreNotEchoed(): void
	{
		$iOrg = (int)$this->CreateOrganization('AttPic '.bin2hex(random_bytes(4)))->GetKey();
		$iPerson = (int)$this->CreatePerson(1, $iOrg)->GetKey();

		try {
			ObjectAttach::execute('Person', $iPerson, 'x.txt', base64_encode('SECRET-BYTES '.str_repeat('A', 300)."\x00\x01"), 'text/plain', 'picture');
			$this->fail('a text file was accepted as a picture');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('Wrong format', $e->getMessage(), 'the reason is still given');
			$this->assertStringNotContainsString('SECRET-BYTES', $e->getMessage());
			$this->assertStringNotContainsString("\x00", $e->getMessage());
		}
	}

	/**
	 * B7: every entry of a plan names its class, and the object itself is not "besides".
	 */
	public function testADeletePlanNamesTheClassOfEveryEntryAndLeavesOutTheObjectItself(): void
	{
		$iTicket = $this->aTicket();
		$iAttachment = $this->attachTo($iTicket, 'plan.txt');

		$aPlan = ToolOutput::Decode(ObjectDelete::execute('Attachment', $iAttachment, true))['deletionPlan'];

		foreach (array_merge($aPlan['deleted'], $aPlan['updated']) as $aEntry) {
			$this->assertArrayHasKey('class', $aEntry, 'an entry with no class: '.json_encode($aEntry));
			$this->assertFalse($aEntry['class'] === 'Attachment' && (int)$aEntry['id'] === $iAttachment, 'the object being deleted is listed as what happens besides it');
		}
		$aClasses = array_column($aPlan['updated'], 'class');
		$this->assertContains('CMDBChangeOpAttachmentAdded', $aClasses, 'the bookkeeping row is part of the plan, and shown');
	}

	/**
	 * B9: a file attached to a class the module does not serve would be seen by no console user.
	 */
	public function testAttachingToAClassOutsideAllowedClassesIsRefused(): void
	{
		$this->aTicket();
		$iOrg = (int)$this->CreateOrganization('AttGate '.bin2hex(random_bytes(4)))->GetKey();
		if (!method_exists('AttachmentPlugIn', 'IsAttachmentAllowedForObject')) {
			$this->markTestSkipped('This iTop has no IsAttachmentAllowedForObject() (before 3.2.1).');
		}

		try {
			ObjectAttach::execute('Organization', $iOrg, 'x.txt', base64_encode('x'), 'text/plain');
			$this->fail('a file was accepted for an Organization');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('Attachments are not enabled on class', $e->getMessage());
		}
	}
}
