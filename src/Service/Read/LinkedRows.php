<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use AttributeLinkedSet;
use DBObject;

/**
 * Which rows of a link set a caller is shown, in the mode this request is
 * served in.
 *
 * Every route that reports a link set - a read, a portal list, the `applied`
 * and `changes` of a write, a dry run - reaches {@see ObjectSerializer::Value()},
 * so that is where this is asked, once, instead of in each tool. The console
 * serves a set as the datamodel renders it. A portal user's rights are not what
 * UserRights says (the `Portal user` profile reads every class), so in portal
 * mode a row is shown only as the portal shows it: its remote object in the
 * user's scope, with the columns the portal lists for it
 * (doc/adr/0006-one-tool-surface-the-portal-is-a-policy.md and the portal's own
 * ObjectFormManager::PrepareFields(), which hides a row the scope does not hold).
 *
 * The portal's answer is the access of the request, which sits in a layer above
 * this one: Service\Server\RequestScope::EnterPortal() remembers how to ask it,
 * as it does for ObjectAccess, and Close() forgets. Portal mode with nothing
 * remembered serves no row, never every row.
 *
 * @internal
 * @since 2.1.0
 */
final class LinkedRows
{
	/** @var (\Closure(DBObject, string, ?int): ?array{0: array<int, array<string, mixed>>, 1: int})|null */
	private static ?\Closure $fRows = null;

	/**
	 * @param \Closure(DBObject, string, ?int): ?array{0: array<int, array<string, mixed>>, 1: int} $fRows Host object, attribute code and how many rows to describe, to the rows shown.
	 *
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Remember(\Closure $fRows): void
	{
		self::$fRows = $fRows;
	}

	/**
	 * For tests, and for a runner that serves more than one request.
	 *
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Forget(): void
	{
		self::$fRows = null;
	}

	/**
	 * The rows of the link set the caller is shown, or null when the mode
	 * serves it as the datamodel renders it.
	 *
	 * @param int|null $iMost How many rows will be reported at most, or null for every one: a row beyond it is
	 *                        counted in the total and not described, which is what a ceiling that follows cuts.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: int}|null The rows described, and the number the caller is shown in all.
	 */
	public static function Of(DBObject $oObject, AttributeLinkedSet $oAttDef, ?int $iMost = null): ?array
	{
		if (PortalMode::Current() === null) {
			return null;
		}

		// Portal mode, and nobody to say what the portal shows: no row.
		if (self::$fRows === null) {
			return [[], 0];
		}

		return (self::$fRows)($oObject, $oAttDef->GetCode(), $iMost);
	}
}
