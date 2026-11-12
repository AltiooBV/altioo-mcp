<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;

/**
 * A pack's tool claiming to enforce the portal's rules without ever asking
 * ObjectAccess::Current(): served in portal mode only if the operator lists it
 * (ADR 0017), and reported by ElementContract.
 */
class PortalAwarePackTool extends FixtureTool implements PortalAware
{
}
