<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
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
		foreach ($aSchema['relations'] as $sRelation => $aNeighbours) {
			$this->assertIsString($sRelation);
			$this->assertNotSame([], $aNeighbours, "{$sRelation} is listed with nothing it reaches");
			foreach ($aNeighbours as $sQuery) {
				$this->assertIsString($sQuery);
				$this->assertNotSame('', $sQuery);
			}
		}
	}

	/** Asked for on its own, the block answers too, and the other blocks are not in the answer. */
	public function testTheRelationsBlockCanBeAskedForAlone(): void
	{
		$aSchema = ToolOutput::Decode(ClassSchema::execute('Server', 'relations'));

		$this->assertArrayHasKey('relations', $aSchema);
		$this->assertArrayNotHasKey('attributes', $aSchema);
	}
}
