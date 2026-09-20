<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use CMDBChangeOpCreate;
use CMDBChangeOpDelete;
use CMDBChangeOpSetAttribute;
use DBObjectSearch;
use DBObjectSet;
use Throwable;

/**
 * What one request actually changed, written onto its own audit row.
 *
 * The audit row said a write succeeded and not what it wrote. iTop records
 * that - `CMDBChangeOp`, one row per object touched - but only the timestamp
 * joined the two, which stops being a join the moment two requests overlap.
 * {@see \Altioo\iTop\Extension\MCP\Controller\MCPController} now stores the
 * change's key, and this stores a readable summary beside it.
 *
 * **Both, deliberately.** The key is the live drill-down: follow it and iTop
 * renders every operation with its old and new value. The summary is the part
 * that survives - `CMDBChange` retention is the operator's to set (see
 * `doc/security-summary.md`), and when a purge takes the change away, a row
 * saying only "a write succeeded" is back to where this started.
 *
 * **Why this does not call `CMDBChangeOp::GetDescription()`**, which is iTop's
 * own rendering of the same thing and would have been one line:
 *
 * - it returns **HTML** (`AttributeDefinition::DescribeChangeAsHTML()`), which
 *   is not what an `AttributeText` holds;
 * - it is **rights-filtered** and returns the empty string when the caller
 *   cannot read the attribute, so a summary built from it would be silently
 *   short rather than visibly redacted;
 * - it runs a `DBObjectSearch` **per operation**, so one
 *   `core_object_bulk_update` over 200 objects would fire 200 queries after
 *   the response had already been emitted.
 *
 * `objclass`, `objkey` and `attcode` are plain columns on the operation rows,
 * so what is written here costs one query for the whole change and no work per
 * object beyond string building.
 *
 * **Not in `Helper\`**, which `PublicSurfaceTest` treats wholly as the surface
 * a tool pack may depend on. Nothing outside this module composes change
 * summaries - a pack registering its own tools is audited by the controller
 * without touching this - and putting it there would have committed the shape
 * of this text to a major version for no caller.
 *
 * @since 1.0.0
 */
class ChangeSummary
{
	/**
	 * Objects named in full before the tail is counted instead.
	 *
	 * A bulk call can touch thousands. The cap is on objects rather than on
	 * characters so that the line a reader stops at is a whole one, and the
	 * count that follows is the honest remainder rather than a truncation
	 * nobody can size.
	 */
	public const MAX_OBJECTS = 50;

	/** Attribute codes listed for one object before they are counted instead. */
	public const MAX_ATTRIBUTES = 12;

	/** @var string Verb for an object this request brought into existence. */
	public const VERB_CREATED = 'created';

	/** @var string Verb for an object this request removed. */
	public const VERB_DELETED = 'deleted';

	/** @var string Verb for an object this request modified in place. */
	public const VERB_UPDATED = 'updated';

	/**
	 * The summary, from operations already reduced to plain values.
	 *
	 * Pure, and separated from everything that touches iTop, for the same
	 * reason {@see \Altioo\iTop\Extension\MCP\Helper\ChangeTracking::Compose()} is: the shape of this text is the
	 * whole feature - it is what an auditor reads after the change rows have
	 * been purged - and it is the only part of the mechanism that can be
	 * asserted without a database.
	 *
	 * Operations are grouped by object, because iTop writes one row per
	 * *attribute*: an update touching six fields is six rows and one object,
	 * and a summary listing it six times would misreport the size of the
	 * change. Creation and deletion win over update on the same object - an
	 * object created in this request was not also "updated" by the attributes
	 * that came with it.
	 *
	 * Order is the order the operations arrived, which is the order they
	 * happened. Nothing is sorted: a reader comparing this against the change
	 * rows should find the same sequence.
	 *
	 * @param array<int, array{class: string, key: int|string, verb: string, attcode?: string|null}> $aOperations
	 *
	 * @return string One line per object, empty when nothing was changed.
	 *
	 * @since 1.0.0
	 */
	public static function Compose(array $aOperations): string
	{
		/** @var array<string, array{class: string, key: int|string, verb: string, attributes: array<int, string>}> $aByObject */
		$aByObject = [];

		foreach ($aOperations as $aOperation) {
			$sClass = (string)($aOperation['class'] ?? '');
			$sVerb = (string)($aOperation['verb'] ?? '');
			if ($sClass === '' || $sVerb === '') {
				// A row that cannot name what it touched says nothing a reader
				// can act on, and a blank line in an audit summary reads like
				// data that was lost rather than data that was never there.
				continue;
			}

			$sKey = (string)($aOperation['key'] ?? '');
			$sIdentity = $sClass.'::'.$sKey;

			if (!isset($aByObject[$sIdentity])) {
				$aByObject[$sIdentity] = [
					'class'      => $sClass,
					'key'        => $sKey,
					'verb'       => $sVerb,
					'attributes' => [],
				];
			} elseif ($sVerb !== self::VERB_UPDATED) {
				// Created and deleted are statements about the object; updated
				// is a statement about one of its fields. The stronger one is
				// the one worth keeping.
				$aByObject[$sIdentity]['verb'] = $sVerb;
			}

			$sAttCode = isset($aOperation['attcode']) ? (string)$aOperation['attcode'] : '';
			if ($sAttCode !== '' && !in_array($sAttCode, $aByObject[$sIdentity]['attributes'], true)) {
				$aByObject[$sIdentity]['attributes'][] = $sAttCode;
			}
		}

		if ($aByObject === []) {
			return '';
		}

		$aLines = [];
		$iShown = 0;

		foreach ($aByObject as $aObject) {
			if ($iShown === self::MAX_OBJECTS) {
				break;
			}
			$aLines[] = self::ComposeLine($aObject);
			$iShown++;
		}

		$iRemaining = count($aByObject) - $iShown;
		if ($iRemaining > 0) {
			$aLines[] = '... and '.$iRemaining.' more object'.($iRemaining === 1 ? '' : 's');
		}

		return implode("\n", $aLines);
	}

	/**
	 * One object's line: what it is, then what happened to it.
	 *
	 * `Class::id` rather than the object's name on purpose. A name is the
	 * thing most likely to have been changed by the very request being
	 * described, and the most likely to change again before anybody reads
	 * this; the key does not move, and it is what an OQL needs.
	 *
	 * @param array{class: string, key: int|string, verb: string, attributes: array<int, string>} $aObject
	 *
	 * @since 1.0.0
	 */
	private static function ComposeLine(array $aObject): string
	{
		$sLine = $aObject['class'].'::'.$aObject['key'].' '.$aObject['verb'];

		if ($aObject['verb'] !== self::VERB_UPDATED || $aObject['attributes'] === []) {
			return $sLine;
		}

		$aAttributes = $aObject['attributes'];
		$iOver = count($aAttributes) - self::MAX_ATTRIBUTES;
		if ($iOver > 0) {
			$aAttributes = array_slice($aAttributes, 0, self::MAX_ATTRIBUTES);
			$aAttributes[] = '+'.$iOver.' more';
		}

		return $sLine.' ('.implode(', ', $aAttributes).')';
	}

	/**
	 * The operations recorded against one change, as {@see Compose()} takes them.
	 *
	 * One query for the whole change. The operation's own class is what says
	 * which verb applies - `CMDBChangeOpCreate`, `CMDBChangeOpDelete`, and the
	 * `CMDBChangeOpSetAttribute` family, which is a family rather than a class
	 * because iTop has one subclass per attribute type. `instanceof` against
	 * the base is what keeps a subclass added on a later branch from falling
	 * through to nothing.
	 *
	 * @return array<int, array{class: string, key: int|string, verb: string, attcode?: string|null}>
	 *
	 * @since 1.0.0
	 */
	public static function OperationsOfChange(int $iChangeId): array
	{
		$oSearch = new DBObjectSearch('CMDBChangeOp');
		$oSearch->AddCondition('change', $iChangeId, '=');
		// The audit row is written for the caller, but it records what the
		// instance did. A summary narrowed by what this user may read would
		// under-report a change the user themselves had just made.
		$oSearch->AllowAllData();

		$oSet = new DBObjectSet($oSearch);
		$aOperations = [];

		while ($oOperation = $oSet->Fetch()) {
			$sVerb = self::VerbOf($oOperation);
			if ($sVerb === null) {
				continue;
			}

			$aOperations[] = [
				'class'   => (string)$oOperation->Get('objclass'),
				'key'     => $oOperation->Get('objkey'),
				'verb'    => $sVerb,
				'attcode' => ($oOperation instanceof CMDBChangeOpSetAttribute)
					? (string)$oOperation->Get('attcode')
					: null,
			];
		}

		return $aOperations;
	}

	/**
	 * What an operation did, or null when it is one this summary has no word for.
	 *
	 * `CMDBChangeOpPlugin` and anything a module adds below `CMDBChangeOp`
	 * land here. Skipped rather than guessed at: a row invented for an
	 * operation nobody here understands is worse than a row that is not there,
	 * because the change key beside it still leads to the full record.
	 *
	 * @param object $oOperation A `CMDBChangeOp`.
	 *
	 * @since 1.0.0
	 */
	private static function VerbOf(object $oOperation): ?string
	{
		if ($oOperation instanceof CMDBChangeOpCreate) {
			return self::VERB_CREATED;
		}
		if ($oOperation instanceof CMDBChangeOpDelete) {
			return self::VERB_DELETED;
		}
		if ($oOperation instanceof CMDBChangeOpSetAttribute) {
			return self::VERB_UPDATED;
		}

		return null;
	}

	/**
	 * The summary for a change, or the empty string when there is none to give.
	 *
	 * Never throws. This runs after the response has been emitted, on the way
	 * to writing an audit row, and a failure here must not be the thing that
	 * loses the row: a summary that could not be built is a missing sentence,
	 * while a lost row is a call with no record at all.
	 *
	 * @since 1.0.0
	 */
	public static function OfChange(?int $iChangeId): string
	{
		if ($iChangeId === null || $iChangeId <= 0) {
			return '';
		}

		try {
			return self::Compose(self::OperationsOfChange($iChangeId));
		} catch (Throwable $e) {
			MCPHelper::LogError('Could not summarise change '.$iChangeId.' ('.get_class($e).').');

			return '';
		}
	}
}
