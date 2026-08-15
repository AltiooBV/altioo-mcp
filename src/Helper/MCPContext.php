<?php

namespace Altioo\iTop\Extension\MCP\Helper;

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
	 * Prefix of a scope naming a toolset, e.g. MCP-toolset-objects.
	 *
	 * Spelled out rather than 'MCP-<name>' so that a pack naming a toolset
	 * "write" cannot collide with the grade of the same name.
	 */
	const SCOPE_TOOLSET_PREFIX = 'MCP-toolset-';
}
