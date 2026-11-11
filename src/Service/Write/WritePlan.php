<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Helper\ObjectId;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\SecretPolicy;

use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DeletionPlan;
use Mcp\Exception\ToolCallException;
use MetaModel;
use RestUtils;
use Throwable;
use UserRights;
use utils;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\Identifier;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;

/**
 * What a write would do, established before it does it.
 *
 * Two things every writing tool needs. The rest of what this class held in 1.x lives beside it in
 * Service\Write: WriteValues converts the caller's fields, ExternalKeyPolicy judges a key's target,
 * WriteOutcome shapes what a write reports and DeletionCascade checks what a deletion reaches.
 *
 * The first is CheckToWrite(). It is where iTop decides whether an object may
 * be written - mandatory attributes, DoCheckToWrite() on the class and on
 * every extension hooked into it, the target objects of external keys - and
 * without it the first thing that fails is DBInsert() or DBUpdate(), which
 * fails by throwing from inside the ORM. The caller then gets an exception
 * message written for a developer instead of a list of what is missing, and
 * for an update it gets it after the object has already been modified in
 * memory.
 *
 * The second is the dry run. A model acting on an instruction from outside the
 * organisation should not create or modify anything on a first call, and a
 * two-step - simulate, show the user, call again - is what makes that true of
 * every write. Running the check
 * without the write is exactly what makes a dry run worth anything: it answers
 * "would this work", not just "is this well formed".
 *
 * @api
 * @since 1.0.0
 */
final class WritePlan
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
	 * Refuses to act on an object the caller cannot see in its own searches.
	 *
	 * The case: an account that hides obsolete objects - the default - acting
	 * on an id from before the object became obsolete. It cannot find the
	 * object, cannot check what it now is, and the write lands on something
	 * the caller's own view says is gone.
	 *
	 * Not a right, and deliberately not enforced as one. Obsolescence is a
	 * display filter in iTop: the console opens an obsolete object by URL and
	 * edits it, and that is not an oversight - modifying the object is the
	 * only way to stop it being obsolete, because the flag is a condition over
	 * its own fields. A guard with no way past it would make un-obsoleting
	 * impossible for exactly the accounts that hide them, which is every
	 * account nobody has configured.
	 *
	 * So it refuses by default and names the two ways forward: obsolete_ok on
	 * this call, or core_set_obsolete_data to stop hiding them at all. The
	 * refusal says which of the two conditions it is - the object being
	 * obsolete, or the account hiding them - since a caller that cannot tell
	 * will try the wrong one.
	 *
	 * Single-object tools only. A bulk call is given its ids explicitly and
	 * reports per row, so a hidden-obsolete row there is an entry that says so
	 * rather than a refusal that stops the other ninety-nine.
	 *
	 * @throws ToolCallException When the object is obsolete, hidden from this account, and no hatch was passed.
	 * @since 1.0.0
	 */
	public static function RefuseHiddenObsolete(DBObject $oObject, string $sClass, bool $bObsoleteOk, string $sVerb): void
	{
		if ($bObsoleteOk) {
			return;
		}

		try {
			if (!MetaModel::IsObsoletable($sClass) || utils::ShowObsoleteData()) {
				return;
			}

			if (!$oObject->Get('obsolescence_flag')) {
				return;
			}
		} catch (Throwable $e) {
			// A question about the object must not refuse a write it cannot
			// answer for: the guard is a courtesy, and failing open leaves the
			// caller exactly where it was before the guard existed.
			ErrorReport::Log('Could not check obsolescence before a write on '.$sClass.': '.$e->getMessage());

			return;
		}

		throw new ToolCallException(sprintf(
			"This %s counts as obsolete (%s) and your account does not show obsolete objects, so it is absent from your own searches. "
			."Refused because acting on it is usually a stale id rather than a decision. "
			."Pass obsolete_ok=true to %s it anyway - which is also how you stop it being obsolete - or call core_set_obsolete_data to show them.",
			$sClass,
			MetaModel::GetObsolescenceExpression($sClass)->Render(),
			$sVerb
		));
	}

	/**
	 * What the object holds for these attributes right now, kept for later
	 * comparison.
	 *
	 * Taken after the caller's values have been Set() and before
	 * {@see Check()} runs, which is the only moment the object holds what the
	 * caller asked for and nothing else. Both the raw value and its rendered
	 * form are kept: the raw one is what {@see Overridden()} compares and what
	 * iTop validates, the rendered one is what a reader is shown, and it cannot
	 * be produced afterwards because by then the object no longer holds it.
	 *
	 * @param array<int, string> $aAttCodes The attribute codes the caller named.
	 *
	 * @return array<string, array{raw: mixed, shown: mixed}>
	 * @since 1.0.0
	 */
	public static function Requested(DBObject $oObject, string $sClass, array $aAttCodes): array
	{
		$aRequested   = [];
		$oInstanceSet = null;

		foreach ($aAttCodes as $sAttCode) {
			try {
				$aRequested[$sAttCode] = [
					'raw'   => $oObject->Get($sAttCode),
					'shown' => ObjectSerializer::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)
						? ObjectSerializer::Value($oObject, $sClass, $sAttCode)
						: ObjectSerializer::MASK,
				];
			} catch (Throwable $e) {
				// An attribute that cannot be read back cannot be compared
				// either. Leaving it out costs a line of the report; failing
				// here would cost the call.
				continue;
			}
		}

		return $aRequested;
	}

	/**
	 * The supplied values the write would throw away, and what it would store
	 * instead.
	 *
	 * This is the half of a dry run that ListChanges() cannot express. iTop
	 * runs DoComputeValues() from inside CheckToWrite(), and a class is free to
	 * Set() an attribute there from other attributes - UserRequest::ComputeValues()
	 * sets `priority` from `urgency` and `impact` on every write. When it puts
	 * back the value that was already stored, DBObject::ListChangedValues()
	 * compares it strictly against the original, finds them equal, and drops
	 * the attribute from the delta. The caller then reads `changes: {}` and a
	 * `valid: true` that came from a DoCheckToWrite() which only ever checks
	 * the attributes still in that delta - so the supplied value was neither
	 * applied nor validated, and nothing in the answer said so.
	 *
	 * Reported rather than refused. Setting a derived attribute alongside the
	 * ones it derives from is a legitimate call, and so is re-sending a value
	 * that already holds; what is not legitimate is answering "nothing would
	 * change" without saying that something was asked for and dropped. A value
	 * that is not merely overridden but invalid is a different matter - see
	 * {@see CheckRequested()}.
	 *
	 * @param array<string, array{raw: mixed, shown: mixed}> $aRequested From {@see Requested()}, taken before {@see Check()}.
	 *
	 * @return array<string, array{requested: mixed, effective: mixed}>
	 * @since 1.0.0
	 */
	public static function Overridden(DBObject $oObject, string $sClass, array $aRequested): array
	{
		$aOverridden  = [];
		$oInstanceSet = null;

		foreach ($aRequested as $sAttCode => $aBefore) {
			try {
				if (!self::Differs($aBefore['raw'], $oObject->Get($sAttCode))) {
					continue;
				}

				$aOverridden[$sAttCode] = [
					'requested' => $aBefore['shown'],
					'effective' => ObjectSerializer::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)
						? ObjectSerializer::Value($oObject, $sClass, $sAttCode)
						: ObjectSerializer::MASK,
				];
			} catch (Throwable $e) {
				continue;
			}
		}

		return $aOverridden;
	}

	/**
	 * Refuses a supplied value that iTop would have discarded without ever
	 * checking it.
	 *
	 * DoCheckToWrite() validates the attributes in ListChanges() and no others,
	 * so an attribute overridden by DoComputeValues() leaves the check having
	 * been asked nothing about the value the caller actually sent. That is how
	 * a priority of 9 on a four-value enum comes back `valid: true`: the value
	 * was gone before anything looked at it.
	 *
	 * Asked here for exactly those attributes, and with the value spelled out,
	 * because CheckValue() defaults to reading the attribute off the object -
	 * which by now holds the override rather than the request. Every other
	 * supplied value is left to DoCheckToWrite(), which has already run the
	 * same check on it; the narrow scope is deliberate, so that this adds the
	 * refusal iTop skipped and no refusal iTop would not have made.
	 *
	 * @param array<string, array{raw: mixed, shown: mixed}>          $aRequested  From {@see Requested()}.
	 * @param array<string, array{requested: mixed, effective: mixed}> $aOverridden From {@see Overridden()}.
	 *
	 * @throws ToolCallException When a discarded value was not a legal one.
	 * @since 1.0.0
	 */
	public static function CheckRequested(DBObject $oObject, array $aRequested, array $aOverridden, string $sWhat): void
	{
		$aIssues = [];

		foreach (array_keys($aOverridden) as $sAttCode) {
			if (!array_key_exists($sAttCode, $aRequested)) {
				continue;
			}

			try {
				$mResult = $oObject->CheckValue($sAttCode, $aRequested[$sAttCode]['raw']);
			} catch (Throwable $e) {
				// Same reading as Check(): a check that could not run is not a
				// check that passed, and what it threw is for the log.
				throw new ToolCallException(ErrorReport::OpaqueFailure("Could not validate {$sWhat}", $e));
			}

			if ($mResult !== true) {
				$aIssues[] = "'{$sAttCode}': ".(is_string($mResult) ? $mResult : 'value not allowed');
			}
		}

		if (empty($aIssues)) {
			return;
		}

		throw new ToolCallException(sprintf(
			'%s cannot be written as described: %s. iTop overwrites %s from other attributes, so the value was never stored - but it is not a legal value either, and a call that sent it is not the call that was meant.',
			$sWhat,
			implode(' ', $aIssues),
			count($aIssues) === 1 ? 'this attribute' : 'these attributes'
		));
	}

	/**
	 * Whether two attribute values are not the same value.
	 *
	 * Strict, the way DBObject::ListChangedValues() is strict about scalars,
	 * because that comparison is what decided the attribute was unchanged in
	 * the first place. Objects - a link set, a document - compare by identity:
	 * iTop hands back the instance it holds, so a different instance means
	 * something replaced it, and the same instance means nothing did.
	 */
	private static function Differs(mixed $mBefore, mixed $mAfter): bool
	{
		return $mBefore !== $mAfter;
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
