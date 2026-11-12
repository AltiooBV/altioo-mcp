<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use AttributeDate;
use AttributeDateTime;
use AttributeDefinition;
use AttributeEmailAddress;
use AttributeURL;
use MetaModel;

/**
 * How an attribute's value is shaped, for a caller that has to write one.
 *
 * The format a date or datetime takes in this instance, and the regular
 * expression that format becomes - for the numeric tokens only, because a format
 * built from anything else is reported without a pattern rather than with a
 * wrong one. {@see AttributeDescription} asks for both when it describes an
 * attribute.
 *
 * @internal
 * @since 2.0.0
 */
final class AttributeFormat
{
	/**
	 * PHP date() tokens this module can turn into a regular expression.
	 *
	 * Deliberately only the numeric ones: a format built from anything else is
	 * reported without a pattern rather than with a wrong one.
	 */
	private const DATE_FORMAT_TOKENS = [
		'Y' => '\d{4}',
		'y' => '\d{2}',
		'm' => '\d{2}',
		'n' => '\d{1,2}',
		'd' => '\d{2}',
		'j' => '\d{1,2}',
		'H' => '\d{2}',
		'G' => '\d{1,2}',
		'i' => '\d{2}',
		's' => '\d{2}',
	];

	/**
	 * ECMA-262 SyntaxCharacter, plus '/'. Escaping any of these is valid both
	 * there and in PCRE; escaping anything else is not.
	 */
	private const REGEX_SYNTAX_CHARACTERS = ['^', '$', '\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/'];

	/**
	 * The JSON Schema `format` an attribute's values honour, or null.
	 *
	 * Only claimed where iTop's own syntax really is the one the format names.
	 * A format is a promise about the string on the wire: a wrong one has the
	 * model send a value iTop then refuses, which is worse than saying nothing.
	 */
	public static function format(AttributeDefinition $oAttDef): ?string
	{
		// Order matters: AttributeDate extends AttributeDateTime.
		if ($oAttDef instanceof AttributeDate) {
			return 'date'; // 'Y-m-d' is exactly RFC 3339 full-date.
		}

		if ($oAttDef instanceof AttributeDateTime) {
			// Deliberately not 'date-time'. iTop reads and writes
			// 'Y-m-d H:i:s' - a space, no timezone - and
			// AttributeDateTime::MakeRealValue() throws on anything else, so a
			// model told 'date-time' would send the RFC 3339 form and have
			// every create and update rejected. The pattern carries the real
			// syntax instead.
			return null;
		}

		if ($oAttDef instanceof AttributeEmailAddress) {
			return 'email';
		}

		if ($oAttDef instanceof AttributeURL) {
			return 'uri';
		}

		return null;
	}

	/**
	 * The JSON Schema `pattern` an attribute's values match, or null.
	 *
	 * Dates and date-times only, and read off the attribute rather than
	 * hardcoded - which follows an iTop upgrade rather than a datamodel: the
	 * internal format is a literal on the attribute class, not a setting, and
	 * the configurable one is GetFormat(), the display format, which is not
	 * what crosses this wire. Unlike `format`, a pattern is asserted by every
	 * validator, so it is the part that actually keeps a date-time honest.
	 */
	public static function pattern(AttributeDefinition $oAttDef): ?string
	{
		if (!$oAttDef instanceof AttributeDateTime) {
			return null;
		}

		return self::PatternFromDateFormat($oAttDef::GetInternalFormat());
	}

	/**
	 * Translates a PHP date() format into an anchored regular expression.
	 *
	 * Returns null rather than guessing: a format carrying a token this does
	 * not know, or a backslash escape, yields no pattern at all. Pure - it
	 * touches no MetaModel - which is what lets it be tested without iTop.
	 *
	 * @since 1.0.0
	 */
	public static function PatternFromDateFormat(string $sFormat): ?string
	{
		if ($sFormat === '' || str_contains($sFormat, '\\')) {
			return null;
		}

		$sPattern = '';
		foreach (str_split($sFormat) as $sChar) {
			if (isset(self::DATE_FORMAT_TOKENS[$sChar])) {
				$sPattern .= self::DATE_FORMAT_TOKENS[$sChar];

				continue;
			}

			if (ctype_alpha($sChar)) {
				return null;
			}

			// Not preg_quote(): it escapes '-' and ':' too, and "\-" is an
			// invalid identity escape in ECMA-262, which is the flavour JSON
			// Schema patterns are read as. Only the syntax characters, which
			// are escapable in both flavours, are escaped here.
			$sPattern .= in_array($sChar, self::REGEX_SYNTAX_CHARACTERS, true) ? '\\'.$sChar : $sChar;
		}

		return '^'.$sPattern.'$';
	}
}
