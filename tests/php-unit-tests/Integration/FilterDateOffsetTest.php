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
use Mcp\Server\Exception\ToolCallException;
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
		foreach (['2026-10-28 10:46:22', '2026-10-28t10:46:22', '2026-10-28', 'open', '2026-10-28T10:46:22Zjunk', ''] as $sValue) {
			$this->assertSame($sValue, FilterDate::ForFilter($oAtt, $sValue));
		}
		$this->assertSame('2026-10-28 10:46:22', FilterDate::ForFilter($oAtt, '2026-10-28T10:46:22'), 'a T and no offset is local time, not converted');
		$this->assertSame(7, FilterDate::ForFilter($oAtt, 7));
		$this->assertSame('2026-10-28T10:46:22Z', FilterDate::ForFilter(MetaModel::GetAttributeDef('UserRequest', 'title'), '2026-10-28T10:46:22Z'), 'only a date attribute converts');
	}

	/**
	 * Retest of 2026-11-27: `23/11/2026 19:27:50` was compared as text and found nothing, which reads as "there is none".
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function notADate(): array
	{
		return [
			'day first'                 => ['23/11/2026 19:27:50'],
			'day first, no time'        => ['23/11/2026'],
			'dotted'                    => ['23.11.2026'],
			'no seconds, no offset'     => ['2026-11-23 19:27'],
			'T with no seconds'         => ['2026-11-23T19:27'],
			'T with a fraction'         => ['2026-11-23T19:27:50.250'],
			'a lower-case t'            => ['2026-11-23t19:27:50'],
			'slashes, year first'       => ['2026/11/23 19:27:50'],
			'dots, year first'          => ['2026.11.23'],
			'digits alone'              => ['20261123192750'],
			'T with a day that is none' => ['2026-02-30T10:00:00'],
			'T with an hour that is none' => ['2026-11-23T25:00:00'],
			'a month that is none'      => ['2026-13-01'],
			'a day that is none'        => ['2026-02-30 10:00:00'],
			'a day that is none, in UTC' => ['2026-02-30T10:00:00Z'],
			'an hour that is none'      => ['2026-11-23 25:00:00'],
			'an offset that is none'    => ['2026-11-23T19:27:50+99:99'],
			'trailing text'             => ['2026-11-23T19:27:50Zjunk'],
			'a word'                    => ['yesterday'],
			'a number'                  => [20261123],
			'blank'                     => ['   '],
		];
	}

	/** @dataProvider notADate */
	public function testAFilterOnADateRefusesAnyOtherFormWithoutQuotingIt(mixed $value): void
	{
		$sRefusal = FilterDate::Refusal(MetaModel::GetAttributeDef('UserRequest', 'start_date'), 'start_date', $value);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('YYYY-MM-DD HH:MM:SS, YYYY-MM-DDTHH:MM:SS or YYYY-MM-DD', $sRefusal);
		$this->assertStringContainsString('RFC 3339 with an offset', $sRefusal);
		if (trim((string)$value) !== '') {
			$this->assertStringNotContainsString((string)$value, $sRefusal, 'the value is quoted back');
		}
		foreach ([ObjectSearchByClass::class, ObjectCount::class] as $sTool) {
			try {
				$sTool::execute('UserRequest', ['start_date' => $value]);
				$this->fail("{$sTool} searched for it and answered");
			} catch (ToolCallException $e) {
				$this->assertSame($sRefusal, $e->getMessage(), $sTool);
			}
		}
	}

	public function testTheFormsTakenAreNotRefused(): void
	{
		$oAtt = MetaModel::GetAttributeDef('UserRequest', 'start_date');
		foreach (['2026-11-23 19:27:50', '2026-11-23T19:27:50', ' 2026-11-23T19:27:50 ', '2026-11-23', ' 2026-11-23 ', '2026-11-23T19:27:50Z', '2026-11-23t19:27:50.250+01:00', '2026-11-23 19:27:50 -0500', '2026-11-23T19:27+01', '', null] as $value) {
			$this->assertNull(FilterDate::Refusal($oAtt, 'start_date', $value), var_export($value, true));
		}
		$this->assertNull(FilterDate::Refusal(MetaModel::GetAttributeDef('UserRequest', 'title'), 'title', '23/11/2026'), 'only a date is held to a date');
		$this->assertSame('2026-11-23', FilterDate::ForFilter($oAtt, ' 2026-11-23 '));
		$this->assertSame('2026-11-23 19:27:50', FilterDate::ForFilter($oAtt, ' 2026-11-23T19:27:50 '));
	}

	/** Decided on 2026-11-27: a T with no offset is accepted, as the same local time as with a space. */
	public function testAPlainAndATDateTimeFindTheSameStoredTicket(): void
	{
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}
		$iOrg = (int)$this->CreateOrganization('FilterDate '.bin2hex(random_bytes(4)))->GetKey();
		$iId = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey(), 'start_date' => '2026-11-23 19:27:00'])->GetKey();

		foreach (['2026-11-23 19:27:00', '2026-11-23T19:27:00'] as $sValue) {
			$aFound = ToolOutput::Decode(ObjectSearchByClass::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => $sValue]));
			$this->assertSame(1, $aFound['total'], $sValue);
			$this->assertSame($iId, (int)$aFound['objects'][0]['id'], $sValue);
			$this->assertSame(1, ToolOutput::Decode(ObjectCount::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => $sValue]))['total'], $sValue);
		}
		// Local time, so the same clock reading with an offset is another instant here (Paris is an hour ahead).
		$this->assertSame(0, ToolOutput::Decode(ObjectSearchByClass::execute('UserRequest', ['org_id' => $iOrg, 'start_date' => '2026-11-23T19:27:00Z']))['total']);
	}

	/**
	 * On a date attribute the two forms answer alike too. A stock ticket has none, so a customer contract. The column
	 * holds a day, which a date-time at midnight equals and one at any other time does not, as a date-time with a space
	 * always has; an offset is converted to the day.
	 */
	public function testAPlainAndATDateTimeAnswerAlikeOnADateAttribute(): void
	{
		if (!MetaModel::IsValidClass('CustomerContract')) {
			$this->markTestSkipped('Needs the service management module.');
		}
		$iOrg = (int)$this->CreateOrganization('FilterDate '.bin2hex(random_bytes(4)))->GetKey();
		$iId = (int)$this->createObject('CustomerContract', ['name' => 'FilterDate '.bin2hex(random_bytes(4)), 'org_id' => $iOrg, 'provider_id' => $iOrg, 'start_date' => '2026-11-23'])->GetKey();
		$this->assertInstanceOf(\AttributeDate::class, MetaModel::GetAttributeDef('CustomerContract', 'start_date'), 'the premise: a date, not a date-time');

		foreach (['2026-11-23' => 1, '2026-11-23 00:00:00' => 1, '2026-11-23T00:00:00' => 1, '2026-11-23 19:27:00' => 0, '2026-11-23T19:27:00' => 0] as $sValue => $iExpected) {
			$aFound = ToolOutput::Decode(ObjectSearchByClass::execute('CustomerContract', ['org_id' => $iOrg, 'start_date' => (string)$sValue]));
			$this->assertSame($iExpected, $aFound['total'], (string)$sValue);
			if ($iExpected === 1) {
				$this->assertSame($iId, (int)$aFound['objects'][0]['id'], (string)$sValue);
			}
			$this->assertSame($iExpected, ToolOutput::Decode(ObjectCount::execute('CustomerContract', ['org_id' => $iOrg, 'start_date' => (string)$sValue]))['total'], (string)$sValue);
		}
	}

	/** A stopwatch's dates and a date shown through an external key are compared as dates too. */
	public function testAStopwatchDateAndAnExternalDateAreHeldToTheSameForms(): void
	{
		$aFound = [];
		foreach (MetaModel::GetClasses() as $sClass) {
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
				if (!isset($aFound['stopwatch']) && $oAttDef instanceof \AttributeSubItem && $oAttDef->Get('item_code') === 'started') {
					$aFound['stopwatch'] = $oAttDef;
				}
				if (!isset($aFound['external']) && $oAttDef instanceof \AttributeExternalField && $oAttDef->GetFinalAttDef() instanceof \AttributeDateTime) {
					$aFound['external'] = $oAttDef;
				}
			}
		}
		if ($aFound === []) {
			$this->markTestSkipped('This datamodel has neither a stopwatch date nor an external date.');
		}
		foreach ($aFound as $sKind => $oAttDef) {
			$this->assertNotNull(FilterDate::Refusal($oAttDef, 'x', '23/11/2026'), $sKind);
			$this->assertNull(FilterDate::Refusal($oAttDef, 'x', '2026-11-23'), $sKind);
		}
		// What a stopwatch counts is no date, and is left alone.
		foreach (MetaModel::ListAttributeDefs('UserRequest') as $oAttDef) {
			if ($oAttDef instanceof \AttributeSubItem && $oAttDef->Get('item_code') === 'timespent') {
				$this->assertNull(FilterDate::Refusal($oAttDef, 'x', '120'));
			}
		}
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
