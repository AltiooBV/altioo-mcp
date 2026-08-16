<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Exception;

use Exception;

/**
 * A document that cannot be served, said once for both doors.
 *
 * The same file is reachable as a tool and as a resource template, and the SDK
 * wants a different exception from each: ToolCallException from one,
 * ResourceReadException from the other. Neither belongs in
 * {@see \Altioo\iTop\Extension\MCP\Helper\DocumentAccess}, which would then have
 * to know which of its two callers it was serving and would have two copies of
 * every refusal. It throws this instead, and each door translates.
 *
 * The messages are written here and meant for the caller: they say what was
 * wrong with the request - unknown attribute, empty document, over the ceiling -
 * and never why the object could not be found, which is deliberately
 * indistinguishable from not being allowed to see it.
 *
 * @since 1.0.0
 */
class MCPDocumentException extends Exception
{
}
