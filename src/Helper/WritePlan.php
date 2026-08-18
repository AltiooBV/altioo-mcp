<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use DBObject;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * What a write would do, established before it does it.
 *
 * Two things every writing tool needs and only the delete tool had.
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
 * organisation should not create or modify anything on a first call, and the
 * same two-step the delete tool already required - simulate, show the user,
 * call again - is what makes that true of every write. Running the check
 * without the write is exactly what makes a dry run worth anything: it answers
 * "would this work", not just "is this well formed".
 *
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
	 * @param array<string, array<string, mixed>> $aProperties Properties this particular tool adds.
	 * @param array<int, string>                  $aRequired   Property names it always reports.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function OutcomeSchema(array $aProperties = [], array $aRequired = []): array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'     => [
					'type'        => 'string',
					'description' => 'Final class of the object the call acted on.',
				],
				'id'        => [
					'type'        => 'integer',
					'description' => 'Identifier of the object. Absent from a create dry run, which has not created anything yet.',
				],
				'simulated' => [
					'type'        => 'boolean',
					'description' => 'true when the call validated everything and wrote nothing.',
				],
				'changes'   => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Attribute code => the value this write set, or would set. Every attribute for a creation, only the modified ones for an update.',
				],
			] + $aProperties,
			'required'   => array_values(array_unique(array_merge(['class', 'simulated'], $aRequired))),
			// The identifier is reported a second time under the class's own
			// key attribute - 'id' for every stock class but a link class,
			// where it is 'link_id' - so the shape is open by construction.
			'additionalProperties' => true,
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
