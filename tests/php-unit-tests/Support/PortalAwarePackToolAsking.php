<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;

/**
 * A pack's portal-aware tool that asks ObjectAccess::Current() in code, as
 * ADR 0017 requires of one the operator may list.
 */
class PortalAwarePackToolAsking extends FixtureTool implements PortalAware
{
	public function execute(): mixed
	{
		return ObjectAccess::Current() === null ? 'none' : 'asked';
	}
}
