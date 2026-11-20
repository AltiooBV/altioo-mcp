<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Mcp\Schema\ToolAnnotations;

/**
 * A pack's read-only tool, for the advisory test: an advisory credential is still served it (ADR 0022).
 */
class ReadPackTool extends FixtureTool
{
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations('A read', true, false, true, false);
	}
}
