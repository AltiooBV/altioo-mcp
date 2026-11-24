<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
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

	/** C4: required_only listed seven attributes of a UserRequest, and a create with three passes. */
	public function testRequiredOnlyKeepsWhatACreateMustBeGiven(): void
	{
		$aFull = $this->schema('UserRequest')['attributes'];
		$aRequired = $this->schema('UserRequest', DatamodelReader::BLOCKS_ALL, DatamodelReader::ATTRIBUTES_ALL, true)['attributes'];

		$this->assertEqualsCanonicalizing(['org_id', 'title', 'description'], array_keys($aRequired));
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
}
