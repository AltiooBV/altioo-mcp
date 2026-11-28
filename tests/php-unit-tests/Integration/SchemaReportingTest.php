<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * What core_class_schema and core_class_list say, read against a compiled datamodel (review 2026-11-23, batch 2).
 */
class SchemaReportingTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the request management datamodel (UserRequest).');
		}
		$this->exclude([]);
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
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

	/** @return array<string, mixed> */
	private function schema(string $sClass, string $sInclude = DatamodelReader::BLOCKS_ALL, string $sAttributes = DatamodelReader::ATTRIBUTES_ALL, bool $bRequiredOnly = false): array
	{
		return ToolOutput::Decode(ClassSchema::execute($sClass, $sInclude, $sAttributes, $bRequiredOnly));
	}

	/** C9: an attribute nobody writes said readOnly: true and modify: "yes" together. */
	public function testADerivedAttributeIsNotGradedAsOneTheCallerMayModify(): void
	{
		$aDerived = $this->schema('UserRequest')['derived'];

		$this->assertNotSame([], $aDerived);
		foreach ($aDerived as $sCode => $aAttribute) {
			$this->assertTrue($aAttribute['readOnly'], $sCode);
			$this->assertSame('no', $aAttribute['modify'], "{$sCode} is read-only and must not be graded as writable");
		}
	}

	/**
	 * C4: required_only listed seven attributes of a UserRequest, and a create passes with few. Those are the ones the
	 * datamodel requires and, since the retest of 2026-11-28 (B13), the caller its first state makes mandatory.
	 */
	public function testRequiredOnlyKeepsWhatACreateMustBeGiven(): void
	{
		$aFull = $this->schema('UserRequest')['attributes'];
		$aRequired = $this->schema('UserRequest', DatamodelReader::BLOCKS_ALL, DatamodelReader::ATTRIBUTES_ALL, true)['attributes'];

		$this->assertEqualsCanonicalizing(['org_id', 'caller_id', 'title', 'description'], array_keys($aRequired));
		$this->assertFalse($aRequired['caller_id']['required'], 'the column may be null: the state asks for it');
		$this->assertTrue($aRequired['caller_id']['mandatoryOnCreate']);
		foreach (['status', 'impact', 'urgency', 'priority'] as $sCode) {
			$this->assertTrue($aFull[$sCode]['required'], "{$sCode} is still required by the datamodel");
			$this->assertArrayHasKey('default', $aFull[$sCode], "{$sCode} says what it is given when none is sent");
		}
		$this->assertSame('new', $aFull['status']['default']);
		$this->assertArrayNotHasKey('default', $aFull['title'], 'an empty default is not reported');
		$this->assertArrayNotHasKey('default', $aFull['org_id'], 'an empty key is not a default');
	}

	/** C3: the lifecycle block gave stimulus and target state and nothing of what the state asks for. */
	public function testALifecycleStateSaysWhatEnteringItAsksFor(): void
	{
		$aStates = $this->schema('UserRequest', 'lifecycle')['lifecycle']['states'];

		$this->assertContains('solution', $aStates['resolved']['mandatory'] ?? [], 'ev_resolve needs a solution, and the schema must say so before the refusal does');
		$this->assertContains('team_id', $aStates['assigned']['mandatory'] ?? [], 'ev_assign needs a team');
		$this->assertContains('agent_id', $aStates['assigned']['mandatory'] ?? []);
		foreach ($aStates as $sState => $aState) {
			foreach (['mandatory', 'prompted', 'readOnly'] as $sKey) {
				if (array_key_exists($sKey, $aState)) {
					$this->assertNotSame([], $aState[$sKey], "{$sState}.{$sKey} is left out when empty");
				}
			}
		}
	}

	/** C19: nothing said how to write a link set; [{"functionalci_id": 1}] had to be guessed. */
	public function testALinkSetSaysHowItIsWritten(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'functionalcis_list')) {
			$this->markTestSkipped('Needs UserRequest.functionalcis_list.');
		}
		$aHint = $this->schema('UserRequest')['attributes']['functionalcis_list'];

		$this->assertStringContainsString('[{"functionalci_id": 1}]', $aHint['writeHint']);
		$this->assertStringContainsString('lnkFunctionalCIToTicket', $aHint['writeHint']);
		$this->assertStringContainsString('left out is removed', $aHint['writeHint']);
	}

	/** C19 and ADR 0012: the hint names no class or attribute the operator excluded. */
	public function testTheLinkSetHintNamesNothingExcluded(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'functionalcis_list')) {
			$this->markTestSkipped('Needs UserRequest.functionalcis_list.');
		}
		$this->exclude(['FunctionalCI']);

		$sSchema = json_encode($this->schema('UserRequest'));

		$this->assertStringNotContainsStringIgnoringCase('functionalci', $sSchema);
	}
}
