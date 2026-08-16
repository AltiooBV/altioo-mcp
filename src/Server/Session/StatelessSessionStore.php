<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Server\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A session store for a server that has no sessions.
 *
 * This endpoint is stateless by design: every request carries its own
 * credential, is authenticated on its own, and resets iTop's PHP session
 * before doing anything. Nothing is meant to be carried from one request to
 * the next.
 *
 * The SDK does not currently offer a stateless mode, so it is given a store
 * that satisfies the interface without storing anything: writes go to a static
 * array that dies with the process, and exists() accepts any id because there
 * is nothing to look up. A client that keeps sending an Mcp-Session-Id back is
 * therefore never contradicted, which is the point - it is also never
 * remembered.
 *
 * This is a workaround, not a design: it stands in until the PHP SDK supports
 * stateless operation directly, at which point this class goes away rather
 * than being improved. Nothing here is a place to start keeping state - any
 * state kept would be per PHP process and would survive exactly one request,
 * which is worse than none.
 *
 * @see https://modelcontextprotocol.io/specification/basic/transports Streamable HTTP without a session
 * @since 1.0.0
 */
class StatelessSessionStore implements SessionStoreInterface
{

	// In-memory only — survives this request, gone on next
	private static array $sessions = [];

	public function exists(Uuid $id): bool
	{
		return true; // accept any ID
	}

	public function read(Uuid $id): string|false
	{
		return self::$sessions[$id->toRfc4122()] ?? false;
	}

	public function write(Uuid $id, string $data): bool
	{
		self::$sessions[$id->toRfc4122()] = $data;
		return true;
	}

	public function destroy(Uuid $id): bool
	{
		unset(self::$sessions[$id->toRfc4122()]);
		return true;
	}

	public function gc(): array
	{
		return [];
	}
}
