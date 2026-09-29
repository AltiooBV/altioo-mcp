<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeCaseLog;
use AttributeString;
use iAttributeNoGroupBy;
use MetaModel;
use Throwable;

/**
 * Text that a spreadsheet would run as a formula, refused on the way in.
 *
 * A value starting with =, +, -, @, a tab or a carriage return - leading
 * spaces skipped, since a spreadsheet may trim them on import - becomes live
 * when it reaches a spreadsheet - =HYPERLINK(…) to exfiltrate what is beside
 * it, DDE to start a program. Where it leaves iTop is not one place: iTop's
 * own CSV export does not neutralise it, REST returns it as stored, and a
 * model reading it through this endpoint may be asked to build the CSV
 * itself. The one point this module holds is the way in, and this endpoint
 * is the low-supervision write path most likely to put such a value there.
 *
 * Numbers are left alone, because refusing them would break real data: a
 * phone number (+33 1 23 45 67 89), a negative quantity, a date written with
 * dashes. A value is let through when it is only a sign, or a sign and then
 * digits, spaces and ( ) . / -. Everything else starting with a trigger
 * character is refused and told why, including the harmless @jdoe - the
 * rule is the one OWASP gives, deliberately blunt, and mcp_refuse_formula_values
 * turns it off for an instance that never exports.
 *
 * Case logs are not checked here: an exported log starts with iTop's own
 * "====" entry header rather than with the caller's text, and the entries are
 * MentionPolicy's. Secrets (iAttributeNoGroupBy - passwords, encrypted
 * strings) are not checked either: they are masked on every read, and a
 * password refused for its first character would be absurd.
 *
 * @api
 * @since 1.1.0
 */
final class FormulaPolicy
{
	/** What a spreadsheet takes as the start of a formula (OWASP, CSV injection). */
	private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

	/** A sign alone, or a sign followed by what a number, phone number or date is made of. */
	private const NUMBER_LIKE = '/^(?:[+-]+|[+-]?[0-9][0-9 ().\/-]*)$/';

	/**
	 * Why this value may not be written, or null when it may.
	 */
	public static function RefusalFor(string $sClass, string $sAttCode, mixed $value): ?string
	{
		if (!is_string($value) || !MCPHelper::RefusesFormulaValues()) {
			return null;
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		} catch (Throwable $e) {
			// An unknown attribute is refused by the write itself, in its own words.
			return null;
		}
		// The exclusions first. Both are AttributeString subclasses in iTop,
		// but static analysis runs without iTop and cannot know it: asked in
		// the other order, it reads "an AttributeString that is a case log" as
		// a contradiction.
		if ($oAttDef instanceof AttributeCaseLog || $oAttDef instanceof iAttributeNoGroupBy) {
			return null;
		}
		if (!$oAttDef instanceof AttributeString) {
			return null;
		}

		return self::RefusalForText($sAttCode, $value);
	}

	/**
	 * The rule itself, for any text.
	 */
	public static function RefusalForText(string $sAttCode, string $sValue): ?string
	{
		// Leading spaces are skipped, not trusted: a spreadsheet that trims
		// them on import (LibreOffice can) turns " =HYPERLINK(…)" back into
		// a formula. Tab and CR are not skipped - they are triggers.
		$sValue = ltrim($sValue, ' ');
		if ($sValue === '' || !in_array($sValue[0], self::TRIGGERS, true)) {
			return null;
		}
		if (preg_match(self::NUMBER_LIKE, $sValue) === 1) {
			return null;
		}

		return sprintf(
			"The value for '%s' starts with %s, which a spreadsheet runs as a formula when this data is exported or copied into one. "
			.'Write it without the leading character, or reword it. An administrator can turn this check off with mcp_refuse_formula_values.',
			$sAttCode,
			match ($sValue[0]) {
				"\t" => 'a tab',
				"\r" => 'a carriage return',
				default => "'".$sValue[0]."'",
			}
		);
	}
}
