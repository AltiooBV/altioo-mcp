<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;

/**
 * Declares PortalAware and only talks about ObjectAccess::Current(): in a
 * comment, in a docblock and in a string, and a call to another class that
 * has a Current() method too. None of that is a call.
 */
class PortalAwarePackToolMentioning extends FixtureTool implements PortalAware
{
	/** Should call ObjectAccess::Current() but does not. */
	public function execute(): mixed
	{
		// ObjectAccess::Current()
		return self::Other()::Current() ?? 'ObjectAccess::Current()'.ObjectAccess::class;
	}

	private static function Other(): object
	{
		return new class () {
			public static function Current(): ?string
			{
				return null;
			}
		};
	}
}
