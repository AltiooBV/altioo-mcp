<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Audit;

/**
 * The decisions {@see AuditHousekeeping} makes, apart from the database it
 * makes them on, so that they can be asserted without iTop.
 *
 * @since 1.3.0
 */
final class AuditRetention
{
	/**
	 * The free-text columns cleared from a row when the person behind its
	 * account is anonymised: what a caller wrote or was told, which can name
	 * people.
	 *
	 * @var array<int, string>
	 */
	public const CLEARED_ON_ANONYMISATION = ['request_params', 'change_comment', 'refusal_text'];

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
