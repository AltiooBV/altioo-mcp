<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use MetaModel;
use UserRights;
use iAttributeNoGroupBy;

/**
 * The datamodel, read the way the calling user is allowed to see it.
 *
 * The class list and the class detail are each served twice - once as a
 * resource, once as a tool - because clients differ in what they actually
 * fetch: many never read resources at all, and support for resource templates
 * is thinner still. A model that cannot discover the schema falls back to
 * guessing attribute codes, so the schema has to be reachable from the surface
 * every client does support.
 *
 * Two surfaces, one implementation: what core_ClassSchema reports and what
 * itop://core/class/{class} reports cannot drift apart, and the access checks
 * are written once rather than per surface.
 */
final class DatamodelReader
{
	/**
	 * Whether the class exists *and* the caller may read it.
	 *
	 * The two are deliberately answered together: callers report both as
	 * "unknown class", so that probing this endpoint cannot map out the classes
	 * a user is not allowed to see.
	 */
	public static function IsReadable(string $sClass): bool
	{
		return MetaModel::IsValidClass($sClass) && UserRights::IsActionAllowed($sClass, UR_ACTION_READ);
	}

	/**
	 * Categories declared by the datamodel, e.g. 'bizmodel' or 'searchable'.
	 *
	 * MetaModel keeps '' as the bucket holding every class; it is not a
	 * category anyone can ask for, so it is dropped here.
	 *
	 * @return array<int, string>
	 */
	public static function Categories(): array
	{
		$aCategories = array_filter(MetaModel::EnumCategories(), static fn ($sCategory): bool => $sCategory !== '');
		sort($aCategories);

		return array_values($aCategories);
	}

	/**
	 * Every readable class, or those of one category, summarised and sorted by
	 * class name.
	 *
	 * @param string $sCategory A category from {@see Categories()}; '' for all classes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function ListClasses(string $sCategory = ''): array
	{
		$aClasses = [];

		foreach (MetaModel::GetClasses($sCategory) as $sClass) {
			// Skip classes the current user has no read access to
			if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				continue;
			}

			$aClasses[] = self::Summarize($sClass);
		}

		usort($aClasses, static fn ($a, $b) => strcmp($a['class'], $b['class']));

		return $aClasses;
	}

	/**
	 * Keeps the summaries whose name, label or description contains $sText.
	 *
	 * All three count, and case does not: a model looking for "ticket" should
	 * find UserRequest, whose class name says nothing about tickets.
	 *
	 * Pure - it touches no MetaModel - which is what lets it be tested without
	 * a live iTop.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries, as returned by {@see ListClasses()}.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function FilterByText(array $aClasses, string $sText): array
	{
		if ($sText === '') {
			return array_values($aClasses);
		}

		$aMatching = array_filter($aClasses, static function (array $aClass) use ($sText): bool {
			foreach (['class', 'label', 'description'] as $sField) {
				$sValue = $aClass[$sField] ?? '';
				if (is_string($sValue) && stripos($sValue, $sText) !== false) {
					return true;
				}
			}

			return false;
		});

		return array_values($aMatching);
	}

	/**
	 * What a class is and where it sits in the hierarchy, without its contents.
	 *
	 * @return array<string, mixed>
	 */
	public static function Summarize(string $sClass): array
	{
		return [
			'class'          => $sClass,
			'label'          => MetaModel::GetName($sClass),
			'description'    => MetaModel::GetClassDescription($sClass),
			'isAbstract'     => MetaModel::IsAbstract($sClass),
			'isRoot'         => MetaModel::IsRootClass($sClass),
			'isHierarchical' => MetaModel::IsHierarchicalClass($sClass),
			'rootClass'      => MetaModel::GetRootClass($sClass),
			'parentClass'    => MetaModel::GetParentClass($sClass),
		];
	}

	/**
	 * One class in full: the summary, plus attributes, relations and lifecycle.
	 *
	 * Readability is the caller's to check - see {@see IsReadable()} - because
	 * only the caller knows which exception its surface has to raise.
	 *
	 * @return array<string, mixed>
	 */
	public static function Describe(string $sClass): array
	{
		return self::Summarize($sClass) + [
			'attributes' => self::attributes($sClass),
			'relations'  => self::relations($sClass),
			'lifecycle'  => self::lifecycle($sClass),
		];
	}

	/**
	 * @param string $sClass The class for which to retrieve attribute details
	 * @return array An array of attribute details
	 */
	private static function attributes(string $sClass): array
	{
		$aAttributes = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			$aAttributes[$sAttCode] = [
				'label'         => MetaModel::GetLabel($sClass, $sAttCode),
				'description'   => $oAttDef->GetDescription(),
				'type'          => get_class($oAttDef),
				'required'      => $oAttDef->IsNullAllowed() === false,
				'readOnly'      => $oAttDef->IsWritable() === false,
				'nullable'      => $oAttDef->IsNullAllowed(),
				'isExternalKey' => $oAttDef->IsExternalKey(),
				'isScalar'      => $oAttDef->IsScalar(),
				'isSensible'    => $oAttDef instanceof iAttributeNoGroupBy, // iAttributeNoGroupBy is equivalent to sensitive attribute
				'values'        => $oAttDef->GetAllowedValues(),
			];
		}

		return $aAttributes;
	}

	/**
	 * @param string $sClass The class for which to retrieve relation details
	 * @return array An array of relation details
	 */
	private static function relations(string $sClass): array
	{
		$aRelations = [];

		foreach (MetaModel::EnumRelations() as $sRelation) {
			$aRelated = [];
			foreach (MetaModel::EnumRelationQueries($sClass, $sRelation) as $sNeighbour => $aQueryInfo) {
				$aRelated[] = $sNeighbour;
			}
			if (!empty($aRelated)) {
				$aRelations[$sRelation] = $aRelated;
			}
		}

		return $aRelations;
	}

	/**
	 * @param string $sClass The class for which to retrieve lifecycle details
	 * @return array An array of lifecycle details
	 */
	private static function lifecycle(string $sClass): array
	{
		if (!MetaModel::HasLifecycle($sClass)) {
			return [];
		}
		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($sStateAttCode === '') {
			return [];
		}

		$aStates = [];
		foreach (MetaModel::EnumStates($sClass) as $sState => $aStateDef) {
			$aTransitions = [];
			foreach (MetaModel::EnumTransitions($sClass, $sState) as $sStimulusCode => $aTransitionDef) {
				$aTransitions[$sStimulusCode] = $aTransitionDef['target_state'];
			}
			$aStates[$sState] = [
				'label'       => MetaModel::GetStateLabel($sClass, $sState),
				'transitions' => $aTransitions,
			];
		}

		return [
			'stateAttribute' => $sStateAttCode,
			'states'         => $aStates,
		];
	}
}
