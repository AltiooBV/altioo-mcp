<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated;
use Altioo\iTop\Extension\MCP\Helper\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * A relation names the classes it reaches (doc/adr/0012): what the schema lists for a class's relations is the relation
 * queries, each keyed by the class that defines it and the neighbour it leads to, and each selecting a target class. An
 * excluded class must not be reachable through one - not by name in the answer, and not as a relation that exists only to
 * lead to it.
 */
class SchemaRelationsExclusionTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Server', 'Hypervisor', 'Contact'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
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
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_ATTRIBUTES, []);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	/** @return array<string, array<int, string>> */
	private function relationsOfServer(): array
	{
		return ToolOutput::Decode(ClassSchema::execute('Server', 'relations'))['relations'];
	}

	public function testAClassReachedThroughARelationIsNotListedAsReached(): void
	{
		$aBefore = $this->relationsOfServer();
		$this->assertContains('Server_hypervisor', $aBefore['impacts'] ?? [], 'the control: a Server reaches a Hypervisor');

		$this->exclude(['Hypervisor']);
		$aAfter = $this->relationsOfServer();

		$this->assertNotContains('Server_hypervisor', $aAfter['impacts'] ?? [], 'a relation that leads to an excluded class is listed');
		$this->assertStringNotContainsStringIgnoringCase('hypervisor', json_encode($aAfter), 'the excluded class is named by a relation');
		// What is not about the excluded class is untouched.
		$this->assertContains('FunctionalCI_contact', $aAfter['impacts'] ?? [], 'a relation to another class was dropped');
	}

	/** An excluded class takes its subclasses with it: excluding the parent drops the query that selects a child. */
	public function testAnExcludedParentTakesItsSubclassesWithIt(): void
	{
		if (!MetaModel::IsValidClass('VirtualHost') || !MetaModel::IsParentClass('VirtualHost', 'Hypervisor')) {
			$this->markTestSkipped('Needs Hypervisor below VirtualHost.');
		}
		$this->assertContains('Server_hypervisor', $this->relationsOfServer()['impacts'] ?? [], 'the control');

		// The query selects a Hypervisor, which is a VirtualHost; a Server is not one, so the schema of Server is still served.
		$this->exclude(['VirtualHost']);

		$this->assertNotContains('Server_hypervisor', $this->relationsOfServer()['impacts'] ?? []);
	}

	public function testTheClassResourceAnswersAsTheToolDoes(): void
	{
		$this->exclude(['Hypervisor']);

		$this->assertSame(
			$this->relationsOfServer(),
			ToolOutput::Decode(ClassSchema::execute('Server'))['relations'],
			'the default blocks and the relations block alone disagree'
		);
	}

	/** The walk validates the relation it is asked for against what the class has: no relation exists only to reach an excluded class. */
	public function testTheRelationsAWalkMayUseAreTheSameOnes(): void
	{
		$this->exclude(['Hypervisor']);
		$aRelations = array_keys($this->relationsOfServer());

		$this->assertNotSame([], $aRelations);
		// Every relation the schema lists is one a walk accepts; the walk refuses the others in its own words.
		$sTarget = (string)MetaModel::GetObjectFromOQL('SELECT Server', [], false)?->GetKey() ?: '0';
		if ($sTarget === '0') {
			$this->markTestSkipped('Needs a Server.');
		}
		foreach ($aRelations as $sRelation) {
			$this->assertNotNull(ObjectGetRelated::execute('Server', (int)$sTarget, $sRelation, 'down', 1));
		}
	}
}
