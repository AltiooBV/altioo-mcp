<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
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
	 * Runs iTop's own pre-write check, and refuses with what it found.
	 *
	 * CheckToWrite() returns [ok, issues, securityIssue] and fills the issues
	 * with sentences meant for a person - "Attribute X is mandatory" - which
	 * are exactly what a model needs to fix the call and try again.
	 *
	 * @throws ToolCallException When the object cannot be written as described.
	 */
	public static function Check(DBObject $oObject, string $sWhat): void
	{
		try {
			[$bOk, $aIssues] = $oObject->CheckToWrite();
		} catch (Throwable $e) {
			// A check that cannot run is not a check that passed.
			throw new ToolCallException("Could not validate {$sWhat}: ".$e->getMessage());
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
	 * @return array<string, mixed>
	 */
	public static function Changes(DBObject $oObject, string $sClass): array
	{
		$aChanges = [];

		foreach (array_keys($oObject->ListChanges()) as $sAttCode) {
			if (!is_string($sAttCode) || $sAttCode === 'finalclass') {
				continue;
			}

			try {
				$aChanges[$sAttCode] = ObjectSerializer::Value($oObject, $sClass, $sAttCode);
			} catch (Throwable $e) {
				// Reporting a value is never worth failing the call it
				// describes.
				$aChanges[$sAttCode] = null;
			}
		}

		return $aChanges;
	}
}
