<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Read\CurrentUserReader;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/** Review 2026-11-23, C10: the zone the instance's configuration sets, and the time now in it, with the offset. */
class CurrentUserTimeTest extends ItopDataTestCaseAlias
{
	public function testTheAnswerNamesTheConfiguredZoneAndTheTimeNowWithItsOffset(): void
	{
		$iOrg = (int)$this->CreateOrganization('Time '.bin2hex(random_bytes(4)))->GetKey();
		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'time-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'Time-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey());
		UserRights::Login($sLogin);

		$sBefore = time();
		$a = CurrentUserReader::Payload();

		$sConfigured = (string)MetaModel::GetConfig()->Get('timezone');
		$this->assertSame($sConfigured !== '' ? $sConfigured : date_default_timezone_get(), $a['timezone']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $a['now']);
		$this->assertEqualsWithDelta($sBefore, strtotime($a['now']), 5, 'it is the time now');
		$this->assertSame((new \DateTimeImmutable($a['now']))->getTimezone()->getOffset(new \DateTimeImmutable($a['now'])), (new \DateTimeZone($a['timezone']))->getOffset(new \DateTimeImmutable($a['now'])), 'the offset is the zone\'s own');
	}
}
