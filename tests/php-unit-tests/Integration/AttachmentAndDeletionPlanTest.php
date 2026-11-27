<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Combodo\iTop\Service\Events\EventService;
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
		UserRights::Login($this->userWith($sProfile));
	}

	/** A user holding one stock profile, made while still the administrator; the login is returned. */
	private function userWith(string $sProfile): string
	{
		$sLogin = 'att-del-'.bin2hex(random_bytes(3));
		$oProfile = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :n'), [], ['n' => $sProfile]))->Fetch();
		if ($oProfile === null) {
			$this->markTestSkipped("No profile {$sProfile}.");
		}
		$this->CreateUser($sLogin, (int)$oProfile->GetKey(), 'Att-Del-'.bin2hex(random_bytes(3)).'-1!', $this->CreatePerson(2, (int)$this->CreateOrganization('AttDel user '.bin2hex(random_bytes(3)))->GetKey())->GetKey());

		return $sLogin;
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
	 * B8 follow-up: the console removes an attachment from its host's edit form, so the host decides: it must exist and
	 * be readable, the account must be able to modify this very object, and the attachments module must not freeze it
	 * (closed tickets, by default). Both delete tools, dry run and real.
	 *
	 * @dataProvider hostProvider
	 */
	public function testAnAttachmentIsRemovedOnlyWhereItsHostAllowsIt(string $sCase, bool $bAllowed, string $sWords): void
	{
		$iTicket = $this->aTicket();
		$iHost = $iTicket;
		$sHostClass = 'UserRequest';
		switch ($sCase) {
			case 'a ticket the user may modify':
				break;
			case 'an object the user may read and not modify':
				$sHostClass = 'Organization';
				$iHost = (int)$this->CreateOrganization('AttHost '.bin2hex(random_bytes(3)))->GetKey();
				break;
			case 'an object the user cannot read':
				$sHostClass = 'URP_Profiles';
				$iHost = 1;
				break;
			case 'a closed ticket':
				$oTicket = MetaModel::GetObject('UserRequest', $iTicket);
				$oTicket->Set('status', 'closed');
				$oTicket->DBUpdate();
				break;
			case 'no host at all':
				$iHost = 0;
				break;
		}
		$iAttachment = $this->attachmentOn($sHostClass, $iHost, 'host-'.bin2hex(random_bytes(3)).'.txt');
		$iSecond = $iAttachment;
		$this->loginAs('Support Agent');
		if ($sCase === 'an object the user may read and not modify') {
			$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('Organization', UR_ACTION_MODIFY), 'the premise');
		}
		if ($sCase === 'an object the user cannot read') {
			$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('URP_Profiles', UR_ACTION_READ), 'the premise');
		}

		foreach ([[true, false], [false, false]] as [$bSimulate, $bBulk]) {
			try {
				if ($bBulk) {
					$aReport = json_decode((string)json_encode(ToolOutput::Decode(ObjectBulkDelete::execute('Attachment', [$iSecond], $bSimulate))), true);
					$bOk = ($aReport['objects'][0]['status'] ?? '') === 'ok';
					$sMessage = (string)($aReport['objects'][0]['message'] ?? json_encode($aReport));
				} else {
					ObjectDelete::execute('Attachment', $iAttachment, $bSimulate);
					$bOk = true;
					$sMessage = '';
				}
			} catch (ToolCallException $e) {
				$bOk = false;
				$sMessage = $e->getMessage();
			}
			$sWhich = ($bSimulate ? 'dry run' : 'real').($bBulk ? ' bulk' : ' single');
			$this->assertSame($bAllowed, $bOk, $sWhich.', '.$sCase.': '.$sMessage);
			if (!$bAllowed) {
				$this->assertStringContainsString($sWords, $sMessage, $sWhich.', '.$sCase);
				$this->assertStringNotContainsString($sHostClass === '' ? 'no-such' : $sHostClass.'::', $sMessage, 'the host is named');
			}
		}
		$this->assertSame($bAllowed, MetaModel::GetObject('Attachment', $iSecond, false) === null, 'the attachment is '.($bAllowed ? 'still there' : 'gone anyway'));
		$this->assertSame($bAllowed, MetaModel::GetObject('Attachment', $iAttachment, false) === null);
		if ($bAllowed) {
			$iRemoved = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT CMDBChangeOpAttachmentRemoved WHERE objclass = :c AND objkey = :k'), [], ['c' => $sHostClass, 'k' => $iHost]))->Count();
			$this->assertSame(1, $iRemoved, 'the host\'s history has no "attachment removed" line, which the console leaves');
		}
	}

	/**
	 * The bulk tool asks the same of the host. A Support Agent holds no bulk delete on Attachment, so the class gate
	 * stops it first; an administrator holds every right, and only the host's own conditions are left to refuse.
	 */
	public function testTheBulkToolAsksTheSameOfTheHost(): void
	{
		$iTicket = $this->aTicket();
		$iFine = $this->attachmentOn('UserRequest', $iTicket, 'fine.txt');
		$iClosedTicket = $this->aTicket();
		$oClosed = MetaModel::GetObject('UserRequest', $iClosedTicket);
		$oClosed->Set('status', 'closed');
		$oClosed->DBUpdate();
		$iFrozen = $this->attachmentOn('UserRequest', $iClosedTicket, 'frozen.txt');
		$iDraft = $this->attachmentOn('UserRequest', 0, 'draft.txt');

		$aReport = json_decode((string)json_encode(ToolOutput::Decode(ObjectBulkDelete::execute('Attachment', [$iFine, $iFrozen, $iDraft], false))), true);
		$aByStatus = array_column($aReport['objects'], 'status', 'id');

		$this->assertSame('ok', $aByStatus[$iFine]);
		$this->assertSame('error', $aByStatus[$iFrozen]);
		$this->assertSame('error', $aByStatus[$iDraft]);
		$this->assertNull(MetaModel::GetObject('Attachment', $iFine, false));
		$this->assertNotNull(MetaModel::GetObject('Attachment', $iFrozen, false), 'an attachment of a closed ticket was removed');
		$this->assertNotNull(MetaModel::GetObject('Attachment', $iDraft, false));
		$this->assertSame(1, (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT CMDBChangeOpAttachmentRemoved WHERE objclass = "UserRequest" AND objkey = :k'), [], ['k' => $iTicket]))->Count(), 'the host\'s history has no "attachment removed" line');

		$this->loginAs('Support Agent');
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('in bulk');
		ObjectBulkDelete::execute('Attachment', [$iFrozen], true);
	}

	/**
	 * ADR 0026: whoever may edit the host may remove its attachment, whatever the class right on Attachment says, and
	 * the right to delete an Attachment does not reach a host the account may not edit. Two stock profiles have one
	 * without the other (read from iTop 3.2.3-3): `Portal user` edits tickets and cannot delete an Attachment, and
	 * `Change Approver` can delete an Attachment and cannot edit an Organization.
	 */
	public function testTheHostRightReplacesTheDeleteRightOnTheAttachment(): void
	{
		$iTicket = $this->aTicket();
		$iOrg = (int)$this->CreateOrganization('AttRule '.bin2hex(random_bytes(3)))->GetKey();
		$iEditOnly = $this->attachmentOn('UserRequest', $iTicket, 'edit-only.txt');
		$iDeleteOnly = $this->attachmentOn('Organization', $iOrg, 'delete-only.txt');

		// May edit tickets, may read attachments, holds no delete right on Attachment.
		$sEditor = $this->userWith('Portal user');
		$sApprover = $this->userWith('Change Approver');
		UserRights::Login($sEditor);
		$this->assertSame(UR_ALLOWED_YES, UserRights::IsActionAllowed('UserRequest', UR_ACTION_MODIFY), 'the premise: may edit the ticket');
		$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('Attachment', UR_ACTION_DELETE), 'the premise: no delete right on Attachment');
		$aDone = ToolOutput::Decode(ObjectDelete::execute('Attachment', $iEditOnly, false));
		$this->assertFalse($aDone['simulated']);
		$this->assertNull(MetaModel::GetObject('Attachment', $iEditOnly, false), 'the right to edit the host did not remove its attachment');

		// Holds the delete right on Attachment, may only read the host.
		UserRights::Login($sApprover);
		$this->assertSame(UR_ALLOWED_YES, UserRights::IsActionAllowed('Attachment', UR_ACTION_DELETE), 'the premise');
		$this->assertSame(UR_ALLOWED_NO, UserRights::IsActionAllowed('Organization', UR_ACTION_MODIFY), 'the premise');
		try {
			ObjectDelete::execute('Attachment', $iDeleteOnly, false);
			$this->fail('the delete right on Attachment removed a file from an object the account may not edit');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('cannot edit the object this attachment belongs to', $e->getMessage());
		}
		$this->assertNotNull(MetaModel::GetObject('Attachment', $iDeleteOnly, false));
	}

	/**
	 * In iTop's form the event fired before an attachment goes can be vetoed by a listener, by throwing. A listener of
	 * another extension keeps that power here, in both tools, and its message is not repeated to the caller.
	 */
	public function testAListenerOfAnotherExtensionCanVetoTheRemoval(): void
	{
		$iTicket = $this->aTicket();
		$iSingle = $this->attachmentOn('UserRequest', $iTicket, 'veto-single.txt');
		$iBulk = $this->attachmentOn('UserRequest', $iTicket, 'veto-bulk.txt');
		$bVeto = true;
		$sId = EventService::RegisterListener(EVENT_REMOVE_ATTACHMENT_FROM_OBJECT, static function () use (&$bVeto): void {
			if ($bVeto) {
				throw new \Exception('veto-secret-text from another extension');
			}
		});

		try {
			try {
				ObjectDelete::execute('Attachment', $iSingle, false);
				$this->fail('the single tool deleted past a listener\'s veto');
			} catch (ToolCallException $e) {
				$this->assertStringContainsString('an extension refused it', $e->getMessage());
				$this->assertStringNotContainsString('veto-secret-text', $e->getMessage(), 'the listener\'s words are repeated');
			}
			$this->assertNotNull(MetaModel::GetObject('Attachment', $iSingle, false));

			$aReport = json_decode((string)json_encode(ToolOutput::Decode(ObjectBulkDelete::execute('Attachment', [$iBulk], false))), true);
			$this->assertSame('error', $aReport['objects'][0]['status']);
			$this->assertStringContainsString('an extension refused it', $aReport['objects'][0]['message']);
			$this->assertStringNotContainsString('veto-secret-text', (string)json_encode($aReport));
			$this->assertNotNull(MetaModel::GetObject('Attachment', $iBulk, false));

			// A dry run fires nothing, and shows the deletion as it would be.
			$this->assertTrue(ToolOutput::Decode(ObjectDelete::execute('Attachment', $iSingle, true))['simulated']);
		} finally {
			$bVeto = false;
			EventService::UnRegisterListener($sId);
		}
	}

	/** @return array<string, array{0: string, 1: bool, 2: string}> */
	public static function hostProvider(): array
	{
		return [
			'a ticket the user may modify'                 => ['a ticket the user may modify', true, ''],
			'an object the user may read and not modify'   => ['an object the user may read and not modify', false, 'cannot edit the object this attachment belongs to'],
			'an object the user cannot read'               => ['an object the user cannot read', false, 'was not found'],
			'a closed ticket'                              => ['a closed ticket', false, 'read-only in its current state'],
			'no host at all'                               => ['no host at all', false, 'attached to no saved object'],
		];
	}

	private function attachmentOn(string $sHostClass, int $iHost, string $sName): int
	{
		$oAttachment = MetaModel::NewObject('Attachment');
		$oAttachment->Set('item_class', $sHostClass);
		$oAttachment->Set('item_id', $iHost);
		$oAttachment->Set('contents', new \ormDocument("x\n", 'text/plain', $sName));
		return (int)$oAttachment->DBInsert();
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
	 * Files an image attribute does not take, as iTop quotes them: whole when short, cut and HTML-escaped past a hundred
	 * bytes. Random bytes, because the first fix compared the start of the file with the quotation and a retest got
	 * binary back (review 2026-11-23, S8).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refusedFiles(): array
	{
		return [
			'text'                          => ['SECRET-BYTES '.str_repeat('A', 300)."\x00\x01"],
			'short text'                    => ['SECRET-BYTES'],
			'random, long'                  => ["\x01\x02".random_bytes(400)],
			'random, short'                 => ["\x01\x02".random_bytes(40)],
			'random with closing brackets'  => ["\x01]\x02]] ] ".random_bytes(20).'] SECRET-BYTES ]'.random_bytes(200)],
			'brackets and a second opening' => ["\x01] Wrong format [ SECRET-BYTES ] (picture) <b>&\n".random_bytes(150)],
			'escaped by iTop'               => ["\x01<<<>>>&&&\"\"''".str_repeat('<', 200).random_bytes(20)],
		];
	}

	/** @dataProvider refusedFiles */
	public function testARefusedFilesBytesAreNotEchoed(string $sData): void
	{
		$iOrg = (int)$this->CreateOrganization('AttPic '.bin2hex(random_bytes(4)))->GetKey();
		$iPerson = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$aValue = ['data' => base64_encode($sData), 'mimetype' => 'application/octet-stream', 'filename' => 'x.bin', 'downloads_count' => 0];

		$aRoutes = [
			'attach' => static fn () => ObjectAttach::execute('Person', $iPerson, 'x.bin', base64_encode($sData), null, 'picture'),
			'update' => static fn () => ObjectUpdate::execute('Person', $iPerson, ['picture' => $aValue]),
			'create' => static fn () => ObjectCreate::execute('Person', ['org_id' => $iOrg, 'name' => 'Pic', 'first_name' => 'P', 'picture' => $aValue]),
		];
		foreach ($aRoutes as $sRoute => $fCall) {
			try {
				$fCall();
				$this->fail("{$sRoute}: a file that is no picture was accepted as one");
			} catch (ToolCallException $e) {
				$sMessage = $e->getMessage();
				$this->assertStringContainsString('(picture)', $sMessage, "{$sRoute}: the attribute is still named");
				// Nothing follows the replacement, so nothing of the file does, whatever it holds.
				$this->assertStringEndsWith("Wrong format [the file's content is not repeated]", $sMessage, $sRoute);
				$this->assertStringNotContainsString('SECRET-BYTES', $sMessage, $sRoute);
				$this->assertSame(1, preg_match('//u', $sMessage), "{$sRoute}: the answer holds bytes that are no text");
			}
		}
	}

	/** The value of an ordinary attribute is still quoted back: only a file's is not. */
	public function testARefusedValueThatIsNoFileIsStillQuoted(): void
	{
		$iOrg = (int)$this->CreateOrganization('AttPic '.bin2hex(random_bytes(4)))->GetKey();
		$iPerson = (int)$this->CreatePerson(1, $iOrg)->GetKey();

		try {
			ObjectUpdate::execute('Person', $iPerson, ['email' => 'not an address']);
			$this->fail('a text with spaces was accepted as an email address');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('Wrong format [not an address]', $e->getMessage());
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
