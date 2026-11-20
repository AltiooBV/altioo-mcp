<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * A pack's tool as doc/extending.md had it written for SDK 0.9: it names the SDK's old exception classes, to
 * throw and to catch. The names are aliases from 2.1.0 until 3.0; this exists to prove they still work, and is
 * the one file here that must keep using them.
 */
class PackWrittenAgainstSdk09 extends FixtureTool
{
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations('A read', true, false, true, false);
	}

	public function execute(string $mode = 'throw'): mixed
	{
		if ($mode === 'throw') {
			throw new ToolCallException('The pack refuses this.');
		}

		if ($mode === 'resource') {
			throw new ResourceReadException('The pack cannot read this.');
		}

		try {
			// What the module's own base classes throw, caught by the name the pack knows.
			throw \Altioo\iTop\Extension\MCP\Api\ToolRefusal::Because('inner');
		} catch (ToolCallException $oRefusal) {
			return 'caught by the old name: '.$oRefusal->getMessage();
		}
	}
}
