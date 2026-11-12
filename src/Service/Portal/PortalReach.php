<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use MetaModel;

/**
 * Which classes a brick's query, or a scope's, can reach.
 *
 * Reads the finalclass conditions at the top-level AND of a query and nothing
 * else, so that the answer can only ever take a class away, never offer one.
 * Pure of the portal itself: it is asked with a query and a list of classes.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalReach
{
	/**
	 * The bricks reaching any of a class's ancestors, the class included.
	 *
	 * @param array<string, array<int, string>> $aReach   As Reach() answers.
	 * @param array<int, string>                $aLineage The class and its parents.
	 *
	 * @return array<int, string>
	 */
	public static function BricksReaching(array $aReach, array $aLineage): array
	{
		$aBricks = [];
		foreach ($aLineage as $sClass) {
			foreach ($aReach[$sClass] ?? [] as $sBrick) {
				$aBricks[$sBrick] = true;
			}
		}

		return array_keys($aBricks);
	}

	/**
	 * Whether a query bounded by these finalclass conditions can return an
	 * object of any of these classes.
	 *
	 * @param array<int, string>|null $aAllowed  The classes finalclass is pinned to, or null when it is not.
	 * @param array<int, string>      $aDenied   The classes finalclass is told to differ from.
	 * @param array<int, string>      $aConcrete The concrete classes an object of the class asked about can be.
	 */
	public static function CanHold(?array $aAllowed, array $aDenied, array $aConcrete): bool
	{
		foreach ($aConcrete as $sClass) {
			if (($aAllowed === null || in_array($sClass, $aAllowed, true)) && !in_array($sClass, $aDenied, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The concrete classes an object of a class can be: itself and its
	 * subclasses, abstract ones left out.
	 *
	 * @return array<int, string>
	 */
	public static function ConcreteClasses(string $sClass): array
	{
		return array_values(array_filter(
			MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL),
			static fn (string $sChild): bool => !MetaModel::IsAbstract($sChild)
		));
	}

	/**
	 * Whether a query can return an object of any of these classes, as far
	 * as its finalclass conditions say.
	 *
	 * Read from the top-level AND of each query only, and only where the
	 * condition is the selected alias's finalclass against literal values.
	 * Anything else - a parameter, an OR, a condition written the other way
	 * round - is no conclusion, and the answer stays yes: this can only ever
	 * take a class away, never offer one.
	 *
	 * Typed object rather than DBSearch: the static analysis runs without
	 * iTop, and cannot know that the two kinds of search below are DBSearches.
	 *
	 * @param object             $oSearch   A DBSearch.
	 * @param array<int, string> $aConcrete
	 */
	public static function CanHoldAnyOf(object $oSearch, array $aConcrete): bool
	{
		if ($oSearch instanceof \DBUnionSearch) {
			foreach ($oSearch->GetSearches() as $oMember) {
				if (self::CanHoldAnyOf($oMember, $aConcrete)) {
					return true;
				}
			}

			return false;
		}

		if (!$oSearch instanceof \DBObjectSearch) {
			return true;
		}

		$aAllowed = null;
		$aDenied = [];
		foreach (self::conjuncts($oSearch->GetCriteria()) as $oCondition) {
			if (!$oCondition instanceof \BinaryExpression) {
				continue;
			}

			$oField = $oCondition->GetLeftExpr();
			if (!$oField instanceof \FieldExpression || $oField->GetName() !== 'finalclass' || $oField->GetParent() !== $oSearch->GetClassAlias()) {
				continue;
			}

			$aValues = self::literals($oCondition->GetRightExpr());
			if ($aValues === null) {
				continue;
			}

			switch (strtoupper((string)$oCondition->GetOperator())) {
				case '=':
				case 'IN':
					$aAllowed = $aAllowed === null ? $aValues : array_values(array_intersect($aAllowed, $aValues));
					break;
				case '!=':
				case 'NOT IN':
					$aDenied = array_merge($aDenied, $aValues);
					break;
			}
		}

		return self::CanHold($aAllowed, $aDenied, $aConcrete);
	}

	/**
	 * The terms of an expression's top-level AND, or the expression itself.
	 *
	 * @return array<int, object>
	 */
	private static function conjuncts(object $oExpression): array
	{
		if ($oExpression instanceof \BinaryExpression && strtoupper((string)$oExpression->GetOperator()) === 'AND') {
			return array_merge(self::conjuncts($oExpression->GetLeftExpr()), self::conjuncts($oExpression->GetRightExpr()));
		}

		return [$oExpression];
	}

	/**
	 * The literal values of a scalar or a list of scalars, or null for
	 * anything else.
	 *
	 * @return array<int, string>|null
	 */
	private static function literals(object $oExpression): ?array
	{
		$aItems = $oExpression instanceof \ListExpression ? $oExpression->GetItems() : [$oExpression];

		$aValues = [];
		foreach ($aItems as $oItem) {
			if (!$oItem instanceof \ScalarExpression) {
				return null;
			}
			$aValues[] = (string)$oItem->GetValue();
		}

		return $aValues;
	}
}
