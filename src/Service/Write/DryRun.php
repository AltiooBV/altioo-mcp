<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use DBObject;
use Throwable;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

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

	/** Whether the credential turned this request's write into a rehearsal. See {@see ForcedByCredential()}. */
	private static bool $bForced = false;

	/** What the write tool decided, once it has: true when it rehearsed, whoever made it. See {@see Decided()}. */
	private static ?bool $bDecided = null;

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
			return self::$bDecided = true;
		}

		$oPolicy = AccessPolicy::Current();
		if ($oPolicy === null || !$oPolicy->isAdvisory()) {
			self::$bDecided = false;

			return false;
		}
		self::$bDecided = true;

		// The caller asked to write and the credential said no. Remembered, because the audit
		// row and the answer both have to say so: the argument the caller sent is not what ran.
		self::$bForced = true;

		return true;
	}

	/**
	 * What the write tool actually did this request: true when it rehearsed - asked, defaulted or
	 * forced - false when it went on to write, null when no tool has decided (a read, a handshake,
	 * a call refused first). The audit row records this rather than the argument, which a caller
	 * may have left out or the credential overridden.
	 *
	 * @since 2.1.0
	 */
	public static function Decided(): ?bool
	{
		return self::$bDecided;
	}

	/**
	 * Whether this request asked to write and was turned into a rehearsal by its credential.
	 *
	 * True only once a write tool has been asked {@see Simulated()} with `false` under an advisory
	 * policy - so a refused call, a read, and a caller that rehearsed anyway are all false. It is the
	 * effective value, where the `simulate` argument is only the requested one.
	 *
	 * @since 2.1.0
	 */
	public static function ForcedByCredential(): bool
	{
		return self::$bForced;
	}

	/**
	 * The one plain line that goes in an answer rehearsed by force, in words a person who never
	 * heard of a token scope understands: the portal's user is told about their account, not about
	 * a scope name.
	 *
	 * @since 2.1.0
	 */
	public static function ForcedReason(): string
	{
		return PortalMode::Current() !== null
			? 'Nothing was saved: this account may only propose changes here, so every change is checked and shown but not made. A person with the right to make it has to do so.'
			: 'Nothing was written: this credential is advisory, so every write rehearses whatever simulate was sent. Show the result to the user; a credential that may write has to make the change.';
	}

	/**
	 * What every write answer adds to itself, additively: whether this was a rehearsal by force, and
	 * the one line that says why (empty when it was not).
	 *
	 * Always present, as every property of a write outcome is - a key that appears only on one kind of
	 * call is a second shape - and only on an answer that already carries `simulated`, which is what
	 * marks it a write's. An answer that has the keys already keeps them.
	 *
	 * @param array<string, mixed> $aData A write's answer.
	 *
	 * @return array<string, mixed>
	 * @since 2.1.0
	 */
	public static function AnnotateForced(array $aData): array
	{
		if (!array_key_exists('simulated', $aData)) {
			return $aData;
		}

		$bForced = self::$bForced && $aData['simulated'] === true;

		return $aData + [
			'forced_by_credential' => $bForced,
			'forced_reason'        => $bForced ? self::ForcedReason() : '',
		];
	}

	/**
	 * The two keys a write's output schema declares for that.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 2.1.0
	 */
	public static function ForcedSchemaProperties(): array
	{
		return [
			'forced_by_credential' => [
				'type'        => 'boolean',
				'description' => 'true when simulate=false was asked for and the call rehearsed anyway, because this credential may only propose changes. Nothing was written.',
			],
			'forced_reason'        => [
				'type'        => 'string',
				'description' => 'When forced_by_credential is true, one plain sentence to tell the user why nothing was written; empty otherwise.',
			],
		];
	}

	/**
	 * For tests, and for a runner that serves more than one request.
	 *
	 * @internal Request state, cleared by Service\Server\RequestScope; a pack never calls it.
	 * @since 2.1.0
	 */
	public static function Forget(): void
	{
		self::$bForced = false;
		self::$bDecided = null;
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
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When the object cannot be written as described.
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
			throw ToolRefusal::Because(ErrorReport::OpaqueFailure("Could not validate {$sWhat}", $e));
		}

		if ($bOk) {
			return;
		}

		// A validation message names the field it is about, which for an excluded one is a leak: those
		// are not repeated, and the call says only that it cannot be written as described.
		$aIssues = ExclusionPolicy::WithoutExcluded(get_class($oObject), array_map('strval', (array)$aIssues));

		throw ToolRefusal::Because(empty($aIssues)
			? "{$sWhat} cannot be written as described."
			: "{$sWhat} cannot be written as described: ".implode(' ', $aIssues));
	}

}
