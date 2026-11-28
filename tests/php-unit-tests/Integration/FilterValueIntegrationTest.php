<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalRows;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Retest of 2026-11-28, B12: `sla_tto_passed: "yes"` counted the requests that had not breached, because the database
 * reads 'yes' as 0, and `status: "nonsense"` counted none instead of being refused.
 *
 * @group integration
 */
class FilterValueIntegrationTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest') || !MetaModel::IsValidAttCode('UserRequest', 'sla_tto_passed')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}
		$this->exclude([]);
		$this->iOrg = (int)$this->CreateOrganization('FilterValue '.bin2hex(random_bytes(4)))->GetKey();
		$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->CreatePerson(1, $this->iOrg)->GetKey()]);
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
	}

	public function testAStopwatchFlagTakesYesAndNoForOneAndZero(): void
	{
		$this->assertSame(1, $this->oql("sla_tto_passed = 0"), 'the premise: one request, not passed');
		$this->assertSame(1, $this->oql("sla_tto_passed = 'yes'"), 'the premise: the database reads yes as 0');

		foreach (['yes', 'Yes', 1, '1', true] as $mPassed) {
			$this->assertSame(0, $this->total(['sla_tto_passed' => $mPassed]), var_export($mPassed, true));
		}
		foreach (['no', 0, '0', false] as $mPassed) {
			$this->assertSame(1, $this->total(['sla_tto_passed' => $mPassed]), var_export($mPassed, true));
		}
		$this->assertRefused('UserRequest', ['sla_tto_passed' => 'perhaps'], "The filter on 'sla_tto_passed' takes one of its values, by code or label: 0 (no), 1 (yes).");
	}

	public function testAStatusTakesItsCodeOrItsLabelAndNothingElse(): void
	{
		$sLabel = (string)MetaModel::GetAttributeDef('UserRequest', 'status')->GetAllowedValues()['new'];
		foreach (['new', 'NEW', $sLabel] as $sStatus) {
			$this->assertSame(1, $this->total(['status' => $sStatus]), $sStatus);
		}
		$this->assertSame(0, $this->total(['status' => 'closed']));

		foreach (['nonsense', '', 7, true] as $mStatus) {
			$sRefusal = $this->refusal('UserRequest', ['status' => $mStatus]);
			$this->assertStringStartsWith("The filter on 'status' takes one of its values, by code or label: ", $sRefusal);
			$this->assertStringContainsString('closed', $sRefusal);
			$this->assertStringNotContainsString('nonsense', $sRefusal, 'the value is quoted back');
		}
	}

	/** A flag shown through an external key is the flag: 'yes' read as 0 there too. */
	public function testAnExternalFieldTakesTheValuesOfWhatItShows(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'org_id_obsolescence_flag')) {
			$this->markTestSkipped('Needs UserRequest.org_id_obsolescence_flag.');
		}
		$this->assertSame(0, $this->total(['org_id_obsolescence_flag' => 'yes']));
		$this->assertSame(1, $this->total(['org_id_obsolescence_flag' => 'no']));
		$this->refusal('UserRequest', ['org_id_obsolescence_flag' => 'maybe']);
	}

	/** What holds no list is left to the search, as it was. */
	public function testAnAttributeWithoutAListIsNotHeldToOne(): void
	{
		$this->assertSame(0, $this->total(['title' => 'nonsense']));
		$this->assertSame(1, $this->total(['org_id' => $this->iOrg]));
	}

	/** doc/adr/0012: an excluded class is neither named in the refusal nor found by its code or its label. */
	public function testAnExcludedClassIsNoValueAndIsNotNamed(): void
	{
		if (!MetaModel::IsValidClass('Server')) {
			$this->markTestSkipped('Needs Server.');
		}
		$sLabel = MetaModel::GetName('Server');
		$this->assertSame(
			ToolOutput::Decode(ObjectCount::execute('FunctionalCI', ['finalclass' => 'Server']))['total'],
			ToolOutput::Decode(ObjectCount::execute('FunctionalCI', ['finalclass' => $sLabel]))['total'],
			'the control: served, the label stands for the class'
		);

		// An entry of the list the refusal names: the code at its start, with or without a label. `DB Server` is another's.
		$sEntry = '/(?:: |, )Server(?: \(|,|\.)/';
		$this->assertMatchesRegularExpression($sEntry, $this->refusal('FunctionalCI', ['finalclass' => 'Zzzzzz']), 'the control: served, it is named');

		$this->exclude(['Server']);
		$sUnknown = $this->refusal('FunctionalCI', ['finalclass' => 'Zzzzzz']);
		foreach (['Server', $sLabel, 'server'] as $sValue) {
			$this->assertSame($sUnknown, $this->refusal('FunctionalCI', ['finalclass' => $sValue]), $sValue);
		}
		$this->assertDoesNotMatchRegularExpression($sEntry, $sUnknown);
	}

	/** A portal list whose tabs are the values of an attribute: a tab that is no value of it lists nothing, so it is refused. */
	public function testAPortalTabMadeOfAValueIsHeldToTheSameList(): void
	{
		$aList = ['tabs' => [], 'tabs_by' => 'status'];
		$sLabel = (string)MetaModel::GetAttributeDef('UserRequest', 'status')->GetAllowedValues()['new'];
		foreach (['new', $sLabel] as $sTab) {
			$oQuery = PortalRows::TabQuery(DBObjectSearch::FromOQL('SELECT UserRequest WHERE org_id = '.$this->iOrg), $aList, $sTab);
			$this->assertSame(1, (new DBObjectSet($oQuery))->Count(), $sTab);
		}

		try {
			PortalRows::TabQuery(DBObjectSearch::FromOQL('SELECT UserRequest'), $aList, 'nonsense');
			$this->fail('a tab that is no value was taken');
		} catch (ToolCallException $e) {
			$this->assertStringStartsWith("This list has no such tab. The filter on 'status' takes", $e->getMessage());
		}
	}

	private function oql(string $sCondition): int
	{
		return (new DBObjectSet(DBObjectSearch::FromOQL("SELECT UserRequest WHERE org_id = {$this->iOrg} AND {$sCondition}")))->Count();
	}

	/**
	 * What search and count find among this test's requests, which has to be the same.
	 *
	 * @param array<string, mixed> $aFilters
	 */
	private function total(array $aFilters): int
	{
		$aFilters += ['org_id' => $this->iOrg];
		$iCount = ToolOutput::Decode(ObjectCount::execute('UserRequest', $aFilters))['total'];
		$aFound = ToolOutput::Decode(ObjectSearchByClass::execute('UserRequest', $aFilters));
		$this->assertSame($iCount, $aFound['total'], 'search and count disagree');

		return $iCount;
	}

	/**
	 * The words both tools refuse the filter with.
	 *
	 * @param array<string, mixed> $aFilters
	 */
	private function refusal(string $sClass, array $aFilters): string
	{
		$aWords = [];
		foreach ([ObjectSearchByClass::class, ObjectCount::class] as $sTool) {
			try {
				$sTool::execute($sClass, $aFilters);
				$this->fail("{$sTool} answered ".json_encode($aFilters));
			} catch (ToolCallException $e) {
				$aWords[] = $e->getMessage();
			}
		}
		$this->assertSame($aWords[0], $aWords[1]);

		return $aWords[0];
	}

	/** @param array<string, mixed> $aFilters */
	private function assertRefused(string $sClass, array $aFilters, string $sWords): void
	{
		$this->assertSame($sWords, $this->refusal($sClass, $aFilters));
	}

	/** @param array<int, string> $aClasses */
	private function exclude(array $aClasses): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, []);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}
}
