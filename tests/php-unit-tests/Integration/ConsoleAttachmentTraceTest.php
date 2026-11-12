<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Combodo\iTop\Service\Events\EventData;
use Combodo\iTop\Service\Events\EventService;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * A file attached in console mode leaves what iTop's own console leaves: a
 * CMDBChangeOpAttachmentAdded in the object's history and the
 * EVENT_ADD_ATTACHMENT_TO_OBJECT event, as the portal already did
 * (AttachmentPlugIn::UpdateAttachments, itop-attachments/main.itop-attachments.php:303-377).
 * A dry run leaves neither.
 */
class ConsoleAttachmentTraceTest extends ItopDataTestCaseAlias
{
	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	public function testAConsoleAttachLeavesOneHistoryRowAndFiresTheEvent(): void
	{
		if (!MetaModel::IsValidClass('Attachment') || !class_exists('CMDBChangeOpAttachmentAdded')) {
			$this->markTestSkipped('Needs the attachments module.');
		}

		$iOrg = (int)$this->CreateOrganization('ConsoleAttach '.bin2hex(random_bytes(4)))->GetKey();
		$sB64 = base64_encode("Console notes.\n");

		$aFired = [];
		EventService::RegisterListener(EVENT_ADD_ATTACHMENT_TO_OBJECT, static function (EventData $oEvent) use (&$aFired): void {
			$aFired[] = $oEvent->Get('attachment')->Get('contents')->GetFileName();
		});

		$aDry = ToolOutput::Decode(ObjectAttach::execute('Organization', $iOrg, 'dry.txt', $sB64, 'text/plain'));
		$this->assertTrue($aDry['simulated']);
		$this->assertSame(0, $this->historyRows($iOrg), 'a dry run leaves no history row');
		$this->assertSame([], $aFired, 'a dry run fires no event');

		$aDone = ToolOutput::Decode(ObjectAttach::execute('Organization', $iOrg, 'console.txt', $sB64, 'text/plain', null, false, 'Because'));
		$this->assertFalse($aDone['simulated']);

		$this->assertSame(1, $this->historyRows($iOrg), 'exactly one history row for the host object, as iTop\'s console leaves');
		$this->assertSame(['console.txt'], $aFired);

		$oOps = new DBObjectSet(DBObjectSearch::FromOQL_AllData(
			'SELECT CMDBChangeOpAttachmentAdded WHERE objclass = "Organization" AND objkey = :id'
		), [], ['id' => $iOrg]);
		$oOp = $oOps->Fetch();
		$this->assertSame('console.txt', $oOp->Get('filename'));
		$this->assertSame((int)$aDone['id'], (int)$oOp->Get('attachment_id'));
		$this->assertStringNotContainsString('MCP', (string)MetaModel::GetObject('CMDBChange', (int)$oOp->Get('change'))->Get('userinfo'));
	}

	private function historyRows(int $iOrg): int
	{
		return (new DBObjectSet(DBObjectSearch::FromOQL_AllData(
			'SELECT CMDBChangeOpAttachmentAdded WHERE objclass = "Organization" AND objkey = :id'
		), [], ['id' => $iOrg]))->Count();
	}
}
