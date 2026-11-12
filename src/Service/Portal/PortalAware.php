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
 * Necessary, not sufficient. Portal mode serves a declaring element that is
 * this module's own, or that a pack provides and the operator listed in
 * mcp_portal_extensions (PortalAccess::Serves(), ADR 0017). A pack element
 * that declares this answers through ObjectAccess::Current() for every object
 * it reads or writes, never UserRights alone;
 * Testing\ElementContract reports one whose source never asks.
 *
 * @api
 * @since 1.2.0
 */
interface PortalAware
{
}
