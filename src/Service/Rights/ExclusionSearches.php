<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use MetaModel;
use Throwable;

/**
 * What the exclusions do to a search (doc/adr/0012): the subclasses and the files filed under an
 * excluded class that a search leaves out, the full-text condition without excluded attributes,
 * the relation queries that lead to an excluded class, and the OQL that cannot be checked.
 *
 * Every method asks {@see ExclusionPolicy} what is excluded and decides nothing about it. It was
 * the search half of `ExclusionPolicy` in 1.x.
 *
 * @api
 * @since 2.0.0
 */
final class ExclusionSearches
{
	/**
	 * The classes below $sClass that are excluded, with everything under them,
	 * for the searches that return rows of a parent class: a search on Contact
	 * returns every Person, and an excluded Person must not be among them -
	 * nor be counted.
	 *
	 * @return array<int, string>
	 */
	public static function ExcludedBelow(string $sClass): array
	{
		$aState = ExclusionPolicy::state();
		if ($aState['classes'] === []) {
			return [];
		}

		$aBelow = [];
		try {
			foreach ($aState['classes'] as $sExcluded) {
				$sExcluded = ExclusionSettings::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				if (strcasecmp($sExcluded, $sClass) === 0 || !MetaModel::IsValidClass($sExcluded)) {
					continue;
				}
				if (!in_array(strtolower($sClass), array_map('strtolower', ExclusionPolicy::lineage($sExcluded)), true)) {
					continue;
				}
				$aBelow[] = $sExcluded;
				foreach (MetaModel::EnumChildClasses($sExcluded, ENUM_CHILD_CLASSES_ALL) as $sChild) {
					$aBelow[] = (string)$sChild;
				}
			}
		} catch (Throwable $e) {
			return [];
		}

		return array_values(array_unique($aBelow));
	}

	/**
	 * iTop's full-text condition, over every attribute it would search but the
	 * ones the operator excluded: a word found only in an excluded attribute
	 * matches nothing, as it would if the attribute did not exist.
	 */
	public static function AddFullText(\DBObjectSearch $oSearch, string $sNeedle): void
	{
		$sClass = $oSearch->GetClass();
		$aCodes = [];
		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if (!$oAttDef->IsScalar() || $oAttDef->IsExternalKey() || !$oAttDef->IsSearchable()
				|| ExclusionPolicy::ExcludesAttribute($sClass, (string)$sAttCode)) {
				continue;
			}
			$aCodes[] = (string)$sAttCode;
		}

		$oSearch->AddCondition_FullTextOnAttributes($aCodes, $sNeedle);
	}

	/**
	 * The relation queries a class has for a relation and a direction, less those that lead to an excluded class.
	 *
	 * What iTop calls a relation query is keyed by the class that defines it and the neighbour it leads to
	 * (`Server_hypervisor`), and selects a target class (`SELECT Hypervisor AS o ...`): the key is no class name, so the
	 * check is on what the query selects and on the class that defines it. A query whose target cannot be read is left out
	 * while anything is excluded, rather than served unchecked.
	 *
	 * @return array<string, array<string, mixed>> Query name => what iTop knows of it.
	 */
	public static function RelationQueries(string $sClass, string $sRelation, bool $bDown = true): array
	{
		/** @var array<string, array<string, mixed>> $aQueries */
		$aQueries = MetaModel::EnumRelationQueries($sClass, $sRelation, $bDown);

		return ExclusionPolicy::IsActive() ? self::FilterRelationQueries($aQueries, $bDown) : $aQueries;
	}

	/**
	 * The relation queries that do not lead to an excluded class, from what iTop knows of each.
	 *
	 * Public for the tests, which feed it queries a datamodel may declare in any form: no promise to a tool pack, whatever
	 * the class's own tag says. {@see RelationQueries()} is the method to call.
	 *
	 * @internal
	 *
	 * @param array<string, array<string, mixed>> $aQueries Query name => what iTop knows of it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function FilterRelationQueries(array $aQueries, bool $bDown = true): array
	{
		$sKey = $bDown ? 'sQueryDown' : 'sQueryUp';
		foreach ($aQueries as $sName => $aInfo) {
			$sTarget = isset($aInfo[$sKey]) && is_string($aInfo[$sKey]) ? self::targetOf($aInfo[$sKey]) : '';
			$sDefinedIn = isset($aInfo['sDefinedInClass']) && is_string($aInfo['sDefinedInClass']) ? $aInfo['sDefinedInClass'] : '';

			if ($sTarget === '' || ExclusionPolicy::ExcludesClass($sTarget) || ($sDefinedIn !== '' && ExclusionPolicy::ExcludesClass($sDefinedIn))) {
				unset($aQueries[$sName]);
			}
		}

		return $aQueries;
	}

	/**
	 * The class a relation query selects, as iTop's own parser reads it: in whatever form the query is written
	 * (`SELECT X AS x`, `SELECT x FROM X AS x`, a join), with its `:this->id` placeholders left unbound. Empty when the query
	 * cannot be parsed or selects no class the datamodel has, which the caller treats as unreadable.
	 */
	private static function targetOf(string $sOql): string
	{
		try {
			$sClass = (string)\DBObjectSearch::FromOQL($sOql)->GetClass();
		} catch (Throwable $e) {
			return '';
		}

		return MetaModel::IsValidClass($sClass) ? $sClass : '';
	}

	/**
	 * Whether the object is a file filed under an object of an excluded class:
	 * an Attachment names its host by class and id, not by a key that
	 * {@see ExclusionPolicy::ExcludesAttribute()} could follow.
	 */
	public static function IsFiledUnderExcluded(\DBObject $oObject): bool
	{
		if (!ExclusionPolicy::IsActive() || !is_a($oObject, 'Attachment')) {
			return false;
		}

		try {
			return ExclusionPolicy::ExcludesClass((string)$oObject->Get('item_class'));
		} catch (Throwable $e) {
			return true;
		}
	}

	/**
	 * The search without the rows of an excluded subclass.
	 *
	 * @psalm-suppress InvalidReturnType
	 * @psalm-suppress InvalidReturnStatement
	 */
	public static function Narrowed(\DBSearch|\DBObjectSearch $oSearch, string $sClass): \DBSearch|\DBObjectSearch
	{
		// A search over attachments leaves out those filed under an excluded class.
		if (ExclusionPolicy::IsActive() && class_exists('MetaModel') && MetaModel::IsValidClass('Attachment')
			&& in_array('attachment', array_map('strtolower', ExclusionPolicy::lineage($sClass)), true)) {
			$aHosts = [];
			foreach (ExclusionPolicy::state()['classes'] as $sExcluded) {
				$sExcluded = ExclusionSettings::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				$aHosts[] = $sExcluded;
				foreach (MetaModel::EnumChildClasses($sExcluded, ENUM_CHILD_CLASSES_ALL) as $sChild) {
					$aHosts[] = (string)$sChild;
				}
			}
			if ($aHosts !== []) {
				$oSearch = $oSearch->Intersect(\DBObjectSearch::FromOQL(sprintf(
					'SELECT %s WHERE item_class NOT IN (%s)',
					$sClass,
					implode(', ', array_map(static fn (string $s): string => "'".$s."'", array_unique($aHosts)))
				)));
			}
		}

		$aBelow = self::ExcludedBelow($sClass);
		if ($aBelow === []) {
			return $oSearch;
		}

		$oOut = \DBObjectSearch::FromOQL(sprintf(
			'SELECT %s WHERE finalclass NOT IN (%s)',
			$sClass,
			implode(', ', array_map(static fn (string $s): string => "'".$s."'", $aBelow))
		));

		return $oSearch->Intersect($oOut);
	}

	/**
	 * Why a query is refused although every class and field it names is served:
	 * it names a class that has an excluded subclass anywhere but as the one it
	 * selects, and there the rows of the subclass cannot be told apart from the
	 * rest - a join or a sub-query on Contact would match an excluded Person.
	 * Said without naming the class: it would say there is something under it.
	 *
	 * Decided from the query as iTop parses it, not from its text (review 2026-11-18, HA-3): the
	 * text counted mentions of the class and refused at two, so one mention as a joined class or in
	 * a sub-query passed - `SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id WHERE
	 * c.email LIKE 'agatha%'` answered, with `Person` excluded, whether an excluded person's email
	 * began so. The selected class is the one thing narrowed to leave the subclass out
	 * ({@see Narrowed()}); every other mention of a class in the query - a join, a sub-query, any
	 * branch of a union - is refused when the class has an excluded subclass. A query that cannot
	 * be parsed here is refused.
	 */
	public static function OqlRefusal(string $sOql): ?string
	{
		if (!ExclusionPolicy::IsActive()) {
			return null;
		}

		$aTainted = [];
		try {
			foreach (ExclusionPolicy::state()['classes'] as $sExcluded) {
				$sExcluded = ExclusionSettings::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				if (MetaModel::IsValidClass($sExcluded)) {
					foreach (ExclusionPolicy::lineage($sExcluded) as $sParent) {
						if (strcasecmp($sParent, $sExcluded) !== 0) {
							$aTainted[strtolower($sParent)] = true;
						}
					}
				}
			}

			if ($aTainted === []) {
				return null;
			}

			if (!class_exists('OqlInterpreter')) {
				require_once(APPROOT.'core/oql/check_oql.php');
			}
			$oQuery = (new \OqlInterpreter($sOql))->ParseQuery();

			// Each class a query mentions where it is not the selected one, in the whole parsed tree.
			foreach (self::mentionedBesidesTheSelected($oQuery) as $sClass) {
				if (isset($aTainted[strtolower($sClass)])) {
					return self::UNPROVEN_QUERY;
				}
			}
		} catch (Throwable $e) {
			return self::UNPROVEN_QUERY;
		}

		return null;
	}

	/**
	 * Every class a query names, from the query as iTop parses it: the class it returns, each joined class, the class of every
	 * sub-query at any depth and every branch of a union. The one walk both the exclusions ({@see OqlRefusal()}) and the read
	 * check of ADR 0023 stand on, so that a join or a sub-query is asked about like a selected class.
	 *
	 * Plumbing only: it decides nothing. A tool asks UserRights of each class in its own source (ADR 0014).
	 *
	 * @return array<int, string>
	 * @throws \LogicException when the query cannot be parsed; the caller has parsed it already, so it fails closed.
	 * @since 2.1.0
	 */
	public static function ClassesNamed(string $sOql): array
	{
		try {
			if (!class_exists('OqlInterpreter')) {
				require_once(APPROOT.'core/oql/check_oql.php');
			}

			return array_values(array_unique(self::allClassesOf((new \OqlInterpreter($sOql))->ParseQuery())));
		} catch (Throwable $e) {
			throw new \LogicException('A query this channel cannot read the classes of.', 0, $e);
		}
	}

	private const UNPROVEN_QUERY ='This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';

	/**
	 * The classes of a parsed query other than the one it returns: joined classes, the classes of every
	 * sub-query, and every class of a union (whose rows are not narrowed branch by branch).
	 *
	 * Psalm runs with no iTop checkout (psalm.xml), so it cannot tell what the parser's classes extend.
	 *
	 * @return array<int, string>
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress RedundantCondition
	 * @psalm-suppress RedundantCast
	 */
	private static function mentionedBesidesTheSelected(\OqlQuery $oQuery): array
	{
		if ($oQuery instanceof \OqlUnionQuery) {
			$aClasses = [];
			foreach ($oQuery->GetQueries() as $oBranch) {
				$aClasses = array_merge($aClasses, self::allClassesOf($oBranch));
			}

			return $aClasses;
		}

		if (!$oQuery instanceof \OqlObjectQuery) {
			throw new \LogicException('A query of a kind this channel does not know.');
		}

		return self::classesOf($oQuery, true);
	}

	/**
	 * @return array<int, string>
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress RedundantCondition
	 * @psalm-suppress RedundantCast
	 */
	private static function allClassesOf(\OqlQuery $oQuery): array
	{
		if ($oQuery instanceof \OqlUnionQuery) {
			$aClasses = [];
			foreach ($oQuery->GetQueries() as $oBranch) {
				$aClasses = array_merge($aClasses, self::allClassesOf($oBranch));
			}

			return $aClasses;
		}
		if (!$oQuery instanceof \OqlObjectQuery) {
			throw new \LogicException('A query of a kind this channel does not know.');
		}

		return self::classesOf($oQuery, false);
	}

	/**
	 * @param bool $bLeaveSelected The query is the outermost one: the class it returns is narrowed, so it is not listed.
	 *
	 * @return array<int, string>
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress RedundantCondition
	 * @psalm-suppress RedundantCast
	 */
	private static function classesOf(\OqlObjectQuery $oQuery, bool $bLeaveSelected): array
	{
		$aSelected = [];
		foreach ((array)$oQuery->GetSelectedClasses() as $oSelected) {
			$aSelected[] = (string)$oSelected->GetValue();
		}

		$aMentions = [(string)$oQuery->GetClassAlias() => (string)$oQuery->GetClassDetails()->GetValue()];
		foreach ((array)$oQuery->GetJoins() as $oJoin) {
			$aMentions[(string)$oJoin->GetClassAlias()] = (string)$oJoin->GetClass();
		}

		$aClasses = [];
		foreach ($aMentions as $sAlias => $sClass) {
			if ($bLeaveSelected && in_array((string)$sAlias, $aSelected, true)) {
				continue;
			}
			$aClasses[] = $sClass;
		}

		$oCondition = $oQuery->GetCondition();
		if ($oCondition instanceof \Expression) {
			$aNested = [];
			$oCondition->Browse(static function ($oExpression) use (&$aNested): void {
				if ($oExpression instanceof \NestedQueryOqlExpression) {
					$aNested[] = $oExpression->GetOQLObjectQuery();
				}
			});
			foreach ($aNested as $oNested) {
				$aClasses = array_merge($aClasses, self::allClassesOf($oNested));
			}
		}

		return $aClasses;
	}
}
