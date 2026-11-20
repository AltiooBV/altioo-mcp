<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Service\Write\DeletionCascade;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * What a bulk call says about itself.
 *
 * Review 2026-11-18, AC-1: a half-failed call is a JSON-RPC success, and nothing at the top of the answer said that every
 * object had not been done. AC-2: ids of which one is deleted by the cascade of another read, on a real run, as a failure
 * ("not found") where the dry run had said all would be deleted.
 */
class BulkReportTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();
		$this->iOrg = (int)$this->CreateOrganization('BulkReport '.bin2hex(random_bytes(4)))->GetKey();
	}

	public function testAWholeBulkCallSaysSoAtTheTop(): void
	{
		$iOrg = $this->iOrg;

		$aReport = ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$iOrg], ['code' => 'BR-OK']));

		$this->assertTrue($aReport['all_succeeded']);
		$this->assertSame(0, $aReport['failed']);
	}

	public function testAPartlyFailedBulkCallSaysSoAtTheTop(): void
	{
		$aReport = ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$this->iOrg, 987654321], ['code' => 'BR-HALF']));

		$this->assertFalse($aReport['all_succeeded'], 'the envelope of a call that did half of it is a success, so the report has to say');
		$this->assertSame(1, $aReport['succeeded']);
		$this->assertSame(1, $aReport['failed']);
	}

	/**
	 * AC-2 cannot be run through a real cascade on iTop 3.2.3 or 3.3: a cascade that reaches the same class again raises a
	 * TypeError in iTop's own `DBObject::MakeDeletionPlan()` (an `in_array()` on a scalar, `core/dbobject.class.php`), before
	 * this tool's code is reached. What can be asserted is the bookkeeping the tool is made from, on a plan built the way
	 * iTop builds one, and (in the unit suite) that the tool asks it before it looks an entry up.
	 */
	public function testAnObjectInThePlanOfAnEarlierEntryIsFoundThere(): void
	{
		$oParent = MetaModel::NewObject('Organization');
		$oParent->Set('name', 'BulkReport-p-'.bin2hex(random_bytes(3)));
		$oParent->DBInsert();
		$oChild = MetaModel::NewObject('Organization');
		$oChild->Set('name', 'BulkReport-c-'.bin2hex(random_bytes(3)));
		$oChild->DBInsert();
		$iParent = (int)$oParent->GetKey();
		$iChild = (int)$oChild->GetKey();

		$oPlan = new \DeletionPlan();
		$oPlan->AddToDelete($oParent, DEL_MANUAL);
		$oPlan->AddToDelete($oChild, DEL_AUTO);
		$aLedger = [];
		DeletionCascade::NoteCascade($oPlan, 'Organization', $iParent, $aLedger);

		$this->assertSame("Organization::{$iParent}", DeletionCascade::CascadeOf('Organization', $iChild, $aLedger));
		$this->assertNull(DeletionCascade::CascadeOf('Organization', $iParent, $aLedger), 'the entry is not in its own cascade');
		$this->assertNull(DeletionCascade::CascadeOf('Organization', 987654321, $aLedger));
	}

	public function testAnIdThatIsNotThereIsStillAFailure(): void
	{
		$aReal = ToolOutput::Decode(ObjectBulkDelete::execute('Organization', [987654321], false));

		$this->assertFalse($aReal['all_succeeded']);
		$this->assertSame('error', $aReal['objects'][0]['status']);
	}
}
