<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
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
			] + self::fieldsSchemaProperties() + self::pagingSchemaProperties() + self::orderingSchemaProperties() + self::auditSchemaProperties() + self::actionsSchemaProperties(),
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
	 * @param bool $audit Also report when each object was created and last changed, and by whom; limited to a page of ObjectHistory::MAX_AUDIT_PAGE objects
	 * @param bool $actions Also report, per object, the write gates answered for that object and the stimuli its state accepts; same page limit
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
		bool   $audit = false,
		bool   $actions = false,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between ".self::MIN_LIMIT." and ".self::MAX_LIMIT.".");
		}

		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

		self::refuseUnattributablePage($audit, $limit, $actions);

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
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
			$sRefusal = self::refusalForValue($class, $sAttCode, $value);
			if ($sRefusal !== null) {
				throw new ToolCallException($sRefusal);
			}
			$oSearch->AddCondition($sAttCode, $value, '=');
		}

		$aOrderBy = self::orderBy($class, $order_by, $order_direction);
		$aFields = self::fieldsForPage($class, $output_fields, $limit);

		$aResults = [];
		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ, $oSet)) {
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

				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass, $aFields, $audit, $actions);
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

	/**
	 * Why a filter value cannot match anything, when that is knowable.
	 *
	 * An unknown attribute code has always been refused by name. An unknown
	 * *value* for a known code was not: the condition was added, the query ran,
	 * and the answer came back total: 0 - which reads as "no ticket is in that
	 * state" and is passed on to a user as fact. Typing "bogus_status" and
	 * typing the code of a state nobody is in produced the same answer, and
	 * only one of them is an answer.
	 *
	 * Refused rather than warned about, because it is the same mistake as the
	 * unknown attribute one line above and deserves the same treatment: a
	 * refusal naming what is valid costs one round trip, a wrong empty result
	 * costs the conclusion drawn from it.
	 *
	 * Only where the datamodel declares a bounded enumeration. An external key
	 * is skipped deliberately - GetAllowedValues() on one runs a query over the
	 * whole target table, which is the cost core_class_schema already refuses
	 * to pay - and so is anything longer than that schema reports, on the same
	 * reasoning. A value the ORM will reject for its own reasons (a malformed
	 * date, say) still reaches the ORM, which says so better than this could.
	 *
	 * @param mixed $value As the caller sent it.
	 */
	private static function refusalForValue(string $sClass, string $sAttCode, mixed $value): ?string
	{
		if (!is_scalar($value)) {
			// A list or a map is an IN or a sub-query as far as the ORM is
			// concerned; not this method's question.
			return null;
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if ($oAttDef->IsExternalKey()) {
			return null;
		}

		$aValues = $oAttDef->GetAllowedValues();
		if (!is_array($aValues) || $aValues === [] || count($aValues) > DatamodelReader::MAX_ALLOWED_VALUES) {
			return null;
		}

		if (array_key_exists((string)$value, $aValues)) {
			return null;
		}

		return sprintf(
			"Invalid value '%s' for attribute '%s' on class '%s'. Allowed: %s.",
			(string)$value,
			$sAttCode,
			$sClass,
			implode(', ', array_map('strval', array_keys($aValues)))
		);
	}
}
