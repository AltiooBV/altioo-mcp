<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use AttributeCaseLog;
use AttributeLinkedSet;

/**
 * The ceilings on the three attribute kinds that have no natural size - a long text, a case log,
 * a link set - and the cuts that apply them.
 *
 * A broad read must not spend a context window nobody asked it to spend. None of the ceilings
 * limits what a caller may read: each cut says how it was made, and naming the attribute in
 * output_fields returns it whole. It was the clipping half of `ObjectSerializer` in 1.x.
 *
 * @internal
 * @since 2.0.0
 */
final class ValueCeilings
{
	/**
	 * Ceilings on the three attribute kinds that have no natural size.
	 *
	 * None of them is a limit on what the caller may read: each one says how it
	 * was cut, and naming the attribute in output_fields returns it whole.
	 */
	public const MAX_TEXT_CHARS = 4000;

	public const MAX_CASELOG_ENTRIES = 10;

	public const MAX_LINKS = 50;

	/**
	 * The tail of a case log, which is the part anyone asks about.
	 *
	 * A five-year-old incident carries hundreds of entries and reaches the
	 * caller in full. GetForJSON() returns them oldest first, so the recent
	 * ones - the state of the conversation now - are at the end.
	 *
	 * @param mixed $value As AttributeCaseLog::GetForJSON() returns it.
	 *
	 * @return mixed
	 */
	public static function recentEntries($value)
	{
		if (!is_array($value) || !isset($value['entries']) || !is_array($value['entries'])) {
			return $value;
		}

		$iTotal = count($value['entries']);
		if ($iTotal <= self::MAX_CASELOG_ENTRIES) {
			return $value;
		}

		$value['entries'] = array_values(array_slice($value['entries'], -self::MAX_CASELOG_ENTRIES));
		$value['entries_omitted'] = $iTotal - self::MAX_CASELOG_ENTRIES;
		$value['entries_total'] = $iTotal;

		return $value;
	}

	/**
	 * The head of a link set, with a count of what was left out.
	 *
	 * A rack with four hundred devices, expanded attribute by attribute, is
	 * larger than everything else in the response put together.
	 *
	 * @param mixed $value As AttributeLinkedSet::GetForJSON() returns it.
	 *
	 * @return mixed
	 */
	public static function firstLinks($value)
	{
		if (!is_array($value) || count($value) <= self::MAX_LINKS) {
			return $value;
		}

		return [
			'links'         => array_values(array_slice($value, 0, self::MAX_LINKS)),
			'links_omitted' => count($value) - self::MAX_LINKS,
			'links_total'   => count($value),
		];
	}

	/**
	 * Cuts long strings, wherever they sit in a value.
	 *
	 * One description can hold an entire email thread, quoted signatures and
	 * all. The cut says so in the value itself rather than in a flag beside
	 * it, so a model reading the text knows it is reading part of it - and is
	 * told, in the text, how to read the rest.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public static function clip($value)
	{
		if (is_array($value)) {
			return array_map(static fn ($mItem) => self::clip($mItem), $value);
		}

		if (!is_string($value) || mb_strlen($value) <= self::MAX_TEXT_CHARS) {
			return $value;
		}

		$iOmitted = mb_strlen($value) - self::MAX_TEXT_CHARS;

		return mb_substr($value, 0, self::MAX_TEXT_CHARS)
			.sprintf(' […truncated, %d more characters. Name this attribute in output_fields to read it in full]', $iOmitted);
	}
}
