<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;

/**
 * A subclass of a core portal tool that does not replace execute(): it runs
 * core's code, which asks ObjectAccess::Current(), so the contract passes it.
 * It only changes what it says about itself.
 */
class PortalAwareCoreSubclassInheriting extends ObjectGet
{
	public function getDescription(): ?string
	{
		return 'ObjectGet, described for this pack.';
	}

	public function getNamespace(): string
	{
		return 'test';
	}

	public function overrides(): ?string
	{
		return 'core_object_get';
	}
}
