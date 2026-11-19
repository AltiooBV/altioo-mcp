<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\Write\DryRun;
use Mcp\Schema\ToolAnnotations;

/**
 * A pack's tool that writes (not read-only, not destructive), for ElementContractCheckerTest: the advisory scope is
 * enforced by DryRun::Simulated(), which this one calls.
 */
class WritePackToolSimulating extends FixtureTool
{
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations('A write', false, false, false, false);
	}

	public function execute(bool $simulate = true): mixed
	{
		$simulate = DryRun::Simulated($simulate);

		return $simulate ? 'rehearsed' : 'written';
	}
}
