<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Service\Rights\ClassRights;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;

use MetaModel;
use UserRights;

/**
 * The classes the calling user may see, listed and narrowed.
 *
 * The categories, the list of readable classes, the narrowing of that list by a
 * text or by a right, and the envelope the class list is served in by
 * core_class_list and itop://core/classes. Each class in it is summarised by
 * {@see DatamodelReader::Summarize()}; its rights are {@see ClassRights}'s.
 *
 * @api
 * @since 2.0.0
 */
final class ClassCatalog
{
	/**
	 * Categories declared by the datamodel, e.g. 'bizmodel' or 'searchable'.
	 *
	 * MetaModel keeps '' as the bucket holding every class; it is not a
	 * category anyone can ask for, so it is dropped here.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function Categories(): array
	{
		// Trimmed and deduplicated, which iTop's own registration is not.
		//
		// A class declares its categories as one string - "core/cmdb,
		// grant_by_profile" - and MetaModel explodes it on the comma without
		// trimming, so a declaration written with a space after the comma
		// registers the key " grant_by_profile", and one written without it
		// registers "grant_by_profile". Both are in EnumCategories(), and
		// printed in a list they look like the same word twice.
		$aCategories = array_map('trim', MetaModel::EnumCategories());
		$aCategories = array_filter($aCategories, static fn (string $sCategory): bool => $sCategory !== '');
		$aCategories = array_unique($aCategories);
		sort($aCategories);

		return array_values($aCategories);
	}

	/**
	 * Every class in a category, including the ones iTop's own lookup misses.
	 *
	 * The same spacing quirk costs more than a duplicate in a list.
	 * Registration keys the bucket untrimmed and GetClasses() trims what it is
	 * asked for, so "grant_by_profile" finds the classes that declared it
	 * without a space and silently misses every class that declared it with
	 * one - which in iTop's own core is most of them.
	 *
	 * HasCategory() reaches them: it matches against the class's raw
	 * declaration, spaces and all. What it does not do is match on a word
	 * boundary, so it is only asked where that cannot mislead - where the
	 * requested category is not part of a longer one. Elsewhere the exact
	 * lookup stands alone, which is what this module did everywhere until now.
	 *
	 * @return array<int, string>
	 */
	private static function classesInCategory(string $sCategory): array
	{
		$aClasses = MetaModel::GetClasses($sCategory);

		foreach (self::Categories() as $sKnown) {
			if ($sKnown !== $sCategory && str_contains($sKnown, $sCategory)) {
				// "core" would drag in "core/cmdb". The exact answer is the
				// safe one.
				return $aClasses;
			}
		}

		foreach (MetaModel::GetClasses('') as $sClass) {
			if (!in_array($sClass, $aClasses, true) && MetaModel::HasCategory($sClass, $sCategory)) {
				$aClasses[] = $sClass;
			}
		}

		return $aClasses;
	}

	/**
	 * Every readable class, or those of one category, summarised and sorted by
	 * class name.
	 *
	 * @param string $sCategory A category from {@see Categories()}; '' for all classes.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function ListClasses(string $sCategory = ''): array
	{
		$aClasses = [];

		foreach (self::classesInCategory($sCategory) as $sClass) {
			// Skip classes the current user has no read access to, and those the operator excluded (doc/adr/0012)
			if (ExclusionPolicy::ExcludesClass($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				continue;
			}

			$aClasses[] = DatamodelReader::Summarize($sClass);
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
	 * @since 1.0.0
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
	 * The class list as both surfaces report it: the narrowing that was asked
	 * for, how many classes came back, and the classes themselves.
	 *
	 * The envelope is here rather than in the tool because the resource serves
	 * the same answer, and both surfaces have to carry the count and the
	 * echoed narrowings. A bare array on either side leaves a client reading
	 * itop://core/classes with no count, unable to tell a complete list from a
	 * clipped one, and looking at a different shape from the one
	 * core_class_list documents for identical data.
	 *
	 * category and filter are echoed back even when empty, which is what makes
	 * the two surfaces the same shape: the resource takes no arguments, so its
	 * answer is this envelope with both narrowings unset rather than a
	 * different envelope.
	 *
	 * The category is validated by the caller, not here - only the caller
	 * knows whether an unknown one is a ToolCallException or a
	 * ResourceReadException. See {@see Categories()}.
	 *
	 * @param string $sCategory A category from {@see Categories()}; '' for all classes.
	 * @param string $sFilter   Case-insensitive text matched against name, label and description; '' for no filter.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function ClassListPayload(string $sCategory = '', string $sFilter = '', string $sMay = ''): array
	{
		$aClasses = self::FilterByText(self::ListClasses($sCategory), $sFilter);

		// Rights cost a gate call per class, so they are read after the cheap
		// narrowing has run and only when they were asked for. A list with no
		// $sMay carries no rights block, which is what keeps itop://core/classes
		// the same answer it has always been.
		if ($sMay !== '') {
			$aClasses = self::FilterByRight(self::WithRights($aClasses), $sMay);
		}

		return [
			'category' => $sCategory,
			'filter'   => $sFilter,
			'may'      => $sMay,
			'total'    => count($aClasses),
			'classes'  => $aClasses,
		];
	}

	/**
	 * Every summary with the caller's rights on that class attached.
	 *
	 * Impure, and separated from the filtering for the reason FilterByText is
	 * separate from ListClasses: what needs UserRights is one step, and the
	 * decision made from its answer is a pure one that a unit suite can hold.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries, as returned by {@see ListClasses()}.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function WithRights(array $aClasses): array
	{
		return array_values(array_map(
			static fn (array $aClass): array => $aClass + ['rights' => ClassRights::RightsOf($aClass['class'])],
			$aClasses
		));
	}

	/**
	 * Keeps the classes whose $sRight gate is not a refusal.
	 *
	 * 'depends' survives on purpose. It is the addon asking for the object
	 * before it answers, so a class graded that way is one the caller may well
	 * be able to act on - dropping it hides work that can be done, and
	 * reporting it as 'yes' claims an answer nobody gave. It comes back with
	 * its own word, as it does everywhere else here.
	 *
	 * Only a known refusal removes anything: a summary carrying no rights block
	 * is kept rather than dropped, because nothing about it says the caller was
	 * refused.
	 *
	 * Pure - it touches no MetaModel - which is what lets it be tested without
	 * a live iTop.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries carrying a rights block, as returned by {@see WithRights()}.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function FilterByRight(array $aClasses, string $sRight): array
	{
		if ($sRight === '' || $sRight === GateGrades::RIGHTS_ALL) {
			return array_values($aClasses);
		}

		$aAllowed = array_filter(
			$aClasses,
			static fn (array $aClass): bool => ($aClass['rights'][$sRight] ?? null) !== 'no'
		);

		return array_values($aAllowed);
	}
}
