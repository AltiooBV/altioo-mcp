<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, HA-4 (and PA-3): core_object_bulk_create validated through a copy of WriteValues::Validated() that
 * never asked ExclusionPolicy. With `Person.phone` excluded, a bulk create of `"phone": "+3355507777"` answered "Would be
 * created" and committed it, and `zzphone` was "Unknown attribute": the dry run was an oracle for the excluded name and the
 * write put a value into a column the operator keeps out of this channel. Every bulk tool now validates through
 * the one validator.
 */
class ExclusionBulkCreateTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidAttCode('Person', 'phone')) {
			$this->markTestSkipped('Needs Person.phone.');
		}
		$this->iOrg = (int)$this->CreateOrganization('BulkExcl '.bin2hex(random_bytes(4)))->GetKey();
		$this->exclude(['Person' => ['phone']]);
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
	}

	/** @param array<string, array<int, string>> $aAttributes */
	private function exclude(array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, []);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	/** @return array<string, mixed> */
	private function rowOf(string $sAttribute, bool $bSimulate): array
	{
		$aReport = ToolOutput::Decode(ObjectBulkCreate::execute('Person', [[
			'name' => 'Zxqbulk'.bin2hex(random_bytes(3)),
			'first_name' => 'Bulk',
			'org_id' => $this->iOrg,
			$sAttribute => '+3355507777',
		]], $bSimulate));

		return $aReport['objects'][0] ?? [];
	}

	private function persons(string $sPhone): int
	{
		return (new DBObjectSet(DBObjectSearch::FromOQL('SELECT Person WHERE phone = :p', ['p' => $sPhone])))->Count();
	}

	public function testABulkCreateOfAnExcludedAttributeIsRefusedLikeAnUnknownOne(): void
	{
		$aExcluded = $this->rowOf('phone', true);
		$aUnknown = $this->rowOf('zzphone', true);

		$this->assertSame('error', $aExcluded['status'] ?? null, 'the dry run of a bulk create of an excluded attribute said it would be created');
		$this->assertSame(
			str_replace('zzphone', '#', (string)$aUnknown['message']),
			str_replace('phone', '#', (string)$aExcluded['message']),
			'the excluded attribute was told apart from one that does not exist'
		);
		$this->assertStringContainsString("Unknown attribute 'phone'", (string)$aExcluded['message']);
	}

	public function testABulkCreateDoesNotCommitAnExcludedAttribute(): void
	{
		$aRow = $this->rowOf('phone', false);

		$this->assertSame('error', $aRow['status'] ?? null);
		$this->assertSame(0, $this->persons('+3355507777'), 'the excluded column was written');
	}

	public function testTheControlAnAttributeThatIsNotExcludedIsStillCreated(): void
	{
		$aRow = $this->rowOf('mobile_phone', true);

		$this->assertSame('ok', $aRow['status'] ?? null, (string)($aRow['message'] ?? ''));
	}

	public function testABulkUpdateOfAnExcludedAttributeIsStillRefused(): void
	{
		$iPerson = (int)$this->CreatePerson(7, $this->iOrg)->GetKey();

		try {
			ObjectBulkUpdate::execute('Person', [$iPerson], ['phone' => '+1']);
			$this->fail('the bulk update was served');
		} catch (\Mcp\Exception\ToolCallException $e) {
			$this->assertStringContainsString("Unknown attribute 'phone'", $e->getMessage());
		}
	}
}
