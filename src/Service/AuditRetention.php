<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

/**
 * The decisions {@see AuditHousekeeping} makes, apart from the database it
 * makes them on, so that they can be asserted without iTop.
 *
 * @since 1.3.0
 */
final class AuditRetention
{
	/**
	 * The date before which a row is past retention.
	 *
	 * @since 1.3.0
	 */
	public static function CutoffFor(int $iNow, int $iDays): string
	{
		return date('Y-m-d H:i:s', $iNow - $iDays * 86400);
	}

	/**
	 * Whether a row still carries a name that is not its account's login.
	 *
	 * With no login to write, the row is left as it is.
	 *
	 * @since 1.3.0
	 */
	public static function NeedsLogin(string $sUserinfo, string $sLogin): bool
	{
		return $sLogin !== '' && $sUserinfo !== $sLogin;
	}
}
