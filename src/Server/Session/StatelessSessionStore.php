<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Server\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A session store that remembers nothing beyond the request it was built for.
 *
 * This endpoint is stateless by design: every request carries its own
 * credential and is authenticated on its own. A request that carries none is
 * refused before iTop's PHP session is touched, and one that carries a
 * credential resets that session before the login runs - so nothing is
 * carried from one request to the next, and nothing already in the browser
 * decides anything here.
 *
 * Since SDK 0.8 the endpoint answers two protocol eras. A 2026-07-28 client
 * never reaches this class at all: that revision dropped the handshake and the
 * session id, and the transport routes it to a dispatcher that has no session
 * layer to store anything in. What is left here is the handshake era -
 * 2025-06-18 and 2025-11-25, which most clients still speak - where the SDK
 * has a session whether or not the deployment wants one. It is given a store
 * that serves back what this request wrote and forgets it when the request
 * ends. Serving it back is not optional. The SDK queues a response into the session
 * (Protocol::queueOutgoing()), saves it through the store, and then reads it
 * back through a *different* Session object, built by
 * SessionManager::createWithId() inside Protocol::consumeOutgoingMessages().
 * That second object starts with an empty data cache, so it goes to the store
 * rather than to memory. A store that discards writes therefore loses every
 * response: the transport finds an empty queue, answers 202 with no body and
 * no Mcp-Session-Id, and the client waits for a reply that was generated and
 * thrown away.
 *
 * What makes this safe is the lifetime, not the emptiness. One store is
 * constructed per request in MCPService::run(), and the array below is an
 * instance property rather than a static one, so it dies with the request
 * under mod_php and FPM and under a persistent worker (FrankenPHP, RoadRunner)
 * alike. Nothing here may become static, and nothing may be backed by a file,
 * a table or iTop's PHP session: exists() accepts any id a caller invents, and
 * the only reason that is not a way into somebody else's state is that no
 * state outlives the request that made it.
 *
 * This is a workaround, not a design, and it is now half retired: the SDK
 * supports stateless operation directly for the revision that defines it, and
 * this class covers the older revisions that cannot be served any other way.
 * It goes away when the handshake era does, rather than being improved.
 *
 * @see https://modelcontextprotocol.io/specification/basic/transports Streamable HTTP without a session
 * @since 1.0.0
 */
class StatelessSessionStore implements SessionStoreInterface
{
	/**
	 * What this request wrote, keyed by session id.
	 *
	 * Instance state, never static - see the class docblock for why that
	 * distinction is the whole safety argument.
	 *
	 * @var array<string, string>
	 */
	private array $aSessionData = [];

	public function exists(Uuid $id): bool
	{
		return true; // accept any ID
	}

	public function read(Uuid $id): string|false
	{
		return $this->aSessionData[$id->toRfc4122()] ?? false;
	}

	public function write(Uuid $id, string $data): bool
	{
		$this->aSessionData[$id->toRfc4122()] = $data;

		return true;
	}

	public function destroy(Uuid $id): bool
	{
		unset($this->aSessionData[$id->toRfc4122()]);

		return true;
	}

	public function gc(): array
	{
		// Nothing outlives the request, so there is never anything expired to
		// collect: the array is gone before a collection could run.
		return [];
	}
}
