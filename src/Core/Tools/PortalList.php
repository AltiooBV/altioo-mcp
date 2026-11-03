<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use DBObject;
use DBObjectSet;
use DBSearch;
use DBUnionSearch;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;

/**
 * Runs one of the lists the portal shows this user: a ManageBrick, one of
 * its tabs, or a BrowseBrick level.
 *
 * The client names the list and never sends a query
 * (doc/adr/0001-portal-mode-bricks-bound-discovery.md). The brick's own query
 * is run here, intersected with the scope of its class, as the portal runs it;
 * a tab adds the condition the brick gives it, and a search adds the portal's
 * own naive full-text match on the columns the list shows. So "what is the
 * status of my requests" is the ongoing-tickets list, and a FAQ search is the
 * FAQ level with a search - each bounded the way the portal bounds it.
 *
 * A BrowseBrick level below the first can be narrowed to one parent, which
 * must itself be one of the parent level's rows: a path is checked level by
 * level, so a parent the user could not have clicked on lists nothing.
 *
 * @since 1.2.0
 */
class PortalList extends AbstractPortalTool
{
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 100;

	protected function defaultTitle(): string
	{
		return 'List from the Portal';
	}

	public function getDescription(): ?string
	{
		return 'Run one of the lists this user\'s portal shows them, as the portal runs it: the brick\'s own query, limited to what the portal\'s scopes let them read. '
			.'Call core_class_list for the lists this portal has - their ids, titles, classes and tabs differ from one portal to another. A list with tabs can be narrowed to one; without one it covers every tab. A list with a parent is a browse level: parent_id narrows it to the children of one row of its parent list, which is how a hierarchy (a service catalogue, FAQ categories) is walked down. '
			.'search matches words in the columns the list shows, all of them required; quote a phrase to match it whole. '
			.'Each row carries its class and id, for core_object_get.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'List from the portal',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'list'      => [
					'type'        => 'string',
					'description' => 'A list id from core_class_list (in iTop\'s stock portal, e.g. ongoing-tickets-for-portal-user).',
				],
				'tab'       => [
					'type'        => 'string',
					'description' => 'One of the list\'s tabs, by id. Empty (the default) covers every tab.',
					'default'     => '',
				],
				'parent_id' => [
					'type'        => 'integer',
					'description' => 'For a browse level below the first: the id of the row above it to list the children of. 0 (the default) lists the whole level.',
					'default'     => 0,
				],
				'search'    => [
					'type'        => 'string',
					'description' => 'Words to look for in the columns the list shows. Empty (the default) lists everything.',
					'default'     => '',
				],
				'limit'     => [
					'type'        => 'integer',
					'description' => 'Maximum number of rows to return.',
					'default'     => self::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
				],
				'offset'    => [
					'type'        => 'integer',
					'description' => 'Number of rows to skip; pass back the next_offset of the previous page.',
					'default'     => 0,
					'minimum'     => 0,
				],
			],
			'required'   => ['list'],
		];
	}

	/**
	 * @param string $list      A list id from core_class_list
	 * @param string $tab       One of its tabs, or '' for all of them
	 * @param int    $parent_id For a browse level, the row above to list the children of; 0 for the whole level
	 * @param string $search    Words to look for in the list's columns
	 * @param int    $limit     Maximum number of rows
	 * @param int    $offset    Rows to skip
	 *
	 * @return mixed The list, the tab, the rows and whether there are more
	 * @throws ToolCallException if the list is not one the portal offers, or the portal cannot be read
	 */
	public static function execute(string $list, string $tab = '', int $parent_id = 0, string $search = '', int $limit = self::DEFAULT_LIMIT, int $offset = 0): mixed
	{
		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new ToolCallException('Invalid limit. Please specify a limit between 1 and '.self::MAX_LIMIT.'.');
		}
		if ($offset < 0) {
			throw new ToolCallException('Invalid offset. Please specify a non-negative offset.');
		}

		$oPolicy = self::policy();

		$aList = $oPolicy->ListDefinition(trim($list));
		if ($aList === null || $oPolicy->ReadSet((string)$aList['class']) === null) {
			// Not quoted back: a list id is the client's text, and an unknown
			// one and one the scopes empty answer alike.
			throw new ToolCallException('The portal offers no such list. Call core_class_list for the lists it offers.');
		}

		$sClass = (string)$aList['class'];
		self::refuseReserved($sClass);

		try {
			$oQuery = self::listQuery($oPolicy, $aList, trim($tab), $parent_id);
			if ($oQuery === null) {
				return ToolOutput::Json(self::answer($list, $tab, $parent_id, $sClass, 0, $limit, $offset, []));
			}

			self::addSearch($oQuery, $sClass, $aList['columns'], $search);

			$oSet = new DBObjectSet($oQuery, self::order($aList, $sClass), [], null, $limit, $offset);
			$aRows = [];
			while ($oObject = $oSet->Fetch()) {
				$aRows[] = self::serialized($oObject, $aList['columns']);
			}

			return ToolOutput::Json(ObjectSerializer::MarkRows(self::answer($list, $tab, $parent_id, $sClass, $oSet->Count(), $limit, $offset, $aRows), $aRows, $sClass)
				+ self::tabsOf($oPolicy, $aList, $sClass));
		} catch (ToolCallException $oException) {
			throw $oException;
		} catch (\Throwable $oException) {
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to run the portal list', $oException));
		}
	}

	/**
	 * One row of a list, when the list holds it for this user: the object a
	 * create action starts from has to be one the user could have picked.
	 */
	public static function RowOf(PortalPolicy $oPolicy, string $sList, int $iId): ?DBObject
	{
		$aList = $oPolicy->ListDefinition($sList);
		if ($aList === null || $iId < 1) {
			return null;
		}

		$oQuery = self::listQuery($oPolicy, $aList, '', 0);
		if ($oQuery === null) {
			return null;
		}
		$oQuery->AddCondition('id', $iId, '=');

		$oObject = (new DBObjectSet($oQuery))->Fetch();

		return $oObject instanceof DBObject ? $oObject : null;
	}

	/**
	 * The list's query, bounded by the scope, the tab and the parent; null
	 * when it can hold nothing.
	 *
	 * @param array<string, mixed> $aList As PortalPolicy::ListDefinition() answers.
	 */
	private static function listQuery(PortalPolicy $oPolicy, array $aList, string $sTab, int $iParent): ?DBSearch
	{
		$sClass = (string)$aList['class'];
		$oQuery = DBSearch::FromOQL((string)$aList['oql']);

		if ($sTab !== '') {
			$oQuery = self::tabQuery($oQuery, $aList, $sTab);
		} elseif ($aList['tabs'] !== []) {
			// Every tab, as the portal counts its total: the union of their
			// conditions, which may be narrower than the brick's query.
			$aTabs = array_map(static fn (string $sOql): DBSearch => DBSearch::FromOQL($sOql), array_values($aList['tabs']));
			$oQuery = $oQuery->Intersect(count($aTabs) === 1 ? $aTabs[0] : new DBUnionSearch($aTabs));
		}

		if ($iParent > 0) {
			$oQuery = self::childrenOf($oPolicy, $oQuery, $aList, $iParent);
			if ($oQuery === null) {
				return null;
			}
		}

		return $oPolicy->Bounded($oQuery, $sClass);
	}

	/**
	 * A ManageBrick tab: one of its groups, by id, or a value of the
	 * attribute its tabs are made of.
	 *
	 * @param array<string, mixed> $aList
	 */
	private static function tabQuery(DBSearch $oQuery, array $aList, string $sTab): DBSearch
	{
		if (isset($aList['tabs'][$sTab])) {
			return $oQuery->Intersect(DBSearch::FromOQL((string)$aList['tabs'][$sTab]));
		}

		$sAttCode = $aList['tabs_by'];
		if (is_string($sAttCode) && $sAttCode !== '' && MetaModel::IsValidAttCode($oQuery->GetClass(), $sAttCode)) {
			// A value, bound as one: AddCondition() never parses it as OQL.
			$oQuery->AddCondition($sAttCode, $sTab, '=');

			return $oQuery;
		}

		throw new ToolCallException('This list has no such tab. Call core_class_list for its tabs.');
	}

	/**
	 * A browse level narrowed to the children of one row of the level above,
	 * when that row is one the level above lists for this user; null when it
	 * is not.
	 *
	 * @param array<string, mixed> $aList
	 */
	private static function childrenOf(PortalPolicy $oPolicy, DBSearch $oQuery, array $aList, int $iParent): ?DBSearch
	{
		$sParentList = $aList['parent'];
		$sParentAtt = $aList['parent_att'];
		if (!is_string($sParentList) || !is_string($sParentAtt) || $sParentAtt === '') {
			throw new ToolCallException('parent_id narrows a browse level below the first, and this list is not one.');
		}

		$aParent = $oPolicy->ListDefinition($sParentList);
		if ($aParent === null) {
			return null;
		}

		// The parent is checked against its own level, which is in turn
		// bounded by its scope: the path is checked one level at a time.
		$oParents = self::listQuery($oPolicy, $aParent, '', 0);
		if ($oParents === null) {
			return null;
		}
		$oParents->AddCondition('id', $iParent, '=');
		if ((new DBObjectSet($oParents))->Count() === 0) {
			return null;
		}

		$oQuery->AddCondition($sParentAtt, $iParent, '=');

		return $oQuery;
	}

	/**
	 * The portal's search, on the same terms as its ManageBrick's: each word
	 * required, a quoted phrase matched whole, over the columns the list
	 * shows - an external key through the name of what it points at.
	 *
	 * @param array<int, string> $aColumns
	 */
	private static function addSearch(DBSearch $oQuery, string $sClass, array $aColumns, string $sSearch): void
	{
		$sSearch = trim($sSearch);
		if ($sSearch === '') {
			return;
		}

		$aAttCodes = [];
		foreach (array_merge(['friendlyname'], $aColumns) as $sAttCode) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				continue;
			}
			$aAttCodes[] = MetaModel::GetAttributeDef($sClass, $sAttCode)->IsExternalKey() ? $sAttCode.'_friendlyname' : $sAttCode;
		}
		$aAttCodes = array_values(array_unique(array_filter(
			$aAttCodes,
			static fn (string $sAttCode): bool => MetaModel::IsValidAttCode($sClass, $sAttCode)
		)));
		if ($aAttCodes === []) {
			throw new ToolCallException('This list shows no column a search can look in.');
		}

		$aNeedles = preg_match('/^"(.*)"$/', $sSearch, $aMatches) === 1
			? [$aMatches[1]]
			: array_values(array_filter(explode(' ', $sSearch), static fn (string $s): bool => $s !== ''));
		foreach ($aNeedles as $sNeedle) {
			$oQuery->AddCondition_FullTextOnAttributes($aAttCodes, $sNeedle);
		}
	}

	/**
	 * Newest first for a ManageBrick, which lists what the user filed; a
	 * browse level by what it shows first, its name. The id breaks ties so
	 * that paging is stable.
	 *
	 * @param array<string, mixed> $aList
	 *
	 * @return array<string, bool>
	 */
	private static function order(array $aList, string $sClass): array
	{
		if ($aList['kind'] === PortalPolicy::BRICK_BROWSE) {
			$sFirst = $aList['columns'][0] ?? null;
			if (is_string($sFirst) && MetaModel::IsValidAttCode($sClass, $sFirst)) {
				return [$sFirst => true, 'id' => true];
			}
		}

		return ['id' => false];
	}

	/**
	 * The tabs of a ManageBrick list made of groups, each with how many rows
	 * it holds for this user, as the portal counts them on its tab headers.
	 *
	 * @param array<string, mixed> $aList
	 *
	 * @return array<string, mixed>
	 */
	private static function tabsOf(PortalPolicy $oPolicy, array $aList, string $sClass): array
	{
		if ($aList['tabs'] === []) {
			return [];
		}

		/** @var array<string, string> $aConditions */
		$aConditions = $aList['tabs'];

		$aTabs = [];
		foreach (array_map('strval', array_keys($aConditions)) as $sTab) {
			$oTab = $oPolicy->Bounded(self::tabQuery(DBSearch::FromOQL((string)$aList['oql']), $aList, $sTab), $sClass);
			$aTabs[] = ['tab' => $sTab, 'count' => $oTab === null ? 0 : (new DBObjectSet($oTab))->Count()];
		}

		return ['tabs' => $aTabs];
	}

	/**
	 * @param array<int, array<string, mixed>> $aRows
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(string $sList, string $sTab, int $iParent, string $sClass, int $iTotal, int $iLimit, int $iOffset, array $aRows): array
	{
		$iNext = $iOffset + $iLimit;

		return [
			'list'        => $sList,
			'tab'         => $sTab === '' ? null : $sTab,
			'parent_id'   => $iParent > 0 ? $iParent : null,
			'class'       => $sClass,
			'total'       => $iTotal,
			'objects'     => $aRows,
			'has_more'    => $iNext < $iTotal,
			'next_offset' => $iNext < $iTotal ? $iNext : null,
		];
	}
}
