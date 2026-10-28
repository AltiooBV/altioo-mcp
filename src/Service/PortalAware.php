<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

/**
 * Declares that an element enforces the portal's rules itself, through
 * {@see PortalPolicy}, and may therefore be served in portal mode.
 *
 * A declaration, not a capability: implementing it changes nothing about what
 * an element does, only whether portal mode lets a client reach it. Every
 * element without it asks UserRights, which grants "Portal user" read on every
 * bizmodel class, so the safe default for anything undeclared is that a
 * portal user never sees it.
 *
 * Internal on purpose. A tool pack cannot declare it yet: PortalPolicy is not
 * part of the surface a pack may build on, and an element declared
 * portal-aware without it would be a console tool served to the portal. Until
 * it is, MCPService serves only this module's own elements in portal mode,
 * whatever else implements this.
 *
 * @internal
 * @since 1.2.0
 */
interface PortalAware
{
}
