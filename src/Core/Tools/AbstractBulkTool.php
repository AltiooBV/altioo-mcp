<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use DBObject;
use DBObjectSearch;
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
 * object is checked on its own, because iTop's object-level rights are what
 * separate "may modify a User" from "may modify their own User and no one
 * else's" - the password case. A bulk tool that checks the class once and then
 * loops is an escalation with a progress counter.
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

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves. */
	public function getToolset(): string
	{
		return 'objects';
	}

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
		$sKey = MetaModel::DBGetKey($sClass);
		$oSet = new DBObjectSet(DBObjectSearch::FromOQL("SELECT {$sClass} WHERE {$sKey} = {$iId}"));

		// The set applies read rights itself, so an empty one is "not found or
		// not yours", which are answered alike on purpose.
		if ($oSet->Count() === 0) {
			return "Object {$sClass}::{$iId} not found.";
		}

		$sFinalClass = MetaModel::GetFinalClassName($sClass, $iId);
		if ($sFinalClass !== $sClass) {
			return "Object {$sClass}::{$iId} is of class '{$sFinalClass}'; call this tool again with that class.";
		}

		if (!UserRights::IsActionAllowed($sClass, $iAction, $oSet)) {
			return "Access denied: cannot {$sVerb} {$sClass}::{$iId}.";
		}

		$oObject = $oSet->Fetch();
		if ($oObject->IsReadOnly()) {
			return "Object {$sClass}::{$iId} is read-only.";
		}

		return $oObject;
	}

	/**
	 * Attribute values, checked and converted, or the reasons they were not.
	 *
	 * Per-attribute write rights are checked per object rather than once for
	 * the class, for the same reason the object right is.
	 *
	 * @param array<string, mixed> $aFields
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} Values, and issues.
	 */
	protected static function validatedValues(string $sClass, array $aFields): array
	{
		$aValues = [];
		$aIssues = [];

		foreach ($aFields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				$aIssues[] = "Unknown attribute '{$sAttCode}' on class '{$sClass}'.";
				continue;
			}
			if (!UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY)) {
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
	 * The shape every bulk tool answers with.
	 *
	 * Per object, always, including the ones that worked: a caller that
	 * receives "38 succeeded" and nothing else cannot tell the user which two
	 * did not, and a model will happily report the batch as done.
	 *
	 * @param array<int, array<string, mixed>> $aOutcomes
	 *
	 * @return array<string, mixed>
	 */
	protected static function report(string $sClass, bool $bSimulate, array $aOutcomes): array
	{
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
	 * @return array<string, mixed>
	 */
	protected static function outcome(int $iId, bool $bOk, string $sMessage = ''): array
	{
		$aOutcome = ['id' => $iId, 'status' => $bOk ? 'ok' : 'error'];
		if ($sMessage !== '') {
			$aOutcome['message'] = $sMessage;
		}

		return $aOutcome;
	}
}
