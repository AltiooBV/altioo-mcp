<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeDate;
use AttributeDateTime;
use AttributeExternalField;
use AttributeSubItem;

/**
 * A date or date-time given to a filter: which forms it may take, and RFC 3339 in the time zone the instance stores
 * its dates in.
 *
 * iTop stores a date-time as local text with no zone, and a filter compares text. Handed
 * `2026-10-28T10:46:22Z`, the comparison ignored the `Z` and matched a row stored at 10:46:22 local time, one hour
 * out in winter and two in summer on an instance in UTC+1 (review 2026-11-23, B2). So a value with an explicit
 * offset is converted the way core_my_activity's `since` is (strtotime, then date() in the zone iTop set as
 * PHP's default), and iTop's own format is left as it was sent. Anything else would be compared as text and match
 * nothing, which reads as "there is none", so {@see Refusal()} says which forms there are (retest of 2026-11-27).
 *
 * A date here is what the filter compares with one: a date or date-time attribute, an external field showing one,
 * and the started, stopped and deadline dates of a stopwatch.
 *
 * @api
 * @since 2.2.0
 */
final class FilterDate
{
	/** A date-time with a time and an explicit offset: `Z`, `+01:00`, `-0500`. Nothing looser is converted. */
	private const WITH_OFFSET = '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(?:Z|z|[+-]\d{2}(?::?\d{2})?)$/';

	/** iTop's own two forms, `Y-m-d H:i:s` and `Y-m-d`; the second is also RFC 3339's full date. */
	private const OWN = '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/';

	/**
	 * @param mixed $value The filter value as sent.
	 *
	 * @return mixed The value to filter on.
	 */
	public static function ForFilter(object $oAttDef, mixed $value): mixed
	{
		$sKind = self::kindOf($oAttDef);
		if (!is_string($value) || $sKind === null) {
			return $value;
		}

		$sValue = trim($value);
		if (preg_match(self::OWN, $sValue) === 1) {
			return $sValue;
		}
		if (preg_match(self::WITH_OFFSET, $sValue) !== 1) {
			return $value;
		}

		$iTime = strtotime($sValue);
		if ($iTime === false) {
			return $value;
		}

		// A date column holds the day, in the instance's zone.
		return date($sKind === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s', $iTime);
	}

	/**
	 * Why a filter cannot be given this value, or null when it can: a date attribute compared with something that is
	 * no date in a form it takes. The value is not quoted back.
	 *
	 * An empty value and null are left to the search, as they were.
	 *
	 * @param mixed $value The filter value as sent.
	 */
	public static function Refusal(object $oAttDef, string $sAttCode, mixed $value): ?string
	{
		if ($value === null || $value === '' || self::kindOf($oAttDef) === null || self::isDate($value)) {
			return null;
		}

		return "The filter on '{$sAttCode}' takes a date: YYYY-MM-DD HH:MM:SS or YYYY-MM-DD, as iTop writes them, "
			."or RFC 3339 with an offset (2026-01-31T09:30:00+01:00), which is converted to the instance's time zone.";
	}

	/** Whether the value is a day that exists, at a time that exists, in one of the forms taken. */
	private static function isDate(mixed $value): bool
	{
		if (!is_string($value)) {
			return false;
		}
		$sValue = trim($value);
		if (preg_match(self::OWN, $sValue, $aPart) !== 1) {
			// An offset out of range is no instant either.
			if (preg_match(self::WITH_OFFSET, $sValue, $aPart) !== 1 || strtotime($sValue) === false) {
				return false;
			}
		}

		// The groups that did not match are absent, or empty before a later one: both count as zero.
		return checkdate((int)$aPart[2], (int)$aPart[3], (int)$aPart[1])
			&& (int)($aPart[4] ?? 0) < 24
			&& (int)($aPart[5] ?? 0) < 60
			&& (int)($aPart[6] ?? 0) <= 60;
	}

	/**
	 * 'date', 'datetime', or null when the filter on this attribute compares something else.
	 *
	 * @psalm-suppress TypeDoesNotContainType, MixedAssignment, MixedMethodCall, MixedArgument iTop's attribute classes are not known to the analysis as related; AttributeDate extends AttributeDateTime.
	 */
	private static function kindOf(object $oAttDef): ?string
	{
		if (is_a($oAttDef, AttributeExternalField::class)) {
			$oAttDef = $oAttDef->GetFinalAttDef();
		}
		if (is_a($oAttDef, AttributeDateTime::class)) {
			// AttributeDate extends AttributeDateTime.
			return is_a($oAttDef, AttributeDate::class) ? 'date' : 'datetime';
		}
		if (is_a($oAttDef, AttributeSubItem::class)) {
			$oWatch = $oAttDef->GetTargetAttDef();

			return $oWatch->GetSubItemSearchType($oAttDef->Get('item_code')) === $oWatch::SEARCH_WIDGET_TYPE_DATE_TIME ? 'datetime' : null;
		}

		return null;
	}
}
