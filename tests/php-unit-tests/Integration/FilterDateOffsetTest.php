<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Helper\FilterDate;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Review of 2026-11-23, B2: a filter given `2026-10-28T10:46:22Z` matched a row stored at 10:46:22 local time, because
 * the offset was dropped. The instance is in a zone that is not UTC for the whole test.
 *
 * @group integration
 */
class FilterDateOffsetTest extends ItopDataTestCaseAlias
{
	private string $sZone;

	protected function setUp(): void
	{
		parent::setUp();
		$this->sZone = date_default_timezone_get();
		date_default_timezone_set('Europe/Paris');
	}

	protected function tearDown(): void
	{
		date_default_timezone_set($this->sZone);
		parent::tearDown();
	}

	public function testAnOffsetIsConvertedToTheInstancesZone(): void
	{
		$oAtt = MetaModel::GetAttributeDef('UserRequest', 'start_date');
		$this->assertSame('2026-10-28 11:46:22', FilterDate::ForFilter($oAtt, '2026-10-28T10:46:22Z'), 'winter: one hour');
		$this->assertSame('2026-07-28 12:46:22', FilterDate::ForFilter($oAtt, '2026-07-28T10:46:22Z'), 'summer: two hours');
		$this->assertSame('2026-10-28 10:46:22', FilterDate::ForFilter($oAtt, '2026-10-28T10:46:22+01:00'));
		$this->assertSame('2026-10-28 10:46:22', FilterDate::ForFilter($oAtt, '2026-10-28T10:46:22+0100'));
	}

	public function testWhatIsNotAnOffsetDateTimeIsLeftAsSent(): void
	{
		$oAtt = MetaModel::GetAttributeDef('UserRequest', 'start_date');
		foreach (['2026-10-28 10:46:22', '2026-10-28T10:46:22', '2026-10-28', 'open', '2026-10-28T10:46:22Zjunk', ''] as $sValue) {
			$this->assertSame($sValue, FilterDate::ForFilter($oAtt, $sValue));
		}
		$this->assertSame(7, FilterDate::ForFilter($oAtt, 7));
		$this->assertSame('2026-10-28T10:46:22Z', FilterDate::ForFilter(MetaModel::GetAttributeDef('UserRequest', 'title'), '2026-10-28T10:46:22Z'), 'only a date attribute converts');
	}

	public function testASearchAndACountByAnInstantFindTheRowStoredAtThatInstant(): void
	{
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}
		$iOrg = (int)$this->CreateOrganization('FilterDate '.bin2hex(random_bytes(4)))->GetKey();
		$oTicket = $this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey(), 'start_date' => '2026-10-28 11:46:22']);
		$iId = (int)$oTicket->GetKey();

		// 10:46:22 UTC is 11:46:22 in Paris: the same instant as the stored value.
		$aFound = ToolOutput::Decode(ObjectSearchByClass::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => '2026-10-28T10:46:22Z']));
		$this->assertSame(1, $aFound['total'], 'the instant given with an offset does not find the row stored at that instant');
		$this->assertSame($iId, (int)$aFound['objects'][0]['id']);

		// And the old reading, 10:46:22 taken as local, finds nothing.
		$aMissed = ToolOutput::Decode(ObjectSearchByClass::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => '2026-10-28T11:46:22+00:00']));
		$this->assertSame(0, $aMissed['total'], 'an offset is read as written, not dropped');

		$aCount = ToolOutput::Decode(ObjectCount::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => '2026-10-28T10:46:22Z']));
		$this->assertSame(1, $aCount['total']);
	}
}
