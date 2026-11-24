<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, S3 (the fix): an object's id is at least 1 wherever a tool takes one, in its schema as well as in its
 * code. core_object_update and core_object_apply_stimulus refused 0 and negative ids and said nothing of it in the schema, so a
 * client validating arguments sent the call to find out.
 */
class IdSchemaMinimumTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	public function testEveryIntegerIdArgumentHasAMinimumOfOne(): void
	{
		$aChecked = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aProperties = $oTool->getInputSchema()['properties'] ?? [];
			if (($aProperties['id']['type'] ?? null) !== 'integer') {
				continue;
			}
			$aChecked[] = $sName;
			$this->assertSame(1, $aProperties['id']['minimum'] ?? null, "{$sName} takes an integer id with no minimum of 1");
		}

		$this->assertContains('core_object_update', $aChecked);
		$this->assertContains('core_object_apply_stimulus', $aChecked);
	}
}
