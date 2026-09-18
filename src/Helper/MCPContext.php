<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * @api
 * @since 1.0.0
 */
class MCPContext
{
	const TAG_MCP = 'MCP';

	/**
	 * Token scope granting access to this endpoint, and the prefix every other
	 * MCP scope is built on.
	 *
	 * iTop matches a token's scopes against the ContextTag stack: a scope is
	 * honoured when a tag of the same name is on it. So a scope value and a
	 * context tag are the same string by construction, and everything this
	 * module recognises has to be pushed as a tag before DoLogin() runs.
	 *
	 * @see \Altioo\iTop\Extension\MCP\Service\TokenScopes
	 */
	const SCOPE_MCP = self::TAG_MCP;

	/**
	 * Scope pinning a token to dry runs.
	 *
	 * Not a grade and not a toolset: a modifier. A token carrying it may call
	 * every write tool its other scopes allow, and every one of them rehearses
	 * - simulate is forced true whatever the caller passes. That is the trust
	 * tier between "may read" and "may write": propose changes, show a person
	 * what they would do, commit nothing.
	 *
	 * On the token rather than in a session, because there is no session: this
	 * endpoint is stateless, one request at a time, and the token record is
	 * the only thing that persists between calls and is read on every one of
	 * them anyway.
	 */
	const SCOPE_ADVISORY = self::SCOPE_MCP.'-advisory';

	/**
	 * Prefix of a scope naming a toolset, e.g. MCP-toolset-objects.
	 *
	 * Spelled out rather than 'MCP-<name>' so that a pack naming a toolset
	 * "write" cannot collide with the grade of the same name.
	 */
	const SCOPE_TOOLSET_PREFIX = 'MCP-toolset-';
}
