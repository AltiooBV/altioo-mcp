<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * What counts as an object id: a positive number, and nothing else.
 *
 * Formerly ObjectId::Normalise(). Reading and writing both report ids, so the
 * decision sits at the bottom, where both can ask it.
 *
 * @internal
 * @since 2.0.0
 */
final class ObjectId
{
	/**
	 * An object id as a number, or null when there is not one yet.
	 *
	 * The one place that decides what counts as an id, because two callers
	 * need the same answer for different reasons: WritePlan reports it, and
	 * ObjectCreate uses it to tell a write that committed from one that did
	 * not.
	 *
	 * Anything not a positive number is null. iTop gives an unsaved object a
	 * deliberately negative temporary key (DBObject::GetNextTempId()), so a
	 * negative one means the row never landed - the same thing "no id yet"
	 * already means here - and reporting it would be reporting a row nobody
	 * can fetch.
	 *
	 * @param int|string|null $mId
	 *
	 * @since 2.0.0
	 */
	public static function Normalise(int|string|null $mId): ?int
	{
		if ($mId === null || !is_numeric($mId)) {
			return null;
		}

		return (int) $mId > 0 ? (int) $mId : null;
	}
}
