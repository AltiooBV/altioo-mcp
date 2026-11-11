<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

use DBObject;
use Mcp\Exception\ToolCallException;
use MetaModel;
use Throwable;
use utils;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;

/**
 * What a write would do, established before it does it.
 *
 * What the core write tools report about a write they are rehearsing. The dry run itself - the
 * `simulate` argument, whether a call is rehearsing, and the pre-write check - is
 * {@see DryRun}, the part a tool pack calls; this class is internal. The rest of what it held in
 * 1.x lives beside it in Service\Write: WriteValues converts the caller's fields,
 * ExternalKeyPolicy judges a key's target, WriteOutcome shapes what a write reports and
 * DeletionCascade checks what a deletion reaches.
 *
 * @api
 * @since 1.0.0
 */
final class WritePlan
{
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
}
