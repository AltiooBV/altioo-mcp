<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
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
					'description'          => 'The OQL query to execute. OQL has no ORDER BY clause; sort with order_by instead.',
				],
			] + self::fieldsSchemaProperties() + self::pagingSchemaProperties() + self::orderingSchemaProperties() + self::auditSchemaProperties(),
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
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between ".self::MIN_LIMIT." and ".self::MAX_LIMIT.".");
		}
		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

		self::refuseUnattributablePage($audit, $limit);

		require_once(APPROOT.'core/oql/check_oql.php');
		$aCheck = CheckOQL($oql, new \ModelReflectionRuntime());
		if ($aCheck['status'] === 'error') {
			throw new ToolCallException("Invalid OQL query. Reason: {$aCheck['message']}");
		}

		$oSearch = DBObjectSearch::FromOQL($oql);
		$class  = $oSearch->GetClass();

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_BULK_READ)) {
			throw new ToolCallException("Bulk read access denied to class '{$class}'.");
		}

		$aOrderBy = self::orderBy($class, $order_by, $order_direction);
		$aFields = self::fieldsForPage($class, $output_fields, $limit);

		$aResults = [];
		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
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
			$aResults = [];
			while ($oObject = $oSet->Fetch()) {
				$sObjectFinalClass = self::finalClassIfReadable($oObject, $class);
				if ($sObjectFinalClass === null) {
					continue;
				}

				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass, $aFields, $audit);
			}

			$iTotal = $oSet->Count();

			return ToolOutput::Json([
				'class' => $class,
				'oql'       => $oql,
				'total'     => $iTotal,
				'limit'     => $limit,
				'offset'    => $offset,
				'objects'   => $aResults,
			] + self::pagingFooter($iTotal, $limit, $offset));
		} catch (\OQLException $e) {
			// Building a DBObjectSet runs no query - it assigns and returns -
			// so a malformed filter can only surface here, where the set is
			// first read. Probing for it beforehand caught nothing.
			throw new ToolCallException("Invalid filter condition. ".$e->getMessage());
		} catch (\Throwable $e) {
			// The OQLException above is the one a caller can act on, and it
			// keeps its message. Anything else came out of the query layer.
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to execute the search', $e));
		}
	}
}
