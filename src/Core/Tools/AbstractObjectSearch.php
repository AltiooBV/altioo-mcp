<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use DBObject;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * What the two search tools share: paging, ordering and serialization.
 */
abstract class AbstractObjectSearch extends AbstractMCPTool
{
	const MIN_LIMIT = 1;
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT = 1000;

	const MIN_OFFSET = 0;
	const DEFAULT_OFFSET = 0;

	const SORT_ASC = 'asc';
	const SORT_DESC = 'desc';
	const DEFAULT_SORT = self::SORT_ASC;

	/** The one attribute every class has, and the only unique one. */
	const TIEBREAK_ATTRIBUTE = 'id';


	public function getNamespace(): string
	{
		return 'core';
	}

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
				'description' => 'Number of objects to skip. Paging is stable: results are ordered by the requested attribute and then by id, so no object is returned twice or skipped between pages.',
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
			if ($sOrderBy !== self::TIEBREAK_ATTRIBUTE && !UserRights::IsActionAllowedOnAttribute($sClass, $sOrderBy, UR_ACTION_READ)) {
				// Ordering by an attribute reports on its values, so it takes
				// the same right as reading it.
				throw new ToolCallException("Unknown attribute '{$sOrderBy}' on class '{$sClass}', cannot sort on it.");
			}

			$aOrderBy = [$sOrderBy => $bAscending];
		}

		$aOrderBy[self::TIEBREAK_ATTRIBUTE] ??= $bAscending;

		return $aOrderBy;
	}

	protected static function serializeObject(DBObject $oObject, string $sClass): array
	{
		return ObjectSerializer::Serialize($oObject, $sClass);
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
