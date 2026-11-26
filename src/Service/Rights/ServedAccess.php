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

	/** @var array<int, string>|null The served tools, asked of the server once per request. */
	private static ?array $aServedTools = null;

	/**
	 * @param \Closure(): array{toolsets: array<int, string>, tools: array<int, string>, capabilities: array<int, string>, prompts: array<int, string>} $fAccess
	 * @param \Closure(AccessPolicy): array<int, string>                                                                                       $fPrompts
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Remember(\Closure $fAccess, \Closure $fPrompts): void
	{
		self::$fAccess = $fAccess;
		self::$fPrompts = $fPrompts;
		self::$aServedTools = null;
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
		self::$aServedTools = null;
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
