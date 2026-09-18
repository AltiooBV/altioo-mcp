<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use DBObject;
use DBObjectSet;
use DeletionPlan;
use Mcp\Exception\ToolCallException;
use MetaModel;
use Throwable;
use UserRights;
use utils;

/**
 * What a write would do, established before it does it.
 *
 * Two things every writing tool needs.
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
	/** Nothing writes on a first call. */
	public const SIMULATE_BY_DEFAULT = true;

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
	 * The result shape every single-object write reports.
	 *
	 * Declared as an output schema, which the reading tools deliberately do not
	 * declare: what a read returns depends on the class and on output_fields,
	 * so no fixed schema could describe it, and structuredContent would double
	 * the payload of the largest responses this server sends. A write answers
	 * with a handful of scalars whose shape never varies, so both objections
	 * fall away - see {@see ToolOutput::Structured()}.
	 *
 * One shape, whatever `simulate` was. Every property is always present and
	 * always required, and it is the *values* that vary: `id` is null until there
	 * is one, `simulated` says which call this was, `changes` is empty rather than
	 * absent. A field that appears and disappears is a second interface hiding
	 * inside the first.
	 *
	 * The alternative - `valid` on a dry run only, `id` on a real write only, and
	 * a schema declaring the union of the two with `required` narrowed to their
	 * intersection - describes neither response. Nothing validating it could catch
	 * a create that came back without an id, and the consumer that actually
	 * matters here reads the schema as prose and cannot tell which fields to
	 * expect when.
	 *
	 * `changes` is not in the core set, and that is not a relapse: a tool
	 * declares it through {@see ChangesSchemaProperty()} and then always
	 * reports it. Forcing it on every tool would make core_object_attach
	 * enumerate the attributes of an Attachment, one of which is the file, and
	 * a write outcome is not the place to send a document back. Different tools
	 * may describe different things; what none of them may do is describe
	 * different things on different calls.
	 *
	 * @param array<string, array<string, mixed>> $aProperties Properties this particular tool adds. They are required too - a tool that declares a property must always report it.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function OutcomeSchema(array $aProperties = []): array
	{
		$aCore = [
			'class'     => [
				'type'        => 'string',
				'description' => 'Final class of the object the call acted on.',
			],
			'id'        => [
				'type'        => ['integer', 'null'],
				'description' => 'Identifier of the object, or null when there is not one yet - a create dry run has not created anything.',
			],
			'simulated' => [
				'type'        => 'boolean',
				'description' => 'true when the call validated everything and wrote nothing.',
			],
			'valid'     => [
				'type'        => 'boolean',
				'description' => 'Every pre-write check passed. A call that fails one comes back as a tool error rather than as a result, so this is true on any result you receive; it is reported so that a dry run and a real write answer with the same shape.',
			],
		];

		return [
			'type'       => 'object',
			'properties' => $aCore + $aProperties,
			'required'   => array_values(array_merge(array_keys($aCore), array_keys($aProperties))),
			// A link class reports its identifier a second time under its own
			// key attribute, 'link_id'. Two stock classes do that and the rest
			// name it 'id', so it cannot be a declared property - see
			// {@see Identity()}.
			'additionalProperties' => true,
		];
	}

	/**
	 * The escape hatch for the guard below, spelled once.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function ObsoleteOkSchemaProperty(string $sWhatItDoes): array
	{
		return [
			'type'        => 'boolean',
			'description' => 'Set true to '.$sWhatItDoes.' an object that counts as obsolete while your account does not show obsolete objects. Off by default: such an object is absent from your own searches, so acting on it is usually a stale id rather than a decision.',
			'default'     => false,
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
			MCPHelper::LogError('Could not check obsolescence before a write on '.$sClass.': '.$e->getMessage());

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
	 * The `obsolescence` property, for a tool that changes an object's fields.
	 *
	 * The case it exists for: an agent sets a status the datamodel counts as
	 * obsolete, the write succeeds, and the object then vanishes from its own
	 * searches - because "show obsolete data" is off for that account, which is
	 * the default. Nothing in the write said so, and the agent's next move is
	 * to search for what it just wrote, find nothing, and conclude the write
	 * failed.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function AfterSchemaProperty(): array
	{
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => 'The object as it stands once the write has been applied, which is not always what was asked for: friendlyname, applied (the attributes you supplied, re-read, so a value a trigger or an AfterInsert hook changed is the value you see), and obsolescence - flag (null when the class has no such notion), condition (the rule the datamodel evaluates) and hidden_from_searches, true when searches for this account will no longer return this object, though a read by id still will. On a dry run it describes the object in hand, since nothing has run yet.',
		];
	}

	/**
	 * That block, filled in.
	 *
	 * The flag is not a stored column: AttributeObsolescenceFlag answers
	 * IsBasedOnOQLExpression, so the database evaluates the class's condition
	 * when the object is queried. A dry run therefore cannot know what the
	 * write would make of it - nothing has evaluated anything - which is why
	 * the condition is reported beside the flag rather than instead of it: on a
	 * dry run the flag is what the object carries now, and the condition is
	 * what the caller can read to see where its own values would land.
	 *
	 * On a real write the object is asked again, which costs one read of one
	 * row and only on a class that has the notion at all.
	 *
	 * The attributes re-read are the ones the *caller supplied*, not the ones
	 * the write changed. On a create those are not the same list at all:
	 * `changes` holds every attribute of the new object, so echoing that back
	 * here returned a seventy-attribute object twice in one answer, and the
	 * block meant to say "here is what became of what you sent" said "here is
	 * everything". What a caller wants confirmed is what it asked for.
	 *
	 * @param DBObject           $oObject   The object the write acted on.
	 * @param array<int, string> $aAttCodes The attributes the caller supplied, re-read so that what a hook changed is visible.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function After(DBObject $oObject, string $sClass, array $aAttCodes, bool $bSimulated): array
	{
		$aAfter = [
			'friendlyname' => null,
			'applied'      => self::Map([]),
			'obsolescence' => ['flag' => null, 'condition' => null, 'hidden_from_searches' => false],
		];

		try {
			$iId = self::AsId($oObject->GetKey());
			if (!$bSimulated && $iId !== null) {
				// One read, for both halves of the answer.
				//
				// `changes` is taken before the write and has to be: DBInsert()
				// clears the pending values, so asking afterwards reports
				// nothing. What that leaves out is everything the write itself
				// did - an AfterInsert hook, an event listener, a lifecycle
				// that filled in a field, the ref a ticket is given - and the
				// obsolescence flag, which is not a stored column at all but an
				// expression the database evaluates when the row is queried.
				$oFresh = MetaModel::GetObject($sClass, $iId, false);
				$oObject = $oFresh ?? $oObject;
			}

			$aAfter['friendlyname'] = $oObject->GetName();

			if ($aAttCodes !== []) {
				// Through the serializer, so per-attribute read rights, the
				// clipping and the masking all apply exactly as they do on a
				// read. An attribute the caller may write and may not read
				// comes back masked rather than echoed.
				$aSerialized = ObjectSerializer::Serialize($oObject, $sClass, $aAttCodes);
				$aAfter['applied'] = self::Map(array_intersect_key($aSerialized, array_flip($aAttCodes)));
			}

			if (MetaModel::IsObsoletable($sClass)) {
				$aAfter['obsolescence']['condition'] = MetaModel::GetObsolescenceExpression($sClass)->Render();
				$aAfter['obsolescence']['flag'] = (bool)$oObject->Get('obsolescence_flag');
				$aAfter['obsolescence']['hidden_from_searches'] = $aAfter['obsolescence']['flag'] && !utils::ShowObsoleteData();
			}
		} catch (Throwable $e) {
			// Describing the write must never cost the write that succeeded.
			MCPHelper::LogError('Could not describe '.$sClass.' after the write: '.$e->getMessage());
		}

		return $aAfter;
	}

	/**
	 * Why an external key cannot point where the caller aimed it, said plainly.
	 *
	 * The case: org_id = 999999 on an instance with no such organisation. iTop
	 * refuses it from inside RestUtils::MakeValue(), and what comes back here
	 * is a CoreException whose message routinely carries SQL, table names and
	 * the context array folded in - so this module answers it opaquely, with a
	 * log reference, as it does for every ORM failure it cannot vouch for.
	 *
	 * That was the wrong answer to this particular question, twice over. The
	 * reason is knowable and harmless - no object of that class has that id -
	 * and the advice was a dead end: the reference points into iTop's log, and
	 * nothing in this server reads it. An agent was told to quote an
	 * identifier at a system it has no path to, for a mistake it could have
	 * corrected itself.
	 *
	 * Asked before the value reaches the ORM, so the refusal replaces the
	 * opaque one rather than decorating it. Missing and unreadable answer the
	 * same sentence: telling them apart would let a caller enumerate the
	 * objects it may not see, which is the oracle SECURITY.md closes
	 * everywhere else.
	 *
	 * Only a plain id. A string is OQL to FindObjectFromKey() and an array is
	 * search criteria; both are the ORM's business, and a caller that sent one
	 * is not the caller this is for.
	 *
	 * @param mixed $value As the caller sent it.
	 *
	 * @return string|null The refusal, or null when there is nothing to object to.
	 * @since 1.0.0
	 */
	public static function RefusalForExternalKey(string $sClass, string $sAttCode, mixed $value): ?string
	{
		if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
			return null;
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if (!$oAttDef->IsExternalKey()) {
				return null;
			}

			$sTarget = $oAttDef->GetTargetClass();
			if ((int) $value === 0 && $oAttDef->IsNullAllowed()) {
				// 0 is how iTop spells "no target" on a key that allows one.
				return null;
			}

			if (MetaModel::GetObject($sTarget, (int) $value, false) !== null) {
				return null;
			}

			return sprintf(
				"No %s has id %s, or it is not one you may read. Search %s for the object you want and pass its id: "
				."core_object_find_by_name finds it by name, core_object_search_by_class by attribute.",
				$sTarget,
				(string) $value,
				$sTarget
			);
		} catch (Throwable $e) {
			// A question about the datamodel must not become the refusal. The
			// ORM still gets its say, which is where this started.
			return null;
		}
	}

	/**
	 * A map that stays a map once it is empty.
	 *
	 * `changes`, `overridden` and `applied` are attribute code => value, and
	 * PHP's empty array is a list as far as json_encode is concerned - so a
	 * write that changed nothing answered `[]` where one that changed
	 * something answered `{}`. A caller with a typed reader breaks on the
	 * emptier of the two answers, which is the one it is least likely to have
	 * tested against.
	 *
	 * The cast is what keeps the keys: an array cast alone does not, since PHP
	 * folds a numeric-string key straight back to an int - attribute codes are
	 * never numeric, but the same rule bit the schema's allowed values, and
	 * doing it the same way here means one habit rather than two.
	 *
	 * @param array<string, mixed> $aMap
	 * @since 1.0.0
	 */
	public static function Map(array $aMap): \stdClass
	{
		return (object) $aMap;
	}

	/**
	 * The `changes` property, for a tool that reports what a write touched.
	 *
	 * Declared by the tools that have something legible to say - create,
	 * update, apply stimulus - and by them on every call, dry run or not. See
	 * {@see Changes()} for what fills it and why an unreadable attribute is
	 * masked rather than dropped.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function ChangesSchemaProperty(string $sWhichOnes = 'Every attribute for a creation, only the modified ones for an update.'): array
	{
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => 'Attribute code => the value this write set, or would set. '.$sWhichOnes.' Empty when the write touched nothing. '
				.'Read before the write, because the write clears it - so an attribute the write itself fills is empty here rather than wrong: a ticket answers "" for ref and friendlyname, which are assigned as the row is inserted. What the object ended up with is in `after`.',
		];
	}

	/**
	 * The `defaulted` property, for a tool that takes caller-supplied fields.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function DefaultedSchemaProperty(): array
	{
		return [
			'type'        => 'array',
			'items'       => ['type' => 'string'],
			'description' => 'Attribute codes in `changes` that you did not supply: a default the datamodel applied, or a value the class computed. Empty when every change came from your own call.',
		];
	}

	/**
	 * Which of the reported changes the caller never asked for.
	 *
	 * `changes` answers "what this write sets", and on a creation that is every
	 * attribute - including the ones nobody mentioned. A link created with a
	 * contact and a ticket comes back reporting role_code too, and a caller
	 * reading it cannot tell the default it was given from the value it sent.
	 *
	 * `overridden` already draws the neighbouring line - what was asked for and
	 * not kept - so the third case was the one with no name: not asked for, and
	 * applied anyway. Codes rather than values, because the value is in
	 * `changes` already and repeating it would double the block that matters.
	 *
	 * @param array<string, mixed> $aChanges  As {@see Changes()} reports them.
	 * @param array<int, string>   $aSupplied The attribute codes the caller sent.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function Defaulted(array $aChanges, array $aSupplied): array
	{
		return array_values(array_diff(array_keys($aChanges), $aSupplied));
	}

	/**
	 * The `overridden` property, for a tool that takes caller-supplied fields.
	 *
	 * Declared alongside {@see ChangesSchemaProperty()} and reported on every
	 * call, empty when there is nothing to say - the same one-shape rule the
	 * rest of the outcome follows.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function OverriddenSchemaProperty(): array
	{
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => 'Attribute code => {requested, effective} for each field you supplied whose value iTop would not keep. '
				.'Empty on a call where every supplied value survives. A non-empty entry means the write silently ignores what you asked for, usually because the attribute is derived from others - '
				.'a ticket\'s priority is computed from its urgency and impact, so setting it directly does nothing. Change the attributes it derives from instead.',
		];
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
				throw new ToolCallException(MCPHelper::OpaqueFailure("Could not validate {$sWhat}", $e));
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
	 * What a deletion would take with it, as a schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function DeletionPlanSchema(): array
	{
		$aObjectRef = [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'class' => ['type' => 'string'],
				'id'    => ['type' => 'integer'],
			],
		];

		return [
			'type'        => 'object',
			'description' => 'What iTop\'s cascading rules would do besides deleting the object itself.',
			'properties'  => [
				'deleted' => [
					'type'        => 'array',
					'items'       => $aObjectRef,
					'description' => 'Related objects deleted along with it.',
				],
				'updated' => [
					'type'        => 'array',
					'items'       => $aObjectRef,
					'description' => 'Related objects left in place but modified, e.g. an external key reset.',
				],
			],
			'required'    => ['deleted', 'updated'],
		];
	}

	/**
	 * Refuses a deletion whose cascade reaches objects this caller may not
	 * read, delete or modify.
	 *
	 * iTop does not do this, and the omission is deliberate on its side:
	 * MakeDeletionPlan() walks the references with
	 * GetReferencingObjectsForDeletion() in its allow-all-data mode, so the
	 * plan is complete whoever asked for it, and the console checks the delete right
	 * on the object the user clicked and on nothing the cascade drags along
	 * (cmdbabstract.class.inc.php). That is defensible there. A person clicked
	 * a button, saw the impact analysis iTop renders, and confirmed it.
	 *
	 * It is not defensible here. The caller is a language model acting on an
	 * instruction, often without a person reading the plan before the second
	 * call, and cascade is precisely the path by which "delete this one ticket"
	 * reaches classes an operator withheld on purpose. A right that can be
	 * routed around by deleting something else is not a right, and nothing in
	 * the audit trail would show it happening: the row says the tool deleted
	 * the ticket it was asked to delete.
	 *
	 * So this endpoint is stricter than the console, and knowingly: a deletion
	 * the console would perform can be refused here. That is the intended
	 * trade. An operator who wants the cascade to go through grants the rights
	 * on the classes it reaches, which is the same thing said out loud.
	 *
	 * Three questions per class in the plan, and the order matters. A class the
	 * caller cannot read at all is never named - the refusal says only that
	 * something unreadable is in the way, so a caller cannot map out the
	 * datamodel by deleting things and reading the error. A class it can read
	 * is named, because that is what makes the refusal actionable. The instance
	 * set is passed on the second and third, so an addon that grades rights per
	 * object gets to answer about these objects rather than about the class.
	 *
	 * @param DeletionPlan $oPlan  A plan already computed by CheckToDelete().
	 * @param string       $sWhat  What is being deleted, e.g. "UserRequest::12", named in the refusal.
	 *
	 * @throws ToolCallException When the cascade reaches something this caller may not touch.
	 *
	 * @since 1.0.0
	 */
	public static function CheckDeletionRights(DeletionPlan $oPlan, string $sWhat): void
	{
		$aRefused  = [];
		$bUnreadable = false;

		foreach ($oPlan->ListDeletes() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf($aEntries, 'to_delete'),
				UR_ACTION_DELETE,
				'deleted',
				$aRefused,
				$bUnreadable
			);
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aEntries) {
			self::JudgeCascadedClass(
				$sClass,
				self::ObjectsOf($aEntries, 'to_reset'),
				UR_ACTION_MODIFY,
				'modified',
				$aRefused,
				$bUnreadable
			);
		}

		if ($bUnreadable) {
			$aRefused[] = 'it also reaches related objects this user may not read.';
		}

		if (empty($aRefused)) {
			return;
		}

		throw new ToolCallException(sprintf(
			'%s cannot be deleted: iTop\'s cascading rules would reach objects this user is not allowed to touch. %s '
			.'This endpoint checks the whole cascade, not just the object named in the call. '
			.'Ask an administrator for the missing rights, or delete the related objects explicitly first.',
			$sWhat,
			implode(' ', $aRefused)
		));
	}

	/**
	 * Records what is wrong with one class of the cascade, if anything is.
	 *
	 * @param array<int, DBObject> $aObjects
	 * @param array<int, string>   $aRefused     Appended to.
	 * @param bool                 $bUnreadable  Raised when a class cannot be named at all.
	 */
	private static function JudgeCascadedClass(
		string $sClass,
		array $aObjects,
		int $iAction,
		string $sWhatWouldHappen,
		array &$aRefused,
		bool &$bUnreadable
	): void
	{
		if (empty($aObjects)) {
			return;
		}

		// Asked without the set first, and answered without naming the class:
		// a caller who may not read the class has no business learning that it
		// exists, let alone that it points at what they just tried to delete.
		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			$bUnreadable = true;

			return;
		}

		$oSet = self::SetOf($sClass, $aObjects);

		if ($oSet !== null && !self::Grants($sClass, UR_ACTION_READ, $oSet)) {
			// The class is readable and these objects are not, which is an
			// object-level rule the class-level question above cannot see.
			$aRefused[] = sprintf(
				"It would have %s objects of class '%s' that this user may not read.",
				$sWhatWouldHappen,
				$sClass
			);

			return;
		}

		if (!self::Grants($sClass, $iAction, $oSet)) {
			$aRefused[] = sprintf(
				"It would have %s %d object(s) of class '%s', which this user may not %s.",
				$sWhatWouldHappen,
				count($aObjects),
				$sClass,
				$iAction === UR_ACTION_DELETE ? 'delete' : 'modify'
			);
		}
	}

	/**
	 * Whether the caller is allowed this action on these objects.
	 *
	 * IsActionAllowed() is tri-state, and the two states are read the way the
	 * bulk tools already read them: with the objects in hand,
	 * UR_ALLOWED_DEPENDS has been resolved and anything short of a yes is a no;
	 * without them - the set could not be built - only an outright refusal
	 * counts, because "depends on the object" is not an answer that can be
	 * given about objects nobody passed.
	 *
	 * The first half is the strict reading, and it is the one that belongs on
	 * a cascade: a DEPENDS treated as a yes here is a right the caller was
	 * never actually granted.
	 */
	private static function Grants(string $sClass, int $iAction, ?DBObjectSet $oSet): bool
	{
		$iAllowed = UserRights::IsActionAllowed($sClass, $iAction, $oSet);

		return $oSet === null
			? $iAllowed !== UR_ALLOWED_NO
			: $iAllowed === UR_ALLOWED_YES;
	}

	/**
	 * The objects behind one class of a plan entry.
	 *
	 * @param array<int, array<string, mixed>> $aEntries
	 *
	 * @return array<int, DBObject>
	 */
	private static function ObjectsOf(array $aEntries, string $sKey): array
	{
		$aObjects = [];

		foreach ($aEntries as $aData) {
			$mObject = $aData[$sKey] ?? null;
			if ($mObject instanceof DBObject) {
				$aObjects[] = $mObject;
			}
		}

		return $aObjects;
	}

	/**
	 * Those objects as a set, so a rights addon that grades per object can.
	 *
	 * Null when the set cannot be built, which makes the caller fall back to
	 * the class-level answer rather than skip the check: a set this module
	 * failed to assemble is not evidence that anything is allowed.
	 *
	 * @param array<int, DBObject> $aObjects
	 */
	private static function SetOf(string $sClass, array $aObjects): ?DBObjectSet
	{
		try {
			return DBObjectSet::FromArray($sClass, $aObjects);
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * What a deletion would take with it, as the two lists both delete tools
	 * report.
	 *
	 * One implementation, read straight after {@see CheckDeletionRights()}: a
	 * rights rule applied to one copy of this and not the other is the bug a
	 * per-tool copy invites.
	 *
	 * Nothing here filters. It does not have to: by the time a plan is
	 * serialised, CheckDeletionRights() has refused every plan holding an
	 * object this caller may not read, so the class and id of everything left
	 * are things they could have looked up themselves. That is the other half
	 * of why the check is a refusal rather than a mask - a plan shown to a
	 * person before they approve it has to be complete, and the only way for it
	 * to be both complete and safe is for the incomplete case not to exist.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function SerializeDeletionPlan(?DeletionPlan $oPlan): array
	{
		if ($oPlan === null) {
			return ['deleted' => [], 'updated' => []];
		}

		$aDeleted = [];
		$aUpdated = [];

		foreach ($oPlan->ListDeletes() as $sClass => $aEntries) {
			foreach (array_keys($aEntries) as $iId) {
				$aDeleted[] = self::Identity($sClass, $iId);
			}
		}

		foreach ($oPlan->ListUpdates() as $sClass => $aEntries) {
			foreach (array_keys($aEntries) as $iId) {
				$aUpdated[] = self::Identity($sClass, $iId);
			}
		}

		return ['deleted' => $aDeleted, 'updated' => $aUpdated];
	}

	/**
	 * How every write names the object it acted on.
	 *
	 * 'id' always, plus the class's own key attribute when that is not simply
	 * 'id'. Two of the classes a stock iTop declares use 'link_id'; the other
	 * 173 use 'id', so spelling both unconditionally would write the same key
	 * twice into one array literal for all but those two. The key attribute is
	 * emitted exactly when it says something.
	 *
	 * Takes what iTop hands back rather than what the signature would prefer.
	 * DBObject::DBInsert() returns the key it set in DBInsertSingleTable(),
	 * which assigns it as `"$iNewKey"` - a string. Under strict_types a ?int
	 * parameter rejects that with a TypeError, thrown *after* the row is
	 * committed, which is how a create came to write a ticket and answer
	 * "Error while executing tool". Every create path hands this method an id
	 * straight out of the ORM, so the normalisation belongs here rather than in
	 * a cast at each of them - a cast the next such tool would be written
	 * without.
	 *
	 * @param int|string|null $mId The id as iTop reports it. Null before a creation has happened.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0 Accepts the id as a string too; previously ?int only, which iTop's own return value violated.
	 */
	public static function Identity(string $sClass, int|string|null $mId): array
	{
		$aIdentity = ['id' => self::AsId($mId)];

		try {
			$sKeyField = MetaModel::DBGetKey($sClass);
		} catch (Throwable $e) {
			return $aIdentity;
		}

		if (is_string($sKeyField) && $sKeyField !== '' && $sKeyField !== 'id') {
			$aIdentity[$sKeyField] = $aIdentity['id'];
		}

		return $aIdentity;
	}

	/**
	 * An object id as a number, or null when there is not one yet.
	 *
	 * The one place that decides what counts as an id, because two callers
	 * need the same answer for different reasons: this class reports it, and
	 * ObjectCreate uses it to tell a write that committed from one that did
	 * not.
	 *
	 * Anything not a positive number is null. iTop gives an unsaved object a
	 * deliberately negative temporary key (DBObject::GetNextTempId()), so a
	 * negative one means the row never landed - the same thing "no id yet"
	 * already means here - and reporting it would be reporting a row nobody
	 * can fetch.
	 *
	 * @param int|string|null $mId
	 *
	 * @since 1.0.0
	 */
	public static function AsId(int|string|null $mId): ?int
	{
		if ($mId === null || !is_numeric($mId)) {
			return null;
		}

		return (int) $mId > 0 ? (int) $mId : null;
	}

	/**
	 * The id of an object that reached the database, or null if it did not.
	 *
	 * For the failure path of a creation, where the question is not "what is
	 * this object's id" but "is there a row". DBInsert() commits inside
	 * DBInsertNoReload() and only then walks the loaded attributes calling
	 * ReadExternalValues(), so a throw from the second half leaves a committed
	 * row behind an exception - and the object carries its key from the moment
	 * it does.
	 *
	 * Answering "failed" there is the worst thing a create can do: creating is
	 * not idempotent, nothing in the protocol says a failed write may have
	 * written, and the reasonable next move on an error is to try again. That
	 * is a second object.
	 *
	 * Guarded, because it runs while an exception is already being reported:
	 * an object left in a state where even reading its key throws must not
	 * replace the failure being described with one from the describing.
	 *
	 * @since 1.0.0
	 */
	public static function CommittedId(?DBObject $oObject): ?int
	{
		if ($oObject === null) {
			return null;
		}

		try {
			return self::AsId($oObject->GetKey());
		} catch (Throwable $e) {
			return null;
		}
	}

	/**
	 * Whether the row a deletion was asked for is gone, when the deletion
	 * threw.
	 *
	 * {@see CommittedId()} asks this question for a creation. A deletion needs
	 * it for the same structural reason and answers it the other way round:
	 * DBDelete() removes the row and then runs what follows it - the objects
	 * that pointed at it, the AfterDelete hooks, iTop's own cleanup for
	 * classes that own something outside their table - and a throw from any of
	 * that arrives with the row already gone.
	 *
	 * The direction the two fail in is deliberately opposite, because the
	 * costly mistake is. A creation reported as failed is retried, and that is
	 * a second object, so a committed row is reported as the success it is. A
	 * deletion reported as succeeded when the row is still there tells an
	 * operator that a thing they were removing on purpose - a leaked
	 * credential, a record somebody staged - is gone when it is not, and a
	 * retry of a deletion that did work costs nothing. So this answers true
	 * only on positive evidence of absence: anything it cannot establish is
	 * reported as the failure it was.
	 *
	 * @return bool True only when the object was looked for and is not there.
	 * @since 1.0.0
	 */
	public static function IsGone(string $sClass, int $iId): bool
	{
		if (!class_exists('MetaModel')) {
			return false;
		}

		try {
			// Not must-be-found, and with all data allowed: a row this caller
			// can no longer see is not a row that was deleted, and answering
			// otherwise would turn a rights change into a deletion report.
			return MetaModel::GetObject($sClass, $iId, false, true) === null;
		} catch (Throwable $e) {
			return false;
		}
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
			throw new ToolCallException(MCPHelper::OpaqueFailure("Could not validate {$sWhat}", $e));
		}

		if ($bOk) {
			return;
		}

		throw new ToolCallException(empty($aIssues)
			? "{$sWhat} cannot be written as described."
			: "{$sWhat} cannot be written as described: ".implode(' ', array_map('strval', $aIssues)));
	}

	/**
	 * The attributes this write would touch, and what they would become.
	 *
	 * ListChanges() reports the pending values - every attribute for an object
	 * that does not exist yet, only the modified ones for one that does. They
	 * are rendered the same way a read renders them, so a dry run and the
	 * object it describes cannot disagree about what a value looks like.
	 *
	 * Rendered under the same read rights, too, which is not automatic: the
	 * masking of sensitive attributes lives in ObjectSerializer::Value(), but
	 * the read right is applied by Serialize(), and a write plan does not go
	 * through Serialize(). Writing an attribute and reading it are separate
	 * rights in iTop, so the set of attributes here is not a subset of what the
	 * caller may see - and iTop fills in more of them than the caller named,
	 * because DoComputeValues() and the lifecycle set attributes of their own
	 * from data the caller may have no right to.
	 *
	 * An unreadable attribute is reported as changed, with its value masked,
	 * rather than dropped: a dry run exists to be shown to someone before they
	 * approve the write, and one that silently omits part of what the write
	 * does is worse than one that says "this changes too, and you may not see
	 * it".
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Changes(DBObject $oObject, string $sClass): array
	{
		$aChanges = [];

		// Built once per object, and only if some attribute answers DEPENDS -
		// see ObjectSerializer::MayReadAttribute().
		$oInstanceSet = null;

		foreach (array_keys($oObject->ListChanges()) as $sAttCode) {
			if (!is_string($sAttCode) || $sAttCode === 'finalclass') {
				continue;
			}

			try {
				$aChanges[$sAttCode] = ObjectSerializer::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)
					? ObjectSerializer::Value($oObject, $sClass, $sAttCode)
					: ObjectSerializer::MASK;
			} catch (Throwable $e) {
				// Reporting a value is never worth failing the call it
				// describes.
				$aChanges[$sAttCode] = null;
			}
		}

		return $aChanges;
	}
}
