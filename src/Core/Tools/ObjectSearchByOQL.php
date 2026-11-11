<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Mcp\Exception\ToolCallException;
use DBObjectSearch;
use DBObjectSet;
use UserRights;

/**
 * Search iTop objects using OQL.
 *
 * Example OQL: SELECT UserRequest WHERE status = 'open'
 *
 * @since 1.0.0
 */
class ObjectSearchByOQL extends AbstractObjectSearch
{
	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves. */
	public function getToolset(): string
	{
		return 'objects';
	}

	protected function defaultTitle(): string
	{
		return 'Search Objects by OQL';
	}

	public function getDescription(): ?string
	{
		return 'Search iTop objects using an OQL query. Returns a list of matching objects with their attributes. Call core_class_list for the class names and core_class_schema for the attribute codes before building a query.';
	}


	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'oql' => [
					'type'                 => 'string',
					'description'          => 'The OQL query to execute. OQL has no ORDER BY clause; sort with order_by instead. '
						.'SELECT on a parent class covers its children - SELECT Ticket answers across UserRequest, Incident and any other subclass - and each row reports the class it actually is.',
				],
			] + self::fieldsSchemaProperties() + self::pagingSchemaProperties() + self::orderingSchemaProperties() + self::archivedSchemaProperty() + self::auditSchemaProperties() + self::actionsSchemaProperties(),
			'required' => ['oql'],
		];
	}

	/**
	 * @param string $oql The OQL query to execute, e.g. 'SELECT UserRequest WHERE status = "open"'
	 * @param int $limit Maximum number of results to return (default: 50, max: 1000)
	 * @param int $offset Number of results to skip for pagination (default: 0)
	 * @param string $order_by Attribute code to sort on; '' for the order the datamodel declares
	 * @param string $order_direction 'asc' or 'desc'
	 * @param string $output_fields Comma-separated attribute codes to return, or '*' for all of them
	 * @param bool $audit Also report when each object was created and last changed, and by whom; limited to a page of ObjectHistory::MAX_AUDIT_PAGE objects
	 * @param bool $actions Also report, per object, the write gates answered for that object and the stimuli its state accepts; same page limit
	 * @param string $archived 'exclude' (default), 'include' or 'only'
	 * @return array An array containing the class, total count, limit, offset, and list of matching objects with their attributes
	 * @throws ToolCallException if the OQL query is invalid, if the class is unknown, or if access is denied.
	 */
	public static function execute(
		string $oql,
		int    $limit = self::DEFAULT_LIMIT,
		int    $offset = self::DEFAULT_OFFSET,
		string $order_by = '',
		string $order_direction = self::DEFAULT_SORT,
		string $output_fields = ObjectSerializer::DEFAULT_LIST_FIELDS,
		bool   $audit = false,
		bool   $actions = false,
		string $archived = self::DEFAULT_ARCHIVED,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between ".self::MIN_LIMIT." and ".self::MAX_LIMIT.".");
		}
		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

		ObjectQuery::CheckQuery($oql);
		$sUnproved = ExclusionPolicy::OqlRefusal($oql);
		if ($sUnproved !== null) {
			throw new ToolCallException($sUnproved);
		}

		$oSearch = DBObjectSearch::FromOQL($oql);
		$class  = $oSearch->GetClass();
		self::applyArchived($oSearch, $class, $archived);

		// The class comes from the query rather than from an argument, which is
		// exactly why this one matters: "SELECT CMDBChangeOpSetAttributeScalar"
		// is the shortest way round every gate core_object_history applies.
		if (ChangeLogReservation::IsReserved($class)) {
			throw new ToolCallException(sprintf(ChangeLogReservation::RESERVED_REFUSAL, $class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(ClassRefusal::Unreadable($class)); // exists, but not for this account to read
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_BULK_READ)) {
			throw new ToolCallException("Bulk read access denied to class '{$class}'.");
		}

		$aOrderBy = self::orderBy($class, $order_by, $order_direction);
		$aFields = self::fieldsForPage($class, $output_fields, $limit);

		$aResults = [];
		$oSet = new DBObjectSet(ExclusionPolicy::Narrowed($oSearch, $class), $aOrderBy, [], null, $limit, $offset);
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ, $oSet)) {
			return ToolOutput::Json([
				'class' => $class,
				'oql' => $oql,
				'total'   => 0,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => $aResults, // hides objects that the user shouldn't see
			] + self::pagingFooter(0, $limit, $offset));
		}

		// Execute the search and fetch results
		try {
			// Measured on the page this call returns, not on the limit it
			// allows: the cost is a question per object served, and four
			// matches under the default limit of 50 are four questions.
			self::refuseUnattributablePage($audit, self::pageSize($oSet->Count(), $limit, $offset), $actions);

			$aResults = [];
			while ($oObject = $oSet->Fetch()) {
				$sObjectFinalClass = self::finalClassIfReadable($oObject, $class);
				if ($sObjectFinalClass === null) {
					continue;
				}

				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass, $aFields, $audit, $actions);
			}

			$iTotal = $oSet->Count();

			return ToolOutput::Json(ObjectSerializer::MarkRows([
				'class' => $class,
				'oql'       => $oql,
				'total'     => $iTotal,
				'limit'     => $limit,
				'offset'    => $offset,
				'objects'   => $aResults,
			] + self::pagingFooter($iTotal, $limit, $offset), $aResults, $class));
		} catch (ToolCallException $e) {
			// The refusal above, which the catch-all below would make opaque.
			throw $e;
		} catch (\OQLException $e) {
			// Building a DBObjectSet runs no query - it assigns and returns -
			// so a malformed filter can only surface here, where the set is
			// first read. Probing for it beforehand caught nothing.
			throw new ToolCallException("Invalid filter condition. ".$e->getMessage());
		} catch (\Throwable $e) {
			// The OQLException above is the one a caller can act on, and it
			// keeps its message. Anything else came out of the query layer.
			throw new ToolCallException(ErrorReport::OpaqueFailure('Failed to execute the search', $e));
		}
	}
}
