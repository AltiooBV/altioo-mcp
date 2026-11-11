<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use DBObject;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * The dry run of a write tool: the argument that asks for it, whether this call is rehearsing, and
 * iTop's own pre-write check that makes a rehearsal worth anything.
 *
 * This is the part of the write plan a tool pack calls. It was the first half of `WritePlan` in
 * 1.x; the rest of what that class did stays beside it in Service\Write and is internal.
 *
 * A model acting on an instruction from outside the organisation should not create or modify
 * anything on a first call, and a two-step - simulate, show the user, call again - is what makes
 * that true of every write. Running the check without the write is exactly what makes a dry run
 * worth anything: it answers "would this work", not just "is this well formed".
 *
 * @api
 * @since 2.0.0
 */
final class DryRun
{
	/** Writes rehearse unless the caller asks otherwise; MCP-advisory removes the asking. */
	public const SIMULATE_BY_DEFAULT = true;

	/**
	 * Whether this call is rehearsing, which is not only the caller's to
	 * decide.
	 *
	 * Every write tool takes a `simulate` argument defaulting to true, and
	 * every one of them asks this instead of reading that argument directly.
	 * The reason is the advisory scope: a token carrying MCP-advisory may call
	 * the write tools its other scopes allow and every one of them rehearses,
	 * whatever it was passed. That is the trust tier between "may read" and
	 * "may write" - propose changes, show a person what they would do, commit
	 * nothing - and it belongs to the credential rather than to the call,
	 * because a caller that can choose is not restricted.
	 *
	 * Pinned to the token and not to a session, because there is no session:
	 * this endpoint is stateless and the token record is the only thing that
	 * persists between calls. The policy is decided once per request by the
	 * controller and remembered, so asking here costs nothing.
	 *
	 * No policy remembered means no opinion, and the caller's own answer
	 * stands: that is the state a unit suite runs in, and also a runner that
	 * never went through the controller. It is the one direction this can fail
	 * in without turning a rehearsal into a write.
	 *
	 * @param bool $bRequested What the caller asked for.
	 *
	 * @since 1.0.0
	 */
	public static function Simulated(bool $bRequested): bool
	{
		if ($bRequested) {
			return true;
		}

		$oPolicy = AccessPolicy::Current();

		return $oPolicy !== null && $oPolicy->isAdvisory();
	}

	/**
	 * The dry-run property, spelled once so that every writing tool spells it
	 * the same way.
	 *
	 * @param string $sWhatItWouldDo e.g. 'create the object'
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function SimulateSchemaProperty(string $sWhatItWouldDo): array
	{
		return [
			'type'        => 'boolean',
			'description' => 'true (the default) validates and reports what would change, writing nothing. Show it to the user, then call again with simulate=false to '.$sWhatItWouldDo.'.',
			'default'     => self::SIMULATE_BY_DEFAULT,
		];
	}

	/**
	 * Runs iTop's own pre-write check, and refuses with what it found.
	 *
	 * CheckToWrite() returns [ok, issues, securityIssue] and fills the issues
	 * with sentences meant for a person - "Attribute X is mandatory" - which
	 * are exactly what a model needs to fix the call and try again.
	 *
	 * @throws ToolCallException When the object cannot be written as described.
	 * @since 1.0.0
	 */
	public static function Check(DBObject $oObject, string $sWhat): void
	{
		try {
			[$bOk, $aIssues] = $oObject->CheckToWrite();
		} catch (Throwable $e) {
			// A check that cannot run is not a check that passed. What it threw
			// came from inside CheckToWrite() - a class extension, a query -
			// and is for the log, not for the caller.
			throw new ToolCallException(ErrorReport::OpaqueFailure("Could not validate {$sWhat}", $e));
		}

		if ($bOk) {
			return;
		}

		// A validation message names the field it is about, which for an excluded one is a leak: those
		// are not repeated, and the call says only that it cannot be written as described.
		$aIssues = ExclusionPolicy::WithoutExcluded(get_class($oObject), array_map('strval', (array)$aIssues));

		throw new ToolCallException(empty($aIssues)
			? "{$sWhat} cannot be written as described."
			: "{$sWhat} cannot be written as described: ".implode(' ', $aIssues));
	}

}
