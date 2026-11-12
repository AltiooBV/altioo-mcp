<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * The JSON-RPC methods this module names: the audited ones and the pseudo-method an unreadable request is recorded under.
 *
 * @internal
 * @since 2.0.0
 */
class MCPMethod
{
	/**
	 * Pseudo-method stamped on the audit row when the request died before its
	 * JSON-RPC method could be read. Must match an entry of log_mcp_method,
	 * otherwise exceptions are silently dropped from the audit trail.
	 */
	const EXCEPTION = 'exceptions';

	/**
	 * The JSON-RPC method a client sends to open a session.
	 *
	 * Audited apart from the rest: it is the only record that a client
	 * connected at all, and the row is worth having whether or not the
	 * connection went on to call anything.
	 *
	 * @since 1.0.0
	 */
	const INITIALIZE = 'initialize';

	/**
	 * The three JSON-RPC methods that actually do something on this endpoint:
	 * call a tool, read a resource, render a prompt.
	 *
	 * Named rather than spelled out at each use because the module branches on
	 * them - ChangeTracking attributes a write to the tool named by a
	 * tools/call body, the controller reads the element name out of a
	 * different params key for each - and a literal that decides behaviour in
	 * one file and appears as data in another is one typo away from a silent
	 * failure: the attribution falls back to nothing, or the audit row loses
	 * its name, with no error either way.
	 *
	 * @since 1.0.0
	 */
	const TOOLS_CALL = 'tools/call';
	const RESOURCES_READ = 'resources/read';
	const PROMPTS_GET = 'prompts/get';
}
