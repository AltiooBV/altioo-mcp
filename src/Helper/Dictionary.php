<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Dict;
use Throwable;

/**
 * Dictionary lookup with the English literal as the fallback.
 *
 * @internal
 * @since 2.0.0
 */
class Dictionary
{
	/**
	 * A dictionary entry, or $sDefault when there is nothing to translate it
	 * with.
	 *
	 * Three cases have to come out as the default rather than as a key: no
	 * iTop at all (the unit suite runs without one), a dictionary that has not
	 * been loaded yet - Dict::S() answers with the key itself there, which
	 * would put "MCP:tool:acme_x:title" in front of a user - and an entry
	 * nobody has written. Falling back to the English literal the element
	 * already carries means a pack that ships no dictionary reads exactly as
	 * it did before, and a pack that ships one is translated.
	 *
	 * @since 1.0.0
	 */
	public static function Translate(string $sKey, string $sDefault): string
	{
		if (!class_exists(Dict::class)) {
			return $sDefault;
		}

		try {
			$sLabel = Dict::S($sKey, $sDefault);
		} catch (Throwable $e) {
			return $sDefault;
		}

		if (!is_string($sLabel) || $sLabel === '' || $sLabel === $sKey) {
			return $sDefault;
		}

		return $sLabel;
	}
}
