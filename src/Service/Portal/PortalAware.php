<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

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
 * On the surface because the core elements implement it (ADR 0016), but a
 * pack declaring it gains nothing yet: portal mode serves only this module's
 * own elements, whatever else implements this (PortalAccess::Serves()).
 * Serving a pack's element to portal users is a decision not yet taken.
 *
 * @api
 * @since 1.2.0
 */
interface PortalAware
{
}
