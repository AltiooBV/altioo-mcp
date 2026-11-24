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
