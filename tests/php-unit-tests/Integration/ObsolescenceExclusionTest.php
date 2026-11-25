<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use appUserPreferences;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23 (batch 3, follow-up): the obsolescence condition reads attributes, and an attribute the operator excluded
 * (doc/adr/0012) is not named by it, in the schema, in a write's answer, in a single refusal or in a bulk row. Left out whole, the
 * wording reads as for a class that states no condition. An account holding an exempt profile still reads it (ADR 0013).
 */
class ObsolescenceExclusionTest extends ItopDataTestCaseAlias
{
	private int $iObsolete = 0;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsObsoletable('Organization')) {
			$this->markTestSkipped('Needs an obsoletable class.');
		}
		$this->iObsolete = (int)$this->createObject('Organization', ['name' => 'ObsExcl '.bin2hex(random_bytes(3)), 'status' => 'inactive'])->GetKey();
		appUserPreferences::SetPref('show_obsolete_data', false);
		$this->exclude([], [], []);
	}

	protected function tearDown(): void
	{
		$this->exclude([], [], []);
		appUserPreferences::SetPref('show_obsolete_data', MetaModel::GetConfig()->Get('obsolescence.show_obsolete_data'));
		parent::tearDown();
	}

	/**
	 * @param array<int, string>                $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 * @param array<int, string>                $aExempt
	 */
	private function exclude(array $aClasses, array $aAttributes, array $aExempt): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, $aExempt);
		ExclusionPolicy::Forget();
	}

	private function refusal(callable $f): string
	{
		try {
			$f();
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail('not refused');
	}

	/** @return array<string, string> The four places a caller can read the condition. */
	private function places(): array
	{
		$aRows = ToolOutput::Decode(ObjectBulkUpdate::execute('Organization', [$this->iObsolete], ['code' => 'OBX'], true));
		$aDel = ToolOutput::Decode(ObjectBulkDelete::execute('Organization', [$this->iObsolete], true));
		$aSchema = ToolOutput::Decode(ClassSchema::execute('Organization'));

		return [
			'schema'        => json_encode($aSchema['obsolescence']),
			'single update' => $this->refusal(fn () => ObjectUpdate::execute('Organization', $this->iObsolete, ['code' => 'OBX'], true)),
			'single delete' => $this->refusal(fn () => ObjectDelete::execute('Organization', $this->iObsolete, true)),
			'bulk update'   => $aRows['objects'][0]['message'],
			'bulk delete'   => $aDel['objects'][0]['message'],
		];
	}

	public function testTheControlStatesTheConditionAsOqlEverywhere(): void
	{
		foreach ($this->places() as $sWhere => $sText) {
			$this->assertStringContainsString("status = 'inactive'", $sText, $sWhere);
			$this->assertStringNotContainsString('COALESCE', $sText, $sWhere.': the refusals say plain OQL too');
			$this->assertStringNotContainsString('`', $sText, $sWhere);
		}
	}

	public function testAnExcludedAttributeIsNamedNowhere(): void
	{
		$this->exclude([], ['Organization' => ['status']], []);

		$aPlaces = $this->places();
		$this->assertNull(DatamodelReader::ObsolescenceCondition('Organization'));
		$this->assertStringContainsString('"condition":null', $aPlaces['schema']);
		foreach (['single update', 'single delete', 'bulk update', 'bulk delete'] as $sWhere) {
			$this->assertStringNotContainsString('status', $aPlaces[$sWhere], $sWhere);
			$this->assertStringNotContainsString('inactive', $aPlaces[$sWhere], $sWhere);
			$this->assertStringContainsString('counts as obsolete and your account does not show', $aPlaces[$sWhere], $sWhere.': it reads as one that states no condition');
			$this->assertStringContainsString('obsolete_ok=true', $aPlaces[$sWhere], $sWhere.': the way forward is still said');
		}
	}

	public function testAWriteAnswerDoesNotNameItEither(): void
	{
		$this->exclude([], ['Organization' => ['status']], []);
		appUserPreferences::SetPref('show_obsolete_data', true);

		$a = ToolOutput::Decode(ObjectUpdate::execute('Organization', $this->iObsolete, ['code' => 'OBX'], true));

		$this->assertNull($a['after']['obsolescence']['condition'] ?? null);
		$this->assertStringNotContainsString('inactive', json_encode($a));
	}

	public function testAKeyWhoseTargetIsExcludedIsNotNamedThroughAnExternalField(): void
	{
		if (!MetaModel::IsValidClass('MiddlewareInstance')) {
			$this->markTestSkipped('Needs the configuration management module.');
		}
		$this->assertNotNull(DatamodelReader::ObsolescenceCondition('MiddlewareInstance'), 'the control');

		$this->exclude(['Middleware'], [], []);

		$this->assertNull(DatamodelReader::ObsolescenceCondition('MiddlewareInstance'));
	}

	public function testAnExemptAccountStillReadsIt(): void
	{
		$iOrg = (int)$this->CreateOrganization('ObsExempt '.bin2hex(random_bytes(3)))->GetKey();
		$iProfile = (int)(new \DBObjectSet(\DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'obs-exempt-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'Obs-Exempt-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(1, $iOrg)->GetKey());
		UserRights::Login($sLogin);
		$this->exclude([], ['Organization' => ['status']], ['Administrator']);
		$this->assertTrue(ExclusionPolicy::IsExempt(), 'the account holds the exempt profile');

		$this->assertSame("(status = 'inactive')", DatamodelReader::ObsolescenceCondition('Organization'));
	}

	public function testAnUnresolvableConditionIsTreatedAsExcluded(): void
	{
		// A class that is not one: nothing to resolve, nothing said.
		$this->assertNull(DatamodelReader::ObsolescenceCondition('NoSuchClassAtAll'));
	}
}
