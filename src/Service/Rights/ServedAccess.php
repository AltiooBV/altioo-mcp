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
 * of every element (ServedElements::ServedAccess()), and registration sits above
 * everything that reports it: the identity tool, its resource and the server
 * instructions. So the server remembers how to answer, once per request, and
 * they ask here, next to AccessPolicy, which they ask the same way and which is
 * remembered by the same hand.
 *
 * The answers are computed when asked, not when remembered, as they were when
 * the server was asked directly. Nothing remembered answers with nothing
 * served: a caller is never named more than registration gave it.
 *
 * @api
 * @since 2.0.0
 */
final class ServedAccess
{
	private static ?\Closure $fAccess = null;

	private static ?\Closure $fPrompts = null;

	private static ?\Closure $fWritesWithheld = null;

	/** What the server said about writes, asked once per request: false until asked. */
	private static string|false|null $mWritesWithheld = false;

	/** @var array<int, string>|null The served tools, asked of the server once per request. */
	private static ?array $aServedTools = null;

	/**
	 * @param \Closure(): array{toolsets: array<int, string>, tools: array<int, string>, capabilities: array<int, string>, prompts: array<int, string>} $fAccess
	 * @param \Closure(AccessPolicy): array<int, string>                                                                                       $fPrompts
	 * @param \Closure(): ?string|null $fWritesWithheld Why no write tool is served (WRITES_* below), null when one is; see WritesWithheldBy().
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Remember(\Closure $fAccess, \Closure $fPrompts, ?\Closure $fWritesWithheld = null): void
	{
		self::$fAccess = $fAccess;
		self::$fPrompts = $fPrompts;
		self::$fWritesWithheld = $fWritesWithheld;
		self::$aServedTools = null;
		self::$mWritesWithheld = false;
	}

	/**
	 * For tests, and for a runner that serves more than one request.
	 *
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Forget(): void
	{
		self::$fAccess = null;
		self::$fPrompts = null;
		self::$fWritesWithheld = null;
		self::$aServedTools = null;
		self::$mWritesWithheld = false;
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
	 * Whether this caller is served a tool, for a description or a refusal that would name it.
	 *
	 * A text that sends a model to a tool it was not given sends it nowhere (review 2026-11-23, C7). True when
	 * no server is asking (nothing remembered: a test, or a call outside a request), where there is nothing to
	 * contradict, so a text built without a server names the tool as it always did. Asked of the server once
	 * per request.
	 *
	 * @param string $sTool The tool's qualified name, e.g. core_object_history.
	 *
	 * @since 2.2.0
	 */
	public static function Serves(string $sTool): bool
	{
		if (self::$fAccess === null) {
			return true;
		}

		self::$aServedTools ??= self::Current()['tools'];

		return in_array($sTool, self::$aServedTools, true);
	}

	/** The credential's own scope is what leaves it without a write tool: the instance would serve one. */
	public const WRITES_BY_SCOPE = 'scope';

	/** The instance's settings (mcp_read_only, mcp_capabilities, mcp_enabled_toolsets) serve no write tool to anyone. */
	public const WRITES_BY_SETTINGS = 'settings';

	/** No write tool is served and neither of the above says why (a tool turned off, a portal that offers none). */
	public const WRITES_BY_OTHER = 'other';

	/**
	 * Why this caller is served no write tool, or null when it is served one.
	 *
	 * "A write tool" is one graded write or delete: whatever changes an object. Decided by what the server serves
	 * (the capabilities it reports), never by what the credential's grade would allow, so a text that withdraws
	 * its write talk is withdrawn from exactly the connections that cannot write (review 2026-11-23, C5; decided
	 * 2026-11-24: the reviewer's portal session was read-only because of the OAuth authorization scope). Null
	 * when no server is asking: nothing remembered, nothing to contradict.
	 *
	 * @return string|null One of the WRITES_BY_* constants.
	 *
	 * @since 2.2.0
	 */
	public static function WritesWithheldBy(): ?string
	{
		if (self::$fWritesWithheld === null) {
			return null;
		}

		if (self::$mWritesWithheld === false) {
			self::$mWritesWithheld = (self::$fWritesWithheld)();
		}

		return self::$mWritesWithheld;
	}

	/**
	 * Whether this caller is served any tool that changes an object. True when no server is asking.
	 *
	 * @since 2.2.0
	 */
	public static function ServesWrites(): bool
	{
		return self::WritesWithheldBy() === null;
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
