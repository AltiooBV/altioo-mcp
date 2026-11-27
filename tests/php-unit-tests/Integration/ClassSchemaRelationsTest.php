<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * core_class_schema with its default blocks, relations included, on a class that has relations.
 *
 * The relations block listed the relations through MetaModel::EnumRelations(), which iTop 3.3 no longer has, so
 * the tool failed on 3.3 for every class whenever the block was asked for - which is the default.
 */
class ClassSchemaRelationsTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('Server')) {
			$this->markTestSkipped('Needs configuration management (Server).');
		}
	}

	public function testTheDefaultBlocksIncludeTheRelationsOfAClassThatHasSome(): void
	{
		$aSchema = ToolOutput::Decode(ClassSchema::execute('Server'));

		$this->assertArrayHasKey('relations', $aSchema);
		$this->assertNotSame([], $aSchema['relations'], 'a Server has relations, and the block lists none');
		foreach ($aSchema['relations'] as $sRelation => $aRelation) {
			$this->assertIsString($sRelation);
			$this->assertArrayHasKey('description', $aRelation);
			$aWays = array_intersect(['down', 'up'], array_keys($aRelation));
			$this->assertNotSame([], $aWays, "{$sRelation} is listed in no direction");
			$this->assertSame([], array_diff(array_keys($aRelation), ['description', 'down', 'up']), "{$sRelation} has a key that is not a direction");
			foreach ($aWays as $sWay) {
				$this->assertArrayHasKey('label', $aRelation[$sWay]);
				$this->assertArrayHasKey('description', $aRelation[$sWay]);
				$this->assertNotSame([], $aRelation[$sWay]['neighbours'], "{$sRelation} {$sWay} is listed with nothing it reaches");
				foreach ($aRelation[$sWay]['neighbours'] as $sQuery) {
					$this->assertIsString($sQuery);
					$this->assertNotSame('', $sQuery);
				}
			}
		}
	}

	/**
	 * A relation says what each direction means in iTop's own words, and lists the directions iTop has a query for: the
	 * same ones EnumRelationsEx() names, which is what core_object_get_related accepts. A direction a class has only
	 * upstream was left out before, while the walk accepted it.
	 */
	public function testEachDirectionIsTheOneAWalkAcceptsWithItsLabel(): void
	{
		$aRelations = ToolOutput::Decode(ClassSchema::execute('Server', 'relations'))['relations'];

		foreach (MetaModel::EnumRelationsEx('Server') as $sRelation => $aDirections) {
			$this->assertArrayHasKey($sRelation, $aRelations, "{$sRelation} is not listed");
			$this->assertEqualsCanonicalizing(array_keys($aDirections), array_values(array_intersect(['down', 'up'], array_keys($aRelations[$sRelation]))), "{$sRelation} lists other directions than iTop has");
			foreach ($aDirections as $sWay => $sLabel) {
				$this->assertSame($sLabel, $aRelations[$sRelation][$sWay]['label'], "{$sRelation} {$sWay} is not labelled as iTop labels it");
			}
		}
		$this->assertNotNull($aRelations['impacts']['down']['label'] ?? null, 'impacts has no label downstream');
		$this->assertNotNull($aRelations['impacts']['up']['label'] ?? null, 'impacts has no label upstream');
	}

	/** On an instance with iTop 3.3's flow-map module, its relation is listed with its own meaning, as any datamodel's would be. */
	public function testARelationAModuleDeclaresIsListedWithItsMeaning(): void
	{
		if (!MetaModel::IsValidClass('DataFlow')) {
			$this->markTestSkipped('Needs the flow-map module (iTop 3.3 and later).');
		}
		$aRelations = ToolOutput::Decode(ClassSchema::execute('Server', 'relations'))['relations'];

		$this->assertArrayHasKey('dataflows', $aRelations);
		$this->assertNotNull($aRelations['dataflows']['description']);
		$this->assertNotNull($aRelations['dataflows']['down']['label'] ?? null);
		$this->assertNotNull($aRelations['dataflows']['up']['label'] ?? null);
	}

	/** Asked for on its own, the block answers too, and the other blocks are not in the answer. */
	public function testTheRelationsBlockCanBeAskedForAlone(): void
	{
		$aSchema = ToolOutput::Decode(ClassSchema::execute('Server', 'relations'));

		$this->assertArrayHasKey('relations', $aSchema);
		$this->assertArrayNotHasKey('attributes', $aSchema);
	}
}
