<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use DBObject;
use DBObjectSet;
use DBSearch;
use DBUnionSearch;
use Mcp\Exception\ToolCallException;
use MetaModel;

/**
 * The rows of a portal list, bounded by the portal's scope, the tab and the
 * parent: what core_portal_list reads, and what {@see PortalAccess} asks when a
 * create action starts from a row of a list.
 *
 * Moved out of the core_portal_list tool by ADR 0015, because the access layer
 * needs it and nothing below Core may import a tool. The tool calls
 * {@see self::ListQuery()} in its own source, as it did before.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalRows
{
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

		$oQuery = self::ListQuery($oPolicy, $aList, '', 0);
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
	 * @psalm-suppress InvalidReturnType DBObjectSearch is a DBSearch; Psalm has no iTop checkout to say so.
	 */
	public static function ListQuery(PortalPolicy $oPolicy, array $aList, string $sTab, int $iParent): ?DBSearch
	{
		$sClass = (string)$aList['class'];
		$oQuery = DBSearch::FromOQL((string)$aList['oql']);

		if ($sTab !== '') {
			$oQuery = self::TabQuery($oQuery, $aList, $sTab);
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

		$oBounded = $oPolicy->Bounded($oQuery, $sClass);

		// A list on a parent class holds the rows of its subclasses: the excluded ones are not among them (doc/adr/0012).
		/** @psalm-suppress InvalidReturnStatement DBObjectSearch is a DBSearch; Psalm has no iTop checkout to say so. */
		return $oBounded === null ? null : ExclusionPolicy::Narrowed($oBounded, $sClass);
	}

	/**
	 * A ManageBrick tab: one of its groups, by id, or a value of the
	 * attribute its tabs are made of.
	 *
	 * @param array<string, mixed> $aList
	 */
	public static function TabQuery(DBSearch $oQuery, array $aList, string $sTab): DBSearch
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
		$oParents = self::ListQuery($oPolicy, $aParent, '', 0);
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
}
