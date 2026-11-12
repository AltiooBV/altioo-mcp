<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

/**
 * The change log is served by core_object_history only.
 *
 * A rights rule, asked by every tool in its own source (ADR 0014) and by the
 * grading of a class's gates: whether a class belongs to the change log rather
 * than to the object tools.
 *
 * @api
 * @since 2.0.0
 */
final class ChangeLogReservation
{
	/**
	 * The classes this surface keeps to itself.
	 *
	 * CMDBChange and CMDBChangeOp are ordinary DBObjects, so without this they
	 * are ordinary objects to every generic tool: searchable by OQL, readable
	 * by id, creatable. That undoes the whole point of reading them through
	 * this helper. The gates applied in For() - the object gate, and today's
	 * rights on the attribute a row names - live one layer above the rows
	 * themselves, and a caller that reaches the rows directly gets neither.
	 * objkey is an integer column, so "SELECT CMDBChangeOpSetAttributeScalar"
	 * is a readable audit trail of every object in the database, silos and
	 * per-attribute rights included.
	 *
	 * The console draws the same line. History is a tab on an object, not a
	 * class you search; nothing in the UI offers a change-op list, and nothing
	 * offers a change-op form.
	 *
	 * Writing matters as much as reading. A create on one of these forges an
	 * audit record, which is worth more to an attacker than any object it
	 * could describe.
	 *
	 * Subclasses are covered by is_a() rather than by a list: iTop declares a
	 * dozen and a pack may add more, and a surface that has to be extended
	 * whenever one appears is one that quietly stops covering them.
	 */
	private const RESERVED_ROOTS = ['CMDBChange', 'CMDBChangeOp'];

	/**
	 * What every tool says when it refuses one, spelled once so they all say
	 * the same thing and all name the way in.
	 */
	public const RESERVED_REFUSAL = 'Class \'%s\' is the change log, which is served by core_object_history only.';

	/**
	 * Whether $sClass belongs to the change log rather than to the object
	 * tools.
	 *
	 * @since 1.0.0
	 */
	public static function IsReserved(string $sClass): bool
	{
		foreach (self::RESERVED_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}
}
