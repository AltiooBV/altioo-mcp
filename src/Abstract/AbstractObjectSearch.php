<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use DBObject;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * Paging, ordering and serialization for a tool that returns a set of objects.
 *
 * Written for the two core searches and kept general enough to be the base of
 * yours: a pack that has its own way of selecting objects - a saved query, a
 * class of its own, a filter no OQL expresses in one line - gets the parts
 * that are the same either way, which are the ones easy to get subtly wrong.
 * The offset/limit contract in particular has a rule in it that costs a caller
 * silent data loss when missed: object-level rights remove rows from a page
 * *after* the database has counted them, so a short page is not the end of the
 * set, and `has_more` rather than `count($rows) < $limit` is the answer.
 *
 * Unlike the core tools it serves, it declares no namespace: that is yours,
 * and the registry refuses 'core' from anything outside this module.
 *
 * @api
 * @since 1.0.0
 */
abstract class AbstractObjectSearch extends AbstractMCPTool
{
	const MIN_LIMIT = 1;
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT = 1000;

	/** Most objects a page may carry when it reports every attribute. */
	const MAX_LIMIT_ALL_FIELDS = 25;

	const MIN_OFFSET = 0;
	const DEFAULT_OFFSET = 0;

	const SORT_ASC = 'asc';
	const SORT_DESC = 'desc';
	const DEFAULT_SORT = self::SORT_ASC;

	/** The one attribute every class has, and the only unique one. */
	const TIEBREAK_ATTRIBUTE = 'id';


	/**
	 * The two properties every paged tool declares, so that both spell them
	 * the same way.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function pagingSchemaProperties(): array
	{
		return [
			'limit'  => [
				'type'        => 'integer',
				'description' => 'Maximum number of objects to return.',
				'default'     => self::DEFAULT_LIMIT,
				'minimum'     => self::MIN_LIMIT,
				'maximum'     => self::MAX_LIMIT,
			],
			'offset' => [
				'type'        => 'integer',
				'description' => 'Number of objects to skip. Paging is stable: results are ordered by the requested attribute and then by id, so no object is returned twice or skipped between pages. The result reports has_more and next_offset; pass next_offset back here for the following page rather than working the arithmetic out, since a page can be shorter than limit when object-level rights remove rows from it.',
				'default'     => self::DEFAULT_OFFSET,
				'minimum'     => self::MIN_OFFSET,
			],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected static function orderingSchemaProperties(): array
	{
		return [
			'order_by'        => [
				'type'        => 'string',
				'description' => 'Attribute code to sort on, e.g. "start_date" or "id". Empty (the default) uses the order the datamodel declares for the class.',
				'default'     => '',
			],
			'order_direction' => [
				'type'        => 'string',
				'description' => 'Sort direction.',
				'enum'        => [self::SORT_ASC, self::SORT_DESC],
				'default'     => self::DEFAULT_SORT,
			],
		];
	}

	/**
	 * The sort order a page is read in, always ending in a unique attribute.
	 *
	 * Without a tiebreaker, offset paging over a non-unique order is not
	 * paging at all: the class default is friendlyname, forty tickets can share
	 * one, and MySQL is free to return them in a different order per query. An
	 * object then shows up on two consecutive pages while another is never
	 * returned - silently, since each page looks perfectly well formed.
	 *
	 * @param string $sOrderBy   An attribute code, or '' for the class default.
	 * @param string $sDirection self::SORT_ASC or self::SORT_DESC.
	 *
	 * @return array<string, bool> As DBObjectSet expects it: attribute code => ascending.
	 *
	 * @throws ToolCallException When the attribute cannot be sorted on by this caller.
	 */
	protected static function orderBy(string $sClass, string $sOrderBy, string $sDirection): array
	{
		if (!in_array($sDirection, [self::SORT_ASC, self::SORT_DESC], true)) {
			throw new ToolCallException("Invalid order_direction '{$sDirection}'. Use '".self::SORT_ASC."' or '".self::SORT_DESC."'.");
		}

		$bAscending = $sDirection === self::SORT_ASC;
		$sOrderBy = trim($sOrderBy);

		if ($sOrderBy === '') {
			$aOrderBy = MetaModel::GetOrderByDefault($sClass);
			if ($sDirection === self::SORT_DESC) {
				$aOrderBy = array_map(static fn (bool $bAsc): bool => !$bAsc, $aOrderBy);
			}
		} else {
			if ($sOrderBy !== self::TIEBREAK_ATTRIBUTE && !MetaModel::IsValidAttCode($sClass, $sOrderBy)) {
				throw new ToolCallException("Unknown attribute '{$sOrderBy}' on class '{$sClass}', cannot sort on it.");
			}
			if ($sOrderBy !== self::TIEBREAK_ATTRIBUTE
				&& UserRights::IsActionAllowedOnAttribute($sClass, $sOrderBy, UR_ACTION_READ) === UR_ALLOWED_NO) {
				// Ordering by an attribute reports on its values, so it takes
				// the same right as reading it.
				throw new ToolCallException("Unknown attribute '{$sOrderBy}' on class '{$sClass}', cannot sort on it.");
			}

			$aOrderBy = [$sOrderBy => $bAscending];
		}

		$aOrderBy[self::TIEBREAK_ATTRIBUTE] ??= $bAscending;

		return $aOrderBy;
	}

	/**
	 * Whether another page exists, and the offset that reads it.
	 *
	 * Derivable from total, limit and offset, and derived wrongly often enough
	 * to be worth stating: a page can come back shorter than limit because
	 * object-level rights removed rows from it, and a caller that concludes
	 * "short page, therefore the end" stops early and silently. The database
	 * skipped limit rows whatever the caller was allowed to see, so the next
	 * page starts at offset + limit and the count decides whether there is one.
	 *
	 * next_offset is null at the end rather than the offset past the end, so
	 * that "call again with this" and "there is nothing more" cannot be
	 * confused for one another.
	 *
	 * @return array{has_more: bool, next_offset: int|null}
	 */
	protected static function pagingFooter(int $iTotal, int $iLimit, int $iOffset): array
	{
		$iNext = $iOffset + $iLimit;
		$bHasMore = $iNext < $iTotal;

		return [
			'has_more'    => $bHasMore,
			'next_offset' => $bHasMore ? $iNext : null,
		];
	}

	/**
	 * The class to report a fetched row as, or null when it must not be
	 * reported at all.
	 *
	 * Fetch() already returns each row as its final class: DBObjectSet reads
	 * the finalclass column and MetaModel::GetObjectByRow() instantiates the
	 * leaf, which is why iTop's own global search identifies a leaf with
	 * get_class(). Asking the database for a class name it has already sent,
	 * and then re-reading the object under that name, was two queries per row
	 * spent on an answer already in hand.
	 *
	 * The rights checks are not redundant and stay. A search for a parent
	 * class returns its subclasses, and being allowed to read Ticket says
	 * nothing about being allowed to read Incident - neither at class level
	 * nor, for a profile whose rights depend on the object, at object level.
	 *
	 * @return string|null The final class, or null to skip the row.
	 */
	protected static function finalClassIfReadable(DBObject $oObject, string $sSetClass): ?string
	{
		$sFinalClass = get_class($oObject);

		if ($sFinalClass === $sSetClass) {
			// Covered by the check already made on the whole set.
			return $sFinalClass;
		}

		if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
			return null;
		}

		// Object-level rights answer "depends" for the set and yes or no for
		// one object, so the row is asked about on its own.
		$oOneRow = new DBObjectSet(ObjectQuery::ById($sFinalClass, (int)$oObject->GetKey()));

		if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ, $oOneRow)) {
			return null; // hide that the object exists
		}

		return $sFinalClass;
	}

	/**
	 * @param array<int, string>|null $aFields
	 */
	protected static function serializeObject(DBObject $oObject, string $sClass, ?array $aFields = null, bool $bAudit = false, bool $bActions = false): array
	{
		$aObject = ObjectSerializer::Serialize($oObject, $sClass, $aFields);

		if ($bAudit) {
			$aObject['audit'] = ObjectHistory::AttributionFor($oObject);
		}

		if ($bActions) {
			// One instance set for both, built only if some gate asks for it.
			$oInstanceSet = null;
			$aObject['rights'] = ObjectSerializer::RightsOn($oObject, $sClass, null, $oInstanceSet);
			$aObject['stimuli'] = ObjectSerializer::StimuliOn($oObject, $sClass, $aObject['rights'], $oInstanceSet);
		}

		return $aObject;
	}

	/**
	 * The argument that asks for creation and last-update attribution.
	 *
	 * Off by default, and not because the answer is rarely wanted: it is two
	 * more reads per object, and the only bound on that is the page size.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	/**
	 * The argument that asks what can be done with each object returned.
	 *
	 * Off by default for the same reason as audit: the class-level gates are
	 * free, but resolving a 'depends' and asking about each stimulus is a
	 * question per object - and for stimuli, per transition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function actionsSchemaProperties(): array
	{
		return ['actions' => [
			'type'        => 'boolean',
			'description' => 'For each object returned, report what may be done to it now: the update and delete gates answered for that object rather than for its class, '
				.'and the stimuli its current state accepts. Use it to plan writes that will not be refused. '
				.'Costs a question per object, so it is refused for a page of more than '.ObjectHistory::MAX_AUDIT_PAGE.'.',
			'default'     => false,
		]];
	}

	protected static function auditSchemaProperties(): array
	{
		return ['audit' => [
			'type'        => 'boolean',
			'description' => 'Report when each object was created and when it was last changed, with the user behind each. '
				.'iTop keeps no such field on an object, so this is read from the change log - which costs two extra reads per object, '
				.'and is therefore refused for a page of more than '.ObjectHistory::MAX_AUDIT_PAGE.'. Use core_object_history for the full record of one object.',
			'default'     => false,
		]];
	}

	/**
	 * Refuses a page too large to attribute, rather than serving it slowly.
	 *
	 * @throws ToolCallException
	 */
	protected static function refuseUnattributablePage(bool $bAudit, int $iLimit, bool $bActions = false): void
	{
		foreach (['audit' => $bAudit, 'actions' => $bActions] as $sArgument => $bAsked) {
			if ($bAsked && $iLimit > ObjectHistory::MAX_AUDIT_PAGE) {
				throw new ToolCallException(sprintf(
					'%s is available for up to %d objects at a time; this call asks for %d. Lower limit, or drop %s and ask about the objects that matter one at a time.',
					$sArgument,
					ObjectHistory::MAX_AUDIT_PAGE,
					$iLimit,
					$sArgument
				));
			}
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected static function fieldsSchemaProperties(): array
	{
		return ['output_fields' => ObjectSerializer::FieldsSchemaProperty(ObjectSerializer::DEFAULT_LIST_FIELDS)];
	}

	/**
	 * The attributes one page reports, refusing the combination that cannot
	 * fit anywhere.
	 *
	 * A thousand objects of two attributes is a list; a thousand objects of
	 * every attribute is several megabytes of JSON, and the caller finds out
	 * by having its context window filled. The limit stays high for narrow
	 * reads and the pair is refused with something the model can act on.
	 *
	 * @return array<int, string>|null
	 *
	 * @throws ToolCallException
	 */
	protected static function fieldsForPage(string $sClass, string $sOutputFields, int $iLimit): ?array
	{
		$aFields = ObjectSerializer::ParseFieldList($sClass, $sOutputFields);

		if ($aFields === null && $iLimit > self::MAX_LIMIT_ALL_FIELDS) {
			throw new ToolCallException(sprintf(
				'Asking for every attribute of %d objects returns more than any client can read. Either name the attributes you need in output_fields, or lower limit to %d or less.',
				$iLimit,
				self::MAX_LIMIT_ALL_FIELDS
			));
		}

		return $aFields;
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Object Search',
			true,  // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}
}
