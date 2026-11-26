<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use appUserPreferences;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, S3: obsolete_ok on the bulk tools and the stimulus, with the meaning it has on update and delete. An
 * object that counts as obsolete while the account hides obsolete data is refused (a row of a bulk call fails, the others
 * go on) unless the call says it means it.
 */
class ObsoleteOkTest extends ItopDataTestCaseAlias
{
	private int $iObsolete = 0;

	private int $iLive = 0;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsObsoletable('Organization')) {
			$this->markTestSkipped('Needs an obsoletable class.');
		}
		$this->iObsolete = (int)$this->createObject('Organization', ['name' => 'ObsoleteOk '.bin2hex(random_bytes(3)), 'status' => 'inactive'])->GetKey();
		$this->iLive = (int)$this->CreateOrganization('ObsoleteOk live '.bin2hex(random_bytes(3)))->GetKey();
		appUserPreferences::SetPref('show_obsolete_data', false);
	}

	protected function tearDown(): void
	{
		appUserPreferences::SetPref('show_obsolete_data', MetaModel::GetConfig()->Get('obsolescence.show_obsolete_data'));
		parent::tearDown();
	}

	/** @return array<int, array<string, mixed>> The rows by id. */
	private function rows(array $aReport): array
	{
		$aById = [];
		foreach ($aReport['objects'] as $aRow) {
			$aById[(int)$aRow['id']] = $aRow;
		}

		return $aById;
	}

	public function testTheControlSingleUpdateRefusesIt(): void
	{
		$this->expectExceptionMessageMatches('/obsolete_ok=true/');
		ObjectUpdate::execute('Organization', $this->iObsolete, ['name' => 'x'], true);
	}

	public function testABulkUpdateFailsTheObsoleteRowAndGoesOnWithTheOthers(): void
	{
		$aRows = $this->rows(ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$this->iObsolete, $this->iLive], ['code' => 'OBS-1'], true)));

		$this->assertSame('error', $aRows[$this->iObsolete]['status']);
		$this->assertStringContainsString('obsolete_ok=true', $aRows[$this->iObsolete]['message']);
		$this->assertStringContainsString('modify', $aRows[$this->iObsolete]['message']);
		$this->assertSame('ok', $aRows[$this->iLive]['status'], 'one row that is refused does not cancel the others');
	}

	public function testABulkUpdateWithTheFlagActsOnIt(): void
	{
		$aRows = $this->rows(ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$this->iObsolete], ['code' => 'OBS-1'], true, null, true)));

		$this->assertSame('ok', $aRows[$this->iObsolete]['status']);
	}

	public function testABulkUpdateOfAnAccountThatShowsObsoleteDataIsUnchanged(): void
	{
		appUserPreferences::SetPref('show_obsolete_data', true);
		$aRows = $this->rows(ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$this->iObsolete], ['code' => 'OBS-1'], true)));

		$this->assertSame('ok', $aRows[$this->iObsolete]['status'], 'the guard is for accounts that cannot see it in their own searches');
	}

	public function testABulkDeleteFailsTheObsoleteRowAndTheFlagLetsItThrough(): void
	{
		$aRows = $this->rows(ToolOutput::Decode(ObjectBulkDelete::execute('Organization', [$this->iObsolete, $this->iLive], true)));
		$this->assertSame('error', $aRows[$this->iObsolete]['status']);
		$this->assertStringContainsString('delete', $aRows[$this->iObsolete]['message']);
		$this->assertStringContainsString('obsolete_ok=true', $aRows[$this->iObsolete]['message']);
		$this->assertSame('ok', $aRows[$this->iLive]['status']);

		$aRows = $this->rows(ToolOutput::Decode(ObjectBulkDelete::execute('Organization', [$this->iObsolete], true, null, true)));
		$this->assertSame('ok', $aRows[$this->iObsolete]['status']);
	}

	public function testNothingWasWrittenByTheRefusedRows(): void
	{
		ObjectBulkUpdate::execute('Organization', [$this->iObsolete], ['code' => 'REFUSED'], false, 'test');

		$this->assertNotSame('REFUSED', (string)MetaModel::GetObject('Organization', $this->iObsolete)->Get('code'));
	}

	public function testTheStimulusToolOffersTheFlagWithTheSameDefault(): void
	{
		$aSchema = (new ObjectApplyStimulus())->getInputSchema();

		$this->assertFalse($aSchema['properties']['obsolete_ok']['default']);
		$this->assertNotContains('obsolete_ok', $aSchema['required']);
		$this->assertTrue((new \ReflectionMethod(ObjectApplyStimulus::class, 'execute'))->getParameters()[6]->isOptional());
	}
}
