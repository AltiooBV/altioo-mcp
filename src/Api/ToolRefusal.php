<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Api;

use Mcp\Server\Exception\ToolCallException;
use Throwable;

/**
 * What a tool throws to tell the caller why it did not do what was asked: `throw ToolRefusal::Because('...')`.
 *
 * The message is for the caller, and the SDK sends it back as a tool error (`isError: true`) that a model reads and
 * acts on, so it says what to change and names nothing the caller may not see. Anything else a tool throws is
 * answered as an unexpected failure with no message, and logged.
 *
 * This is the way a pack raises one, in place of naming the SDK's `ToolCallException`. The SDK declares that class
 * `final`, so it cannot be extended and this is not an exception type of its own: `Because()` returns the SDK's own
 * exception, which is what the SDK catches, so nothing about how it is answered changes. What it changes is where
 * the SDK's name is written. The SDK moved that class in 0.10 (from `Mcp\Exception` to `Mcp\Server\Exception`) and
 * says it is being split into packages, so a pack that names it is rewritten by every such move; one that goes
 * through here is not, and the module's own code goes through here for the same reason. The SDK's old name is
 * aliased until 3.0 (doc/adr/0024-the-pack-contract-names-this-modules-exceptions.md).
 *
 * To tell a refusal from anything else a call raised, ask {@see self::Is()} instead of catching the SDK's class.
 *
 * @api
 * @since 2.1.0
 */
final class ToolRefusal
{
	/**
	 * The exception to throw. The code and the previous exception are the SDK's own and are rarely needed.
	 */
	public static function Because(string $sMessage, int $iCode = 0, ?Throwable $oPrevious = null): ToolCallException
	{
		return new ToolCallException($sMessage, $iCode, $oPrevious);
	}

	/**
	 * Whether something thrown is a tool refusal: one made by {@see self::Because()}, or by the SDK itself.
	 */
	public static function Is(Throwable $oThrown): bool
	{
		return $oThrown instanceof ToolCallException;
	}
}
