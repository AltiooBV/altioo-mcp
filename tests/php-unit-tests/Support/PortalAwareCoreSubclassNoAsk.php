<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;

/**
 * The tool doc/extending.md teaches under "Augmenting a core tool", written
 * badly: a subclass of a core portal tool that replaces execute() and reads
 * through UserRights alone. It inherits PortalAware, and its core parent's
 * source asks ObjectAccess::Current(), which must not count for it.
 */
class PortalAwareCoreSubclassNoAsk extends ObjectGet
{
	public static function execute(string $class, int $id, string $output_fields = '*'): mixed
	{
		return \UserRights::IsActionAllowed($class, UR_ACTION_READ) ? ['class' => $class, 'id' => $id] : null;
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
