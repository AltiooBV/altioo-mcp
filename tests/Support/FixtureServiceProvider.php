<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;

class FixtureServiceProvider implements iMCPServiceProvider
{
	public static int $iCallCount = 0;

	public static function RegisterServiceProvider(): void
	{
		self::$iCallCount++;
		MCPRegistry::RegisterTool(new FixtureTool());
	}
}
