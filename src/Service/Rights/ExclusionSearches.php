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
		} catch (Throwable $e) {
			return 'This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';
		}

		foreach (array_keys($aTainted) as $sParent) {
			$iNamed = preg_match_all('/\b'.preg_quote($sParent, '/').'\b/i', $sOql);
			if ($iNamed > 1) {
				return 'This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';
			}
		}

		return null;
	}
}
