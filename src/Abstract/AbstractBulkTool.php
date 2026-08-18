<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use DBObject;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use RestUtils;
use UserRights;

/**
 * What the bulk tools share, which is almost entirely the checking.
 *
 * A model asked to close forty tickets will otherwise call the single-object
 * tool forty times: forty round trips, forty audit rows, and a run that can
 * stop half way with no record of where. iTop has the notion already - the
 * console does bulk modify, and the permission model has separate bulk
 * actions for it.
 *
 * Two rules hold this together, and both matter.
 *
 * The first is that UR_ACTION_BULK_MODIFY and UR_ACTION_BULK_DELETE are
 * checked before anything else. They are not decoration: an operator can grant
 * a profile the right to edit one object at a time and withhold the right to
 * do it to a thousand, and a bulk tool that only checked the single-object
 * right would quietly hand back what was deliberately withheld.
 *
 * The second is that the class-level answer is never the end of it. Every
 * object is asked about on its own, and written through CheckToWrite(), which
 * is where a datamodel expresses "only the owner may close this" -
 * DoCheckToWrite() sees the object, and nothing above it does. A bulk tool
 * that checks the class once and then loops is an escalation with a progress
 * counter.
 *
 * Be careful what is expected of the rights check specifically. iTop's shipped
 * addon documents that it ignores the instance set for attributes - "acceptable
 * to consider only the root class of the object set" - so under a stock install
 * a profile that may write an attribute may write it on every object of the
 * class it can see. The mono set is still passed, because the API is tri-state
 * and an addon that does grade per object signals it with UR_ALLOWED_DEPENDS,
 * and because iTop's own code passes one at every equivalent call site. What it
 * is not is a substitute for CheckToWrite().
 *
 * Both rules are the reason this is worth extending rather than reproducing:
 * a pack that writes its own bulk tool and forgets either one has written an
 * escalation, and neither omission shows up in testing, because the account a
 * developer tests with holds both rights.
 *
 * Unlike the core tools it serves, it declares no namespace: that is yours,
 * and the registry refuses 'core' from anything outside this module.
 *
 * @since 1.0.0
 * @since 1.0.0 Moved here from Core\Tools and covered by the versioning policy.
 */
abstract class AbstractBulkTool extends AbstractMCPTool
{
	/**
	 * Most objects one call may touch.
	 *
	 * Not a technical limit. It is the point past which a model has stopped
	 * acting on a list a human recognises and started acting on a query it
	 * wrote itself, and the blast radius of a mistake stops being reviewable.
	 */
	const MAX_OBJECTS = 100;

	/**
	 * The class argument, the id list and the dry run, spelled once.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function targetSchemaProperties(string $sWhatHappens): array
	{
		return [
			'class' => [
				'type'        => 'string',
				'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name. Every id must be an object of this exact class.',
			],
			'ids'   => [
				'type'        => 'array',
				'description' => 'Ids of the objects to act on, at most '.self::MAX_OBJECTS.'. Find them with core_object_search_by_oql or core_object_search_by_class.',
				'items'       => ['type' => 'integer', 'minimum' => 1],
				'minItems'    => 1,
				'maxItems'    => self::MAX_OBJECTS,
			],
			'simulate' => [
				'type'        => 'boolean',
				'description' => 'true (the default) checks every object and reports what would happen, without changing anything. Show that report to the user, then call again with simulate=false to '.$sWhatHappens.'.',
				'default'     => true,
			],
			// One reason for the batch, because one call is one decision: the
			// forty tickets are being closed for the same reason, and that is
			// what makes the line worth reading on each of them.
			'comment'  => ChangeTracking::CommentSchemaProperty('these objects are being changed'),
		];
	}

	/**
	 * The class, validated for a bulk action of this kind.
	 *
	 * @param int $iBulkAction   UR_ACTION_BULK_MODIFY or UR_ACTION_BULK_DELETE.
	 * @param int $iSingleAction The single-object right the bulk one sits on top of.
	 *
	 * @throws ToolCallException
	 */
	protected static function checkBulkAllowed(string $sClass, int $iBulkAction, int $iSingleAction, string $sVerb): void
	{
		if (!MetaModel::IsValidClass($sClass)) {
			throw new ToolCallException("Unknown class '{$sClass}'.");
		}
		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$sClass}'."); // hide that the class exists
		}
		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException("The database is in read-only mode, cannot {$sVerb} objects.");
		}
		if (MetaModel::IsAbstract($sClass)) {
			throw new ToolCallException("Class '{$sClass}' is abstract; act on the final class of each object instead.");
		}
		if (!UserRights::IsActionAllowed($sClass, $iSingleAction)) {
			throw new ToolCallException("Access denied: cannot {$sVerb} objects of class '{$sClass}'.");
		}
		if (!UserRights::IsActionAllowed($sClass, $iBulkAction)) {
			// Deliberately its own message: the caller may well be allowed to
			// do this one object at a time, and that is the useful thing to
			// know.
			throw new ToolCallException(
				"Access denied: this user may not {$sVerb} objects of class '{$sClass}' in bulk. "
				."Acting on them one at a time may still be allowed."
			);
		}
	}

	/**
	 * @param array<int, mixed> $aIds
	 *
	 * @return array<int, int>
	 *
	 * @throws ToolCallException
	 */
	protected static function checkIds(array $aIds): array
	{
		if (empty($aIds)) {
			throw new ToolCallException('No ids given.');
		}
		if (count($aIds) > self::MAX_OBJECTS) {
			throw new ToolCallException(sprintf(
				'This call would touch %d objects, and %d is the most one call may. Work in batches of %d.',
				count($aIds),
				self::MAX_OBJECTS,
				self::MAX_OBJECTS
			));
		}

		$aClean = [];
		foreach ($aIds as $mId) {
			if (!is_int($mId) && !(is_string($mId) && ctype_digit($mId))) {
				throw new ToolCallException('Ids must be integers; got '.var_export($mId, true).'.');
			}
			$iId = (int)$mId;
			if ($iId < 1) {
				throw new ToolCallException("Invalid id '{$iId}'.");
			}
			$aClean[$iId] = $iId;
		}

		return array_values($aClean);
	}

	/**
	 * One object, if this caller may act on that particular object.
	 *
	 * The object-level check is the point of the exercise: class rights say a
	 * user may modify a Person, object rights say which Person. Passing a set
	 * of exactly one object is how iTop is asked about one object.
	 *
	 * @return DBObject|string The object, or the reason it cannot be acted on.
	 */
	protected static function objectFor(string $sClass, int $iId, int $iAction, string $sVerb)
	{
		$oSet = new DBObjectSet(ObjectQuery::ById($sClass, $iId));

		// The set applies read rights itself, so an empty one is "not found or
		// not yours", which are answered alike on purpose.
		if ($oSet->Count() === 0) {
			return "Object {$sClass}::{$iId} not found.";
		}

		// Fetched before anything is decided about the class: Fetch()
		// instantiates the leaf from the finalclass column it has already read,
		// so the object answers what GetFinalClassName() was being asked in a
		// query of its own. Reading the row the Count() above already matched
		// costs nothing extra, and the order of the answers below is unchanged.
		//
		// Rewound immediately, because IsActionAllowed() below is handed this
		// same set and the rights addon is free to iterate it: a spent cursor
		// would have it decide on no objects at all.
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $sClass) {
			return "Object {$sClass}::{$iId} is of class '{$sFinalClass}'; call this tool again with that class.";
		}

		if (!UserRights::IsActionAllowed($sClass, $iAction, $oSet)) {
			return "Access denied: cannot {$sVerb} {$sClass}::{$iId}.";
		}

		if ($oObject->IsReadOnly()) {
			return "Object {$sClass}::{$iId} is read-only.";
		}

		return $oObject;
	}

	/**
	 * The class-level gate on the attributes a batch means to write.
	 *
	 * Asked once, before any object is looked at, and it throws rather than
	 * reporting per object: an attribute the caller may not write on the class
	 * is not going to become writable on the fortieth object, and answering
	 * that with a hundred identical failures buries the one thing the caller
	 * needs to read.
	 *
	 * It is a gate, not the decision. IsActionAllowedOnAttribute() answers
	 * UR_ALLOWED_DEPENDS when the addon grades the attribute per object, and
	 * that counts as passing here - the per-object check in validatedValues()
	 * is what resolves it.
	 *
	 * @param array<string, mixed> $aFields
	 *
	 * @throws ToolCallException When an attribute is refused for the class outright.
	 */
	protected static function checkAttributesWritable(string $sClass, array $aFields): void
	{
		$aRefused = [];

		foreach (array_keys($aFields) as $sAttCode) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				$aRefused[] = "Unknown attribute '{$sAttCode}' on class '{$sClass}'.";
				continue;
			}
			if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY) === UR_ALLOWED_NO) {
				$aRefused[] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			if (!MetaModel::GetAttributeDef($sClass, $sAttCode)->IsWritable()) {
				$aRefused[] = "Attribute '{$sAttCode}' is not writable.";
			}
		}

		if (!empty($aRefused)) {
			throw new ToolCallException(
				'This call cannot be made on any object of this class: '.implode(' ', $aRefused)
			);
		}
	}

	/**
	 * Attribute values, checked and converted, or the reasons they were not.
	 *
	 * The write right is asked with the object in hand, not just its class.
	 * IsActionAllowedOnAttribute() is tri-state, and an addon that grades an
	 * attribute per object says so by answering UR_ALLOWED_DEPENDS to the
	 * class-level question; passing the instance set is what turns that into a
	 * yes or a no. iTop's own code passes a mono set at every equivalent call
	 * site - see cmdbchangeop.class.inc.php.
	 *
	 * The shipped addon is not one of those: UserRightsProfile ignores the set
	 * for attributes on purpose, so on a stock install this answers per class
	 * and profile. It is passed anyway because it costs nothing, because the
	 * contract allows better, and because treating DEPENDS as a yes is the one
	 * reading that is wrong under every addon. The per-object rule that does
	 * hold on a stock install is DoCheckToWrite(), reached through
	 * WritePlan::Check().
	 *
	 * @param array<string, mixed> $aFields
	 * @param DBObjectSet|null     $oInstanceSet The object being written, as a set of one. Null falls back to the class-level answer.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} Values, and issues.
	 */
	protected static function validatedValues(string $sClass, array $aFields, ?DBObjectSet $oInstanceSet = null): array
	{
		$aValues = [];
		$aIssues = [];

		foreach ($aFields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				$aIssues[] = "Unknown attribute '{$sAttCode}' on class '{$sClass}'.";
				continue;
			}
			// With an object in hand, UR_ALLOWED_DEPENDS has been resolved and
			// anything short of a yes is a no. Without one - a creation, where
			// there is no object yet to grade - only an outright refusal counts,
			// because "depends on the object" cannot be answered before the
			// object exists, and DBInsert() checks it again anyway.
			$iAllowed = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY, $oInstanceSet);
			$bDenied = $oInstanceSet === null
				? $iAllowed === UR_ALLOWED_NO
				: $iAllowed !== UR_ALLOWED_YES;
			if ($bDenied) {
				$aIssues[] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			if (!MetaModel::GetAttributeDef($sClass, $sAttCode)->IsWritable()) {
				$aIssues[] = "Attribute '{$sAttCode}' is not writable.";
				continue;
			}

			try {
				// The SDK hands us arrays for nested JSON objects; RestUtils
				// branches on stdClass. See RestValue.
				$aValues[$sAttCode] = RestUtils::MakeValue($sClass, $sAttCode, RestValue::FromDecodedJson($value));
			} catch (\Exception $e) {
				$aIssues[] = "Invalid value for attribute '{$sAttCode}': ".$e->getMessage();
			}
		}

		return [$aValues, $aIssues];
	}

	/**
	 * The result shape every bulk tool reports.
	 *
	 * Fixed all the way down, which a single-object read never is: a bulk call
	 * answers with counts and one small outcome per object, never with the
	 * objects themselves, so the schema holds whatever the class is. The
	 * per-object entry is what a caller most needs described, because a bulk
	 * call can half succeed and the only way to know which half is to read
	 * these.
	 *
	 * @param array<string, array<string, mixed>> $aOutcomeProperties Fields this tool adds to an outcome.
	 *
	 * @return array<string, mixed>
	 */
	protected static function reportSchema(array $aOutcomeProperties = []): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'     => [
					'type'        => 'string',
					'description' => 'The class every object in the call belongs to.',
				],
				'simulated' => [
					'type'        => 'boolean',
					'description' => 'true when the call validated everything and wrote nothing.',
				],
				'total'     => [
					'type'        => 'integer',
					'description' => 'Objects the call was asked to act on.',
				],
				'succeeded' => [
					'type'        => 'integer',
					'description' => 'Of those, the ones that succeeded, or would have.',
				],
				'failed'    => [
					'type'        => 'integer',
					'description' => 'Of those, the ones that did not. Read their messages: a bulk call reports each object separately because it can partly succeed.',
				],
				'objects'   => [
					'type'        => 'array',
					'description' => 'One entry per object, in the order they were given.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => self::outcomeProperties() + $aOutcomeProperties,
						// Every declared key on every entry, the successes and
						// the failures alike. An entry that carries `changes`
						// when it worked and not when it did not is two shapes
						// wearing one schema, and a caller reading a partly
						// failed batch is exactly who cannot afford to guess -
						// see report(), which is what guarantees it.
						'required'             => array_values(array_merge(
							array_keys(self::outcomeProperties()),
							array_keys($aOutcomeProperties)
						)),
					],
				],
			],
			'required'   => ['class', 'simulated', 'total', 'succeeded', 'failed', 'objects'],
		];
	}

	/**
	 * The keys every per-object entry carries, whatever the tool and whatever
	 * happened to that object.
	 *
	 * `row` is here for the delete and update tools too, not only for create:
	 * they loop over the ids in the order they were given, so the position is
	 * as real there as it is for a creation, and a caller correlating a report
	 * back to what it sent should not have to know which tool numbers its
	 * entries and which names them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function outcomeProperties(): array
	{
		return [
			'row'     => [
				'type'        => 'integer',
				'description' => 'Zero-based position in the list that was sent, so an entry can be matched to its input.',
			],
			'id'      => [
				'type'        => ['integer', 'null'],
				'description' => 'Identifier of the object, or null when there is not one - a creation that was only simulated, or one that failed.',
			],
			'status'  => [
				'type'        => 'string',
				'enum'        => ['ok', 'error'],
				'description' => 'Outcome for this object alone.',
			],
			'message' => [
				'type'        => 'string',
				'description' => 'Why it failed, or what happened - or would have happened - when it did not. Empty when there is nothing to add.',
			],
		];
	}

	/**
	 * The shape every bulk tool answers with.
	 *
	 * Per object, always, including the ones that worked: a caller that
	 * receives "38 succeeded" and nothing else cannot tell the user which two
	 * did not, and a model will happily report the batch as done.
	 *
	 * $aDefaults is what makes the schema true rather than aspirational: a tool
	 * declaring `changes` or `deletionPlan` passes its empty value here, and
	 * every entry that did not produce one gets it. Doing it at the one place
	 * the entries are collected beats remembering it on each of the six or
	 * seven paths that can produce a failure.
	 *
	 * @param array<int, array<string, mixed>> $aOutcomes
	 * @param array<string, mixed>             $aDefaults Extra keys this tool declares, with the value an entry gets when it has none.
	 *
	 * @return array<string, mixed>
	 */
	protected static function report(string $sClass, bool $bSimulate, array $aOutcomes, array $aDefaults = []): array
	{
		if (!empty($aDefaults)) {
			$aOutcomes = array_map(static fn (array $a): array => $a + $aDefaults, $aOutcomes);
		}

		$iOk = count(array_filter($aOutcomes, static fn (array $a): bool => $a['status'] === 'ok'));

		return [
			'class'     => $sClass,
			'simulated' => $bSimulate,
			'total'     => count($aOutcomes),
			'succeeded' => $iOk,
			'failed'    => count($aOutcomes) - $iOk,
			'objects'   => $aOutcomes,
		];
	}

	/**
	 * One entry, with every key it is going to have.
	 *
	 * The message is emitted even when empty. It used to be added only when
	 * there was one, which meant a successful real write answered with a
	 * different set of keys from a successful dry run - the same
	 * shape-by-parameter problem the single-object tools had, one level down.
	 *
	 * @param int|null $iId  Null when the object has no identifier yet, or never got one.
	 * @param int      $iRow Zero-based position in the list that was sent.
	 *
	 * @return array<string, mixed>
	 */
	protected static function outcome(?int $iId, int $iRow, bool $bOk, string $sMessage = ''): array
	{
		return [
			'row'     => $iRow,
			'id'      => $iId,
			'status'  => $bOk ? 'ok' : 'error',
			'message' => $sMessage,
		];
	}
}
