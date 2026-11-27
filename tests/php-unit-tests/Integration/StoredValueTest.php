<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Read\StoredValue;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AttributeDate;
use AttributeDateTime;
use AttributeStopWatch;
use AttributeSubItem;
use DateTimeFormat;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use ormStopWatch;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A read gives values as iTop stores them, whatever the display format (live check of 2026-11-25).
 *
 * GetForJSON() is the edit value: with `d/m/Y` configured, `start_date` read "25/11/2026 12:54", a
 * deadline came out the same way, `sla_tto_passed` read "yes", and `sla_tto_passed = 'yes'` in OQL
 * matched every ticket that had not breached. The history gave the same deadline as a Unix
 * timestamp. Every test here runs under a display format that is not the internal one.
 *
 * @group integration
 */
class StoredValueTest extends ItopDataTestCaseAlias
{
	private DateTimeFormat $oDateTimeFormat;

	private DateTimeFormat $oDateFormat;

	protected function setUp(): void
	{
		parent::setUp();
		$this->oDateTimeFormat = AttributeDateTime::GetFormat();
		$this->oDateFormat = AttributeDate::GetFormat();
		AttributeDateTime::SetFormat(new DateTimeFormat('d/m/Y H:i'));
		AttributeDate::SetFormat(new DateTimeFormat('d/m/Y'));
	}

	protected function tearDown(): void
	{
		AttributeDateTime::SetFormat($this->oDateTimeFormat);
		AttributeDate::SetFormat($this->oDateFormat);
		parent::tearDown();
	}

	public function testADateTimeIsReadInTheInternalFormat(): void
	{
		$oTicket = $this->ticket();
		$oTicket->Set('start_date', '2026-10-28 11:46:22');

		$this->assertSame('28/10/2026 11:46', MetaModel::GetAttributeDef('UserRequest', 'start_date')->GetForJSON('2026-10-28 11:46:22'), 'the display format is not in force: this test proves nothing');
		$this->assertSame('2026-10-28 11:46:22', ObjectSerializer::Value($oTicket, 'UserRequest', 'start_date'));
	}

	public function testADateIsReadInTheInternalFormatAndAnUnsetOneAsEmpty(): void
	{
		$oAttDef = $this->dateAttribute();
		$this->assertSame('2026-12-01', StoredValue::Of($oAttDef, '2026-12-01'));
		foreach ([null, '', '0000-00-00'] as $value) {
			$this->assertSame('', StoredValue::Of($oAttDef, $value), var_export($value, true));
		}
		$this->assertSame('', StoredValue::Of(MetaModel::GetAttributeDef('UserRequest', 'start_date'), '0000-00-00 00:00:00'));
	}

	public function testAStopwatchsItemsAreReadAsStored(): void
	{
		$oTicket = $this->ticket();
		$oStopWatchDef = $this->stopWatch();
		$iStarted = 1795600000;
		$iDeadline = 1795608575;

		$oStopWatch = new ormStopWatch(120, $iStarted, $iStarted, null);
		foreach (array_keys($oStopWatchDef->ListThresholds()) as $iThreshold) {
			$oStopWatch->DefineThreshold($iThreshold, $iDeadline, true, true, 27);
		}
		$oTicket->Set($oStopWatchDef->GetCode(), $oStopWatch);

		$aSeen = [];
		foreach ($oStopWatchDef->ListSubItems() as $sCode => $oItemDef) {
			$sItem = (string)$oItemDef->Get('item_code');
			$sKind = preg_replace('/^\d+_/', '', $sItem);
			$value = ObjectSerializer::Value($oTicket, 'UserRequest', (string)$sCode);
			$aSeen[$sKind] = true;

			$expected = match ($sKind) {
				'deadline'             => date('Y-m-d H:i:s', $iDeadline),
				'started', 'laststart' => date('Y-m-d H:i:s', $iStarted),
				'stopped'              => '',
				'passed', 'triggered'  => 1,
				'overrun'              => 27,
				'timespent'            => 120,
				default                => $value,
			};
			$this->assertSame($expected, $value, $sCode.' ('.$sItem.')');
		}
		$this->assertArrayHasKey('deadline', $aSeen, 'the datamodel exposes no deadline: nothing was checked');
	}

	public function testOqlFindsTheTicketByThePassedValueARead(): void
	{
		$oTicket = $this->ticket();
		$oTicket->DBWrite();
		$iId = (int)$oTicket->GetKey();

		$sPassed = null;
		foreach ($this->stopWatch()->ListSubItems() as $sCode => $oItemDef) {
			if (str_ends_with((string)$oItemDef->Get('item_code'), '_passed')) {
				$sPassed = (string)$sCode;
				break;
			}
		}
		if ($sPassed === null || !MetaModel::IsValidFilterCode('UserRequest', $sPassed)) {
			$this->markTestSkipped('No searchable passed item on UserRequest in this datamodel.');
		}

		$value = ObjectSerializer::Value(MetaModel::GetObject('UserRequest', $iId), 'UserRequest', $sPassed);
		$this->assertContains($value, [0, 1], 'a passed item is read as 0 or 1');

		$oSearch = DBObjectSearch::FromOQL("SELECT UserRequest WHERE id = :id AND $sPassed = :v");
		$this->assertSame(1, (new DBObjectSet($oSearch, [], ['id' => $iId, 'v' => $value]))->Count(), 'the value a read gave does not find the object it was read from');
	}

	public function testTheHistoryGivesAStopwatchDateAsAReadDoes(): void
	{
		$oDeadline = null;
		$oPassed = null;
		foreach ($this->stopWatch()->ListSubItems() as $oItemDef) {
			$sItem = (string)$oItemDef->Get('item_code');
			if (str_ends_with($sItem, '_deadline')) {
				$oDeadline ??= $oItemDef;
			} elseif (str_ends_with($sItem, '_passed')) {
				$oPassed ??= $oItemDef;
			}
		}
		$this->assertInstanceOf(AttributeSubItem::class, $oDeadline);

		$this->assertSame(date('Y-m-d H:i:s', 1795608575), StoredValue::OfRecorded($oDeadline, '1795608575'));
		$this->assertSame('', StoredValue::OfRecorded($oDeadline, ''));
		$this->assertNull(StoredValue::OfRecorded($oDeadline, null), 'null is "the log has no value", and stays so');
		if ($oPassed !== null) {
			$this->assertSame('1', StoredValue::OfRecorded($oPassed, '1'), 'only dates are converted');
		}
		$this->assertSame('1795608575', StoredValue::OfRecorded(MetaModel::GetAttributeDef('UserRequest', 'title'), '1795608575'));
	}

	private function ticket(): \DBObject
	{
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the request management module.');
		}
		$iOrg = (int)$this->CreateOrganization('StoredValue '.bin2hex(random_bytes(4)))->GetKey();

		return $this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()]);
	}

	private function stopWatch(): AttributeStopWatch
	{
		// The first one is not it: `cumulatedpending` exposes no item at all.
		foreach (MetaModel::ListAttributeDefs('UserRequest') as $oAttDef) {
			if (!$oAttDef instanceof AttributeStopWatch) {
				continue;
			}
			foreach ($oAttDef->ListSubItems() as $oItemDef) {
				if (str_ends_with((string)$oItemDef->Get('item_code'), '_deadline')) {
					return $oAttDef;
				}
			}
		}
		$this->markTestSkipped('UserRequest has no stopwatch with a deadline item in this datamodel.');
	}

	private function dateAttribute(): AttributeDate
	{
		foreach (MetaModel::GetClasses() as $sClass) {
			foreach (MetaModel::ListAttributeDefs($sClass) as $oAttDef) {
				if ($oAttDef instanceof AttributeDate) {
					return $oAttDef;
				}
			}
		}
		$this->markTestSkipped('No date attribute in this datamodel.');
	}
}
