<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Service\Rights\ExcludingModelReflection;
use MetaModel;
use Throwable;

/**
 * The datamodel as a search through this channel may use it: what the operator excluded is
 * absent (ExcludingModelReflection), and so is every attribute the endpoint masks on output.
 *
 * A masked attribute (a password, a token, an outbound secret: ObjectSerializer::IsSensitive())
 * is read back as asterisks, and a search that can filter on it is a read of it one prefix at a
 * time - `token LIKE "SECRET-A%"` answers yes or no. core_object_count has always refused such an
 * attribute; the two search tools did not (review 2026-11-18, RT-3). Asked as the OQL checker's
 * reflection, the attribute is unknown in a condition, a join or a sub-query alike, in iTop's own
 * words for a name the datamodel does not have.
 *
 * It lives in this layer because the answer comes from the serialiser, which the rights layer may
 * not import.
 *
 * @internal
 * @since 2.1.0
 */
final class SearchableModelReflection extends ExcludingModelReflection
{
	#[\Override]
	public function IsValidAttCode($sClass, $sAttCode)
	{
		return parent::IsValidAttCode($sClass, $sAttCode) && !self::masked((string)$sClass, (string)$sAttCode);
	}

	#[\Override]
	public function IsValidFilterCode($sClass, $sFilterCode)
	{
		return parent::IsValidFilterCode($sClass, $sFilterCode) && !self::masked((string)$sClass, (string)$sFilterCode);
	}

	#[\Override]
	public function ListAttributes($sClass, $sScope = null)
	{
		return array_filter(
			(array)parent::ListAttributes($sClass, $sScope),
			static fn ($mType, $mCode): bool => !self::masked((string)$sClass, (string)$mCode),
			ARRAY_FILTER_USE_BOTH
		);
	}

	#[\Override]
	public function GetFiltersList($sClass)
	{
		return array_values(array_filter(
			(array)parent::GetFiltersList($sClass),
			static fn ($sCode): bool => !self::masked((string)$sClass, (string)$sCode)
		));
	}

	/**
	 * Whether the endpoint masks the attribute's value. A code that is not an attribute (`id`, a
	 * filter that is not one) is not; neither is one that cannot be resolved, which the parent has
	 * already answered.
	 */
	private static function masked(string $sClass, string $sAttCode): bool
	{
		try {
			return MetaModel::IsValidAttCode($sClass, $sAttCode)
				&& ObjectSerializer::IsSensitive(MetaModel::GetAttributeDef($sClass, $sAttCode), $sClass);
		} catch (Throwable) {
			return false;
		}
	}
}
