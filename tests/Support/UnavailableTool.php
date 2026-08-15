<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

class UnavailableTool extends FixtureTool
{
	public function isAvailable(): bool
	{
		return false;
	}
}
