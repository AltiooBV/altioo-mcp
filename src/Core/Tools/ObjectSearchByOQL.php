<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Core\Tools\AbstractObjectSearch;
use Mcp\Exception\ToolCallException;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

/**
 * Search iTop objects using OQL.
 *
 * Example OQL: SELECT UserRequest WHERE status = 'open'
 */
class ObjectSearchByOQL extends AbstractObjectSearch
{

	public function getTitle(): ?string
	{
		return 'Search Objects by OQL';
	}

	public function getDescription(): ?string
	{
		return 'Search iTop objects using an OQL query. Returns a list of matching objects with their attributes. Use the itop://core/classes resource to discover available classes and their attributes before building a query.';
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
			] + self::pagingSchemaProperties() + self::orderingSchemaProperties(),
			'required' => ['oql'],
		];
	}

	/**
	 * @param string $oql The OQL query to execute, e.g. 'SELECT UserRequest WHERE status = "open"'
	 * @param int $limit Maximum number of results to return (default: 50, max: 1000)
	 * @param int $offset Number of results to skip for pagination (default: 0)
	 * @param string $order_by Attribute code to sort on; '' for the order the datamodel declares
	 * @param string $order_direction 'asc' or 'desc'
	 * @return array An array containing the class, total count, limit, offset, and list of matching objects with their attributes
	 * @throws ToolCallException if the OQL query is invalid, if the class is unknown, or if access is denied.
	 */
	public static function execute(
		string $oql,
		int    $limit = self::DEFAULT_LIMIT,
		int    $offset = self::DEFAULT_OFFSET,
		string $order_by = '',
		string $order_direction = self::DEFAULT_SORT,
	): mixed {
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between " . self::MIN_LIMIT . " and " . self::MAX_LIMIT . ".");
		}
		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

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

		// Test the query before executing it to provide a more helpful error message in case of invalid filters
		try {
			new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
		} catch (\OQLException $e) {
			throw new ToolCallException("Invalid filter condition. " . $e->getMessage());
		}

		$aResults = [];
		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
		if (!UserRights::IsActionAllowed($class,  UR_ACTION_READ, $oSet)) {
			return [
				'class' => $class,
				'oql' => $oql,
				'total'   => 0,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => $aResults, // hides objects that the user shouldn't see
			];
		}

		// Execute the search and fetch results
		try {
			$aResults = [];
			while ($oObject = $oSet->Fetch()) {
				$sObjectFinalClass = MetaModel::GetFinalClassName($class, $oObject->GetKey());
				if ($sObjectFinalClass !== $class) {
					// skip if not allowed
					if (!UserRights::IsActionAllowed($sObjectFinalClass, UR_ACTION_READ)) {
						continue;
					}
					$oObjectFinal = MetaModel::GetObject($sObjectFinalClass, $oObject->GetKey());
					$sKeyFinal = MetaModel::DBGetKey($sObjectFinalClass);
					$oSearchFinal = DBObjectSearch::FromOQL("SELECT {$sObjectFinalClass} WHERE {$sKeyFinal} = {$oObjectFinal->GetKey()}");
					$oSetFinal = new DBObjectSet($oSearchFinal);
					if (!UserRights::IsActionAllowed($sObjectFinalClass,  UR_ACTION_READ, $oSetFinal)) {
						continue; // hide that the object exists
					}
					// serialize the "real object"
					$oObject = $oObjectFinal;
				}
				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass);
			}

			return [
				'class' => $class,
				'oql'       => $oql,
				'total'     => $oSet->Count(),
				'limit'     => $limit,
				'offset'    => $offset,
				'objects'   => $aResults,
			];
		} catch (\Exception $e) {
			throw new ToolCallException("Failed to execute search: " . $e->getMessage());
		}
	}
}
