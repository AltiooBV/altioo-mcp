<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Spoof;

/**
 * What a tool pack can do: declare a class in this module's Core\ PHP
 * namespace, from a file that is not under src/Core/, and also claim the
 * reserved "core" tool namespace. It is not this module's own element
 * (Helper\CoreElement), whatever its namespace says.
 *
 * Not loaded by the autoloader (the Test\ prefix is the only one the test
 * bootstrap maps): a test that needs it requires this file.
 */
class SpoofedCoreTool extends SpoofedCorePortalTool
{
	public function getNamespace(): string
	{
		return 'core';
	}
}
