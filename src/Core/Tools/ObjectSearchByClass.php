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
use MetaModel;
use UserRights;

/**
 * Search iTop objects by class name with optional field filters.
 *
 * Simpler alternative to core_object_search_by_oql when you do not need full OQL.
 * Filters are combined with AND.
 *
 * Example:
 *   class: "UserRequest"
 *   filters: { "status": "open", "agent_id": 12 }
 *
 * @since 1.0.0
 */
class ObjectSearchByClass extends AbstractObjectSearch
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
		return 'Search Objects by Class';
	}

	public function getDescription(): ?string
	{
		return 'Search iTop objects by class name with optional attribute filters (combined with AND). Simpler alternative to core_object_search_by_oql when you do not need full OQL. Call core_class_schema for the attribute codes you can filter on.';
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name.',
				],
				'filters' => [
					'type'                 => 'object',
					'description'          => 'Key/value pairs to filter results (combined with AND). Keys are attribute codes. Call core_class_schema for the attribute codes of the class, their types and which ones are mandatory.',
					'additionalProperties' => true,
				],
			] + self::fieldsSchemaProperties() + self::pagingSchemaProperties() + self::orderingSchemaProperties(),
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class The iTop class name to search for
	 * @param array  $filters Optional key/value pairs to filter results (combined with AND)
	 * @param int    $limit Maximum number of results to return (default: 50, max: 1000)
	 * @param int    $offset Number of results to skip for pagination (default: 0)
	 * @param string $order_by Attribute code to sort on; '' for the order the datamodel declares
	 * @param string $order_direction 'asc' or 'desc'
	 * @param string $output_fields Comma-separated attribute codes to return, or '*' for all of them
	 * @return array An array containing the class, filters, total count, limit, offset, and list of matching objects with their attributes
	 * @throws ToolCallException if the class is unknown or access is denied.
	 */
	public static function execute(
		string $class,
		array  $filters = [],
		int    $limit = self::DEFAULT_LIMIT,
		int    $offset = self::DEFAULT_OFFSET,
		string $order_by = '',
		string $order_direction = self::DEFAULT_SORT,
		string $output_fields = ObjectSerializer::DEFAULT_LIST_FIELDS,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between ".self::MIN_LIMIT." and ".self::MAX_LIMIT.".");
		}

		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_BULK_READ)) {
			throw new ToolCallException("Bulk read access denied to class '{$class}'.");
		}

		$oSearch = DBObjectSearch::FromOQL("SELECT {$class}");

		foreach ($filters as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
				throw new ToolCallException("Unknown attribute '{$sAttCode}' on class '{$class}'.");
			}
			$oSearch->AddCondition($sAttCode, $value, '=');
		}

		$aOrderBy = self::orderBy($class, $order_by, $order_direction);
		$aFields = self::fieldsForPage($class, $output_fields, $limit);

		$aResults = [];
		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
		if (!UserRights::IsActionAllowed($class,  UR_ACTION_READ, $oSet)) {
			return ToolOutput::Json([
				'requested_class'  => $class,
				'class' => $class,
				'filters' => $filters,
				'total'   => 0,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => $aResults, // hides objects that the user shouldn't see
			] + self::pagingFooter(0, $limit, $offset));
		}
		$sSetClass = $oSet->GetClass();
		if ($sSetClass !== $class) {
			// Hides that the objects might exist, as not allowed to access it
			if (!UserRights::IsActionAllowed($sSetClass, UR_ACTION_READ, $oSet)) {
				return ToolOutput::Json([
					'requested_class'  => $class,
					'class' => $class,
					'filters' => $filters,
					'total'   => 0,
					'limit'   => $limit,
					'offset'  => $offset,
					'objects' => $aResults, // hides objects that the user shouldn't see
				] + self::pagingFooter(0, $limit, $offset));
			}

			if (!UserRights::IsActionAllowed($sSetClass, UR_ACTION_BULK_READ, $oSet)) {
				return ToolOutput::Json([
					'requested_class'  => $class,
					'class' => $sSetClass,
					'filters' => $filters,
					'total'   => 0,
					'limit'   => $limit,
					'offset'  => $offset,
					'objects' => $aResults, // hides objects that the user shouldn't see
				] + self::pagingFooter(0, $limit, $offset));
			}
		}

		// Execute the search and fetch results
		try {
			while ($oObject = $oSet->Fetch()) {
				$sObjectFinalClass = self::finalClassIfReadable($oObject, $sSetClass);
				if ($sObjectFinalClass === null) {
					continue;
				}

				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass, $aFields);
			}

			$iTotal = $oSet->Count();

			return ToolOutput::Json([
				'requested_class'  => $class,
				'class' => $sSetClass,
				'filters' => $filters,
				'total'   => $iTotal,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => $aResults,
			] + self::pagingFooter($iTotal, $limit, $offset));
		} catch (\OQLException $e) {
			// Building a DBObjectSet runs no query - it assigns and returns -
			// so a bad filter value can only surface here, where the set is
			// first read. Probing for it beforehand caught nothing.
			throw new ToolCallException("Invalid filter condition. ".$e->getMessage());
		} catch (\Throwable $e) {
			// The OQLException above is the one a caller can act on, and it
			// keeps its message. Anything else came out of the query layer.
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to execute the search', $e));
		}
	}
}
