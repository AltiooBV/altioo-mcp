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
			'description' => 'true (the default) validates everything and reports what would change, without writing. Show that to the user, then call again with simulate=false to '.$sWhatItWouldDo.'.',
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
			'description'          => 'Attribute code => the value this write set, or would set. '.$sWhichOnes.' Empty when the write touched nothing.',
		];
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
