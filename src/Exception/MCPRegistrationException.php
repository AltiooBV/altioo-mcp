<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Exception;

use InvalidArgumentException;

/**
 * A tool, resource, resource template or prompt does not honour the contract
 * the framework will later read off it by reflection.
 *
 * Raised by {@see \Altioo\iTop\Extension\MCP\Registry\MCPRegistry} at
 * registration time - i.e. at server boot, from the provider that registered
 * the offending object - rather than at call time, where the same defect
 * surfaces as an unrelated "missing argument" or an empty tools/list.
 *
 * It is a programming error in the registering extension, never something a
 * client did: {@see \Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector}
 * catches it per provider, logs it and keeps serving everything else.
 *
 * @since 1.0.0
 */
class MCPRegistrationException extends InvalidArgumentException
{
}
