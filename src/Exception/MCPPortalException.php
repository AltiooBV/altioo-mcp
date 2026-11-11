<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Exception;

use Exception;

/**
 * A portal whose configuration could not be read, so nothing is served for it.
 *
 * Thrown by {@see \Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy} when the
 * portal's bricks, scopes or forms cannot be loaded, or when this iTop lacks
 * what reading them needs. Portal mode never falls back to anything wider when
 * that happens: a portal it cannot read is a portal it serves nothing of.
 *
 * The message is for the caller, and says only that the portal could not be
 * read: what failed - a path, a version, what iTop threw - is logged by the
 * code that throws this, under a reference the message carries when there is
 * one. An element that catches it can pass the message on unchanged.
 *
 * @internal
 * @since 1.2.0
 */
class MCPPortalException extends Exception
{
}
