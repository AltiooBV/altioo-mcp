<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

/**
 * The instance's clock, as a model needs it to read a date it was served or to write one.
 *
 * iTop stores and serves date-times as 'Y-m-d H:i:s' with no zone, in the time zone its configuration sets
 * (iTop's `utils::InitTimeZone()` makes it PHP's default), and a filter converts an RFC 3339 value with an offset
 * to it. Nothing named that zone or the time now, so a model could neither say what "2026-11-24 10:46:22" meant
 * nor know what "since this morning" was (review 2026-11-23, C10).
 *
 * @internal
 * @since 2.2.0
 */
final class InstanceTime
{
	/**
	 * The zone and the time now.
	 *
	 * @param int|null $iTimestamp The instant, for a test; now by default.
	 *
	 * @return array{timezone: string, now: string} The zone's name (Europe/Brussels, or UTC), and the instant as
	 *                                              RFC 3339 with its offset (2026-11-25T10:20:00+01:00).
	 */
	public static function Now(?int $iTimestamp = null): array
	{
		$iTimestamp ??= time();

		return [
			'timezone' => date_default_timezone_get(),
			'now'      => date('c', $iTimestamp),
		];
	}
}
