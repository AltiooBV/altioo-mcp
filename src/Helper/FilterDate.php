<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeDate;
use AttributeDateTime;

/**
 * A date or date-time given to a filter as RFC 3339, in the time zone the instance stores its dates in.
 *
 * iTop stores a date-time as local text with no zone, and a filter compares text. Handed
 * `2026-10-28T10:46:22Z`, the comparison ignored the `Z` and matched a row stored at 10:46:22 local time, one hour
 * out in winter and two in summer on an instance in UTC+1 (review 2026-11-23, B2). So a value with an explicit
 * offset is converted the way core_my_activity's `since` is (strtotime, then date() in the zone iTop set as
 * PHP's default), and anything else is left exactly as it was sent: iTop's own format, and what the ORM refuses.
 *
 * @api
 * @since 2.2.0
 */
final class FilterDate
{
	/** A date-time with a time and an explicit offset: `Z`, `+01:00`, `-0500`. Nothing looser is converted. */
	private const WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?\s*(Z|z|[+-]\d{2}(:?\d{2})?)$/';

	/**
	 * @param mixed $value The filter value as sent.
	 *
	 * @return mixed The value to filter on.
	 *
	 * @psalm-suppress TypeDoesNotContainType iTop's attribute classes are not known to the analysis as related; AttributeDate extends AttributeDateTime.
	 */
	public static function ForFilter(object $oAttDef, mixed $value): mixed
	{
		if (!is_string($value) || !is_a($oAttDef, AttributeDateTime::class) || preg_match(self::WITH_OFFSET, trim($value)) !== 1) {
			return $value;
		}

		$iTime = strtotime(trim($value));
		if ($iTime === false) {
			return $value;
		}

		// AttributeDate extends AttributeDateTime: a date column holds the day, in the instance's zone.
		return date(is_a($oAttDef, AttributeDate::class) ? 'Y-m-d' : 'Y-m-d H:i:s', $iTime);
	}
}
