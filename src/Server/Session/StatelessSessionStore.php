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
 * credential and is authenticated on its own. A request that carries none is
 * refused before iTop's PHP session is touched, and one that carries a
 * credential resets that session before the login runs - so nothing is
 * carried from one request to the next, and nothing already in the browser
 * decides anything here.
 *
 * The SDK does not currently offer a stateless mode, so it is given a store
 * that satisfies the interface and stores nothing. write() accepts and
 * discards, read() always answers "no such session", and exists() accepts any
 * id because there is nothing to look up. A client that keeps sending an
 * Mcp-Session-Id back is therefore never contradicted, which is the point - it
 * is also never remembered.
 *
 * Discarding rather than keeping a per-process array costs nothing, and that
 * is worth saying because the array looks useful. The SDK builds exactly one
 * Session per request and caches its data in the object for the life of that
 * request (see Mcp\Server\Session\Session::readData()); the store is read once,
 * before anything has been written, and written once, after everything has
 * been read. So the only reader an entry could ever have is a later request -
 * and a later request either runs in a fresh PHP context, where the array is
 * empty anyway, or in a persistent worker (FrankenPHP, RoadRunner), where
 * keeping it would be an unbounded leak that also hands one caller's session
 * data to whoever guesses the id. Neither is a thing to keep state for.
 *
 * This is a workaround, not a design: it stands in until the PHP SDK supports
 * stateless operation directly, at which point this class goes away rather
 * than being improved. Nothing here is a place to start keeping state.
 *
 * @see https://modelcontextprotocol.io/specification/basic/transports Streamable HTTP without a session
 * @since 1.0.0
 */
class StatelessSessionStore implements SessionStoreInterface
{
	public function exists(Uuid $id): bool
	{
		return true; // accept any ID
	}

	public function read(Uuid $id): string|false
	{
		return false; // there is never anything to read back
	}

	public function write(Uuid $id, string $data): bool
	{
		// Accepted and discarded. Returning false would make the SDK log a
		// failed save on every single request; there is nothing failing here,
		// there is simply nowhere for it to go.
		return true;
	}

	public function destroy(Uuid $id): bool
	{
		return true; // nothing was stored, so it is already gone
	}

	public function gc(): array
	{
		return [];
	}
}
