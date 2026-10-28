<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\PortalAware;

/**
 * A pack's tool claiming to enforce the portal's rules, which no pack can do
 * yet: PortalPolicy is not on the surface a pack may build on.
 */
class PortalAwarePackTool extends FixtureTool implements PortalAware
{
}
