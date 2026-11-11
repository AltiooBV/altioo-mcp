<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

/**
 * What this request is served, as the server decided it: the toolsets, tools,
 * grades and prompts a caller can reach.
 *
 * The server alone knows, because the answer is the question registration asks
 * of every element (MCPService::ServedAccess()), and registration sits above
 * everything that reports it: the identity tool, its resource and the server
 * instructions. So the server remembers how to answer, once per request, and
 * they ask here, next to AccessPolicy, which they ask the same way and which is
 * remembered by the same hand.
 *
 * The answers are computed when asked, not when remembered, as they were when
 * the server was asked directly. Nothing remembered answers with nothing
 * served: a caller is never named more than registration gave it.
 *
 * @internal
 * @since 2.0.0
 */
final class ServedAccess
{
	private static ?\Closure $fAccess = null;

	private static ?\Closure $fPrompts = null;

	/**
	 * @param \Closure(): array{toolsets: array<int, string>, tools: array<int, string>, capabilities: array<int, string>, prompts: array<int, string>} $fAccess
	 * @param \Closure(AccessPolicy): array<int, string>                                                                                       $fPrompts
	 */
	public static function Remember(\Closure $fAccess, \Closure $fPrompts): void
	{
		self::$fAccess = $fAccess;
		self::$fPrompts = $fPrompts;
	}

	/** For tests, and for a runner that serves more than one request. */
	public static function Forget(): void
	{
		self::$fAccess = null;
		self::$fPrompts = null;
	}

	/**
	 * The toolsets, tools, capabilities and prompts this caller can reach: what it can see, not what exists.
	 *
	 * @return array{toolsets: array<int, string>, tools: array<int, string>, capabilities: array<int, string>, prompts: array<int, string>}
	 */
	public static function Current(): array
	{
		return self::$fAccess === null
			? ['toolsets' => [], 'tools' => [], 'capabilities' => [], 'prompts' => []]
			: (self::$fAccess)();
	}

	/**
	 * The prompts a caller under this policy can fetch.
	 *
	 * @return array<int, string>
	 */
	public static function PromptNames(AccessPolicy $oPolicy): array
	{
		return self::$fPrompts === null ? [] : (self::$fPrompts)($oPolicy);
	}
}
