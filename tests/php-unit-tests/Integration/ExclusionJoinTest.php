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
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, HA-3: the OQL refusal for a class that has an excluded subclass counted textual mentions and
 * refused at two, so one mention as a joined class or inside a sub-query passed. With `Person` excluded,
 * `SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id WHERE c.email LIKE 'agatha%'` answered whether an excluded
 * person's email began so. The rule (ADR 0012) is "cannot be joined or sub-queried": a class with an excluded subclass is
 * the selected class of a query, or it is not in it.
 */
class ExclusionJoinTest extends ItopDataTestCaseAlias
{
	private const REFUSAL = 'This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Person', 'Contact', 'Organization', 'Team'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}
		$this->exclude(['Person']);
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

	/** @return array<string, array{0: string}> */
	public static function refusedProvider(): array
	{
		return [
			'a joined class' => ["SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id WHERE c.email LIKE 'agatha%'"],
			'a joined class, nothing on it' => ['SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id'],
			'a sub-query' => ["SELECT Organization WHERE id IN (SELECT Contact WHERE email LIKE 'agatha%')"],
			'a sub-query one level down' => ["SELECT Organization WHERE id IN (SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id)"],
			'a sub-query in a team query' => ["SELECT Team AS t WHERE t.org_id IN (SELECT Contact AS c WHERE c.phone LIKE '1%')"],
			'a union of the class with itself' => ["SELECT Contact WHERE email LIKE 'a%' UNION SELECT Contact WHERE email LIKE 'b%'"],
		];
	}

	/** @dataProvider refusedProvider */
	public function testAClassWithAnExcludedSubclassIsRefusedAnywhereButAsTheSelectedClass(string $sOql): void
	{
		$this->assertSame(self::REFUSAL, ExclusionSearches::OqlRefusal($sOql));

		try {
			ObjectSearchByOQL::execute($sOql);
			$this->fail('the query was served');
		} catch (ToolCallException $e) {
			$this->assertSame(self::REFUSAL, $e->getMessage());
		}
		try {
			ObjectCount::execute('', [], $sOql);
			$this->fail('the count was served');
		} catch (ToolCallException $e) {
			$this->assertSame(self::REFUSAL, $e->getMessage());
		}
	}

	/** @return array<string, array{0: string}> */
	public static function servedProvider(): array
	{
		return [
			'the class selected, with a condition' => ["SELECT Contact WHERE email LIKE 'zzz%'"],
			'the class selected, from a join on a class with no excluded subclass' => ["SELECT c FROM Contact AS c JOIN Organization AS o ON c.org_id = o.id WHERE o.name LIKE 'zzz%'"],
			'an organisation and its parent' => ["SELECT Organization AS o JOIN Organization AS p ON o.parent_id = p.id WHERE p.name LIKE 'zzz%'"],
			'a sub-query on a class with no excluded subclass' => ["SELECT Contact AS c WHERE c.org_id IN (SELECT Organization AS o WHERE o.name LIKE 'zzz%')"],
		];
	}

	/** @dataProvider servedProvider */
	public function testTheSelectedClassAndUnrelatedClassesAreStillServed(string $sOql): void
	{
		$this->assertNull(ExclusionSearches::OqlRefusal($sOql));
		$this->assertSame(0, ToolOutput::Decode(ObjectSearchByOQL::execute($sOql))['total']);
	}

	public function testNothingIsRefusedWhileNothingIsExcluded(): void
	{
		$this->exclude([]);

		$this->assertNull(ExclusionSearches::OqlRefusal('SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id'));
	}
}
