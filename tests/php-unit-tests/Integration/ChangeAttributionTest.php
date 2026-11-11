<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use CMDBChange;
use CMDBObject;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The change this endpoint makes is written the way iTop writes anyone's.
 *
 * Combodo's personal data anonymizer selects the objects holding a person's
 * case-log entries by `CMDBChange.userinfo = <their name>`, exactly, so a line
 * with a "(MCP: tool)" suffix put an entry out of its reach (doc/adr/0009).
 * The line is iTop's default now; the origin is still the extension's; the
 * reason goes to the audit row's column and nowhere else.
 */
class ChangeAttributionTest extends ItopDataTestCaseAlias
{
	protected function tearDown(): void
	{
		ChangeTracking::Forget();
		parent::tearDown();
	}

	public function testTheChangeCarriesTheUsersPlainNameAndTheExtensionOrigin(): void
	{
		ChangeTracking::ForRequest(null);
		ChangeTracking::Explain('A reason that must not reach the change');

		$oOrg = $this->CreateOrganization('ChangeAttribution '.bin2hex(random_bytes(4)));
		$oChange = CMDBObject::GetCurrentChange(false);
		$this->assertNotNull($oChange, 'the write made a change');

		$this->assertSame(CMDBChange::GetCurrentUserName(), $oChange->Get('userinfo'), 'exactly the name, as REST writes it');
		$this->assertStringNotContainsString('MCP', (string)$oChange->Get('userinfo'));
		$this->assertStringNotContainsString('reason', (string)$oChange->Get('userinfo'));
		$this->assertSame('custom-extension', $oChange->Get('origin'));
		$this->assertSame('A reason that must not reach the change', ChangeTracking::Comment());
		$this->assertNotNull(MetaModel::GetObject('Organization', (int)$oOrg->GetKey()));
	}

	public function testTheReasonHasAColumnOnTheAuditRow(): void
	{
		$this->assertTrue(MetaModel::IsValidAttCode('AltiooEventMCPService', 'change_comment'));
		$this->assertTrue(MetaModel::GetAttributeDef('AltiooEventMCPService', 'change_comment')->IsNullAllowed());
	}
}
