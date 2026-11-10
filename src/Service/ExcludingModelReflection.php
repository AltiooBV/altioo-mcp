<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\ExclusionPolicy;
use ModelReflectionRuntime;

/**
 * The datamodel as iTop's OQL checker sees it, with what the operator excluded
 * taken out (doc/adr/0012).
 *
 * An OQL query is proved to touch nothing excluded by asking iTop's own checker
 * - `OqlQuery::Check()` - to check it against this reflection instead of the real
 * one. The checker walks the whole query, sub-queries and joins included, and
 * asks the reflection about every class and every field it meets; an excluded
 * one is answered as one the datamodel does not have, so the refusal is iTop's
 * own words for an unknown class or attribute and is the same whether the name
 * is hidden or never existed. A query the checker cannot prove is refused by
 * the checker itself.
 *
 * Only loaded once iTop's `core/oql/check_oql.php` has been, which is what
 * declares the class this extends.
 *
 * @internal
 * @since 1.3.0
 */
final class ExcludingModelReflection extends ModelReflectionRuntime
{
	#[\Override]
	public function IsValidClass($sClass)
	{
		return parent::IsValidClass($sClass) && !ExclusionPolicy::ExcludesClass((string)$sClass);
	}

	#[\Override]
	public function IsValidAttCode($sClass, $sAttCode)
	{
		return parent::IsValidAttCode($sClass, $sAttCode)
			&& !ExclusionPolicy::ExcludesClass((string)$sClass)
			&& !ExclusionPolicy::ExcludesAttribute((string)$sClass, (string)$sAttCode);
	}

	#[\Override]
	public function IsValidFilterCode($sClass, $sFilterCode)
	{
		return parent::IsValidFilterCode($sClass, $sFilterCode)
			&& !ExclusionPolicy::ExcludesClass((string)$sClass)
			&& !ExclusionPolicy::ExcludesAttribute((string)$sClass, (string)$sFilterCode);
	}

	#[\Override]
	public function ListAttributes($sClass, $sScope = null)
	{
		return array_diff_key((array)parent::ListAttributes($sClass, $sScope), array_flip(ExclusionPolicy::ExcludedAttributes((string)$sClass)));
	}

	#[\Override]
	public function GetFiltersList($sClass)
	{
		return array_values(array_diff((array)parent::GetFiltersList($sClass), ExclusionPolicy::ExcludedAttributes((string)$sClass)));
	}

	#[\Override]
	public function GetClasses($sCategories = '', $bExcludeLinks = false)
	{
		return array_values(array_filter(
			(array)parent::GetClasses($sCategories, $bExcludeLinks),
			static fn ($sClass): bool => !ExclusionPolicy::ExcludesClass((string)$sClass)
		));
	}

	#[\Override]
	public function EnumChildClasses($sClass, $iOption = ENUM_CHILD_CLASSES_EXCLUDETOP)
	{
		return array_values(array_filter(
			(array)parent::EnumChildClasses($sClass, $iOption),
			static fn ($sChild): bool => !ExclusionPolicy::ExcludesClass((string)$sChild)
		));
	}
}
