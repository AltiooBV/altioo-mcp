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
}
