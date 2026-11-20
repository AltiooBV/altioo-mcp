<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Mcp\Schema\ToolAnnotations;

/**
 * A pack's read-only tool whose execute() takes a parameter typed mixed and one with no type: SDK 0.10 hands a nested JSON
 * object to those as a stdClass, where it handed an array.
 */
class MixedParameterPackTool extends FixtureTool
{
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations('A read', true, false, true, false);
	}

	public function execute(mixed $fields = null, $filters = null, array $ids = [], string|int $name = ''): mixed
	{
		return 'executed';
	}
}
