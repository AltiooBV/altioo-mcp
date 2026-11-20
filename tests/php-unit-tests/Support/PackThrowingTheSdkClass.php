<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Exception\ToolCallException;

/**
 * A pack's tool that throws the SDK's 0.10 class by name: it works, and the contract points to ToolRefusal::Because().
 */
class PackThrowingTheSdkClass extends FixtureTool
{
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations('A read', true, false, true, false);
	}

	public function execute(): mixed
	{
		throw new ToolCallException('Refused.');
	}
}
