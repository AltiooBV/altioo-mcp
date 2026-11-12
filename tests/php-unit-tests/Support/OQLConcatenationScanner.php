<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

/**
 * Finds `.`-concatenation into the OQL text handed to `DBObjectSearch::FromOQL()`
 * / `DBSearch::FromOQL()`.
 *
 * Deliberately narrower than "any `.` in the query text": interpolating a
 * validated class name into a `FROM` clause is this module's normal, safe
 * pattern (OQL takes no bind parameter there), and a rule that also caught
 * `"SELECT {$sClass}"` would fail every current call site. Concatenation of a
 * variable is the shape that lets an unvalidated value reach the query text
 * unexamined; concatenating a class constant - a bind parameter *name*, fixed
 * at compile time, as in `"...:".self::ID_PARAMETER`
 * (src/Service/Read/ObjectQuery.php) - is not that shape. So only a `.` adjacent to
 * a variable counts.
 *
 * Scoped to the first argument only (up to the first top-level comma, or the
 * matching close paren): the second `FromOQL()` argument is bind values, where
 * a `.` is unrelated arithmetic-adjacent code, not query text.
 *
 * **What this does not see.** It reads tokens, not data flow, so the query text
 * has to be concatenated *at the call* for it to have an opinion. Each of these
 * passes silently, and a reader should not mistake a green run for more than it
 * is:
 *
 *  - a wrapper doing the joining - `sprintf('SELECT ... %s', $v)`,
 *    `str_replace(...)`, `implode(...)`;
 *  - a query assembled into a variable on an earlier line and passed as
 *    `FromOQL($sOql)`;
 *  - a receiver reached through an alias (`use DBObjectSearch as Search;`).
 *
 * Catching those needs the kind of source-to-sink analysis the progpilot job in
 * .github/workflows/security.yml does, and none of them is a shape this module
 * currently uses. Relatedly, `src/Core/Tools/ObjectSearchByOQL.php` hands a
 * bare `$oql` straight to `FromOQL()` and is deliberately not flagged: taking
 * an OQL from the caller is that tool's entire purpose, so the check says
 * nothing about the module's most OQL-exposed path.
 */
final class OQLConcatenationScanner
{
	/**
	 * @return array<int, array{0: int, 1: string}> Zero-based (token index, line) pairs, one per offending call.
	 */
	public static function findViolations(string $sCode): array
	{
		$aTokens = token_get_all($sCode);
		$aViolations = [];
		$iCount = count($aTokens);

		for ($i = 0; $i < $iCount; $i++) {
			if (!self::isFromOQLReceiver($aTokens[$i] ?? null)) {
				continue;
			}

			$iNext = self::nextSignificant($aTokens, $i + 1);
			if ($iNext === null || !is_array($aTokens[$iNext]) || $aTokens[$iNext][0] !== T_DOUBLE_COLON) {
				continue;
			}

			$iMethod = self::nextSignificant($aTokens, $iNext + 1);
			if ($iMethod === null || !is_array($aTokens[$iMethod]) || $aTokens[$iMethod][0] !== T_STRING || $aTokens[$iMethod][1] !== 'FromOQL') {
				continue;
			}

			$iParen = self::nextSignificant($aTokens, $iMethod + 1);
			if ($iParen === null || $aTokens[$iParen] !== '(') {
				continue;
			}

			$iLine = is_array($aTokens[$i]) ? $aTokens[$i][2] : self::lineAt($aTokens, $i);
			if (self::firstArgumentConcatenates($aTokens, $iParen + 1)) {
				$aViolations[] = [$i, (string) $iLine];
			}
		}

		return $aViolations;
	}

	/**
	 * Both spellings of the receiver, because both compile to the same call.
	 * `DBObjectSearch` is a T_STRING resolved through the file's `use`
	 * statement; `\DBObjectSearch` is a single T_NAME_FULLY_QUALIFIED token
	 * needing no import at all, which in a namespaced file - and every file in
	 * src/ is namespaced - is the ordinary way to reach a global class without
	 * one. Matching only the first spelling made a leading backslash enough to
	 * walk past this check.
	 */
	private static function isFromOQLReceiver($mToken): bool
	{
		if (!is_array($mToken) || !in_array($mToken[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
			return false;
		}

		return in_array(ltrim($mToken[1], '\\'), ['DBObjectSearch', 'DBSearch'], true);
	}

	private static function nextSignificant(array $aTokens, int $iFrom): ?int
	{
		$iCount = count($aTokens);
		for ($i = $iFrom; $i < $iCount; $i++) {
			$mToken = $aTokens[$i];
			if (is_array($mToken) && in_array($mToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $i;
		}

		return null;
	}

	private static function lineAt(array $aTokens, int $iIndex): int
	{
		for ($i = $iIndex; $i >= 0; $i--) {
			if (is_array($aTokens[$i])) {
				return $aTokens[$i][2];
			}
		}

		return 0;
	}

	/**
	 * Walks the call's argument list starting just after its opening paren.
	 * Depth 1 is the top level of the arguments; a '.' seen there before the
	 * first top-level comma (end of the first argument) or the matching close
	 * paren (end of the call) is examined.
	 *
	 * Not every such '.' is the signature risk: `"...:".self::ID_PARAMETER`
	 * (src/Service/Read/ObjectQuery.php) concatenates a class constant - a bind
	 * parameter *name*, fixed at compile time - onto the query text, which is
	 * no more dynamic than the string literal beside it. What makes
	 * concatenation dangerous is a variable landing in the query text
	 * unexamined, so only a '.' immediately next to a T_VARIABLE counts.
	 */
	private static function firstArgumentConcatenates(array $aTokens, int $iStart): bool
	{
		$iDepth = 1;
		$iCount = count($aTokens);

		for ($i = $iStart; $i < $iCount; $i++) {
			$mToken = $aTokens[$i];
			$sText = is_array($mToken) ? $mToken[1] : $mToken;

			if ($sText === '(') {
				$iDepth++;
				continue;
			}
			if ($sText === ')') {
				$iDepth--;
				if ($iDepth === 0) {
					return false;
				}
				continue;
			}
			if ($iDepth === 1 && $sText === ',') {
				return false;
			}
			if ($iDepth === 1 && $sText === '.' && self::adjoinsVariable($aTokens, $i)) {
				return true;
			}
		}

		return false;
	}

	private static function adjoinsVariable(array $aTokens, int $iDotIndex): bool
	{
		$iBefore = self::previousSignificant($aTokens, $iDotIndex - 1);
		$iAfter = self::nextSignificant($aTokens, $iDotIndex + 1);

		return self::isVariable($aTokens[$iBefore] ?? null) || self::isVariable($aTokens[$iAfter] ?? null);
	}

	private static function isVariable($mToken): bool
	{
		return is_array($mToken) && $mToken[0] === T_VARIABLE;
	}

	private static function previousSignificant(array $aTokens, int $iFrom): ?int
	{
		for ($i = $iFrom; $i >= 0; $i--) {
			$mToken = $aTokens[$i];
			if (is_array($mToken) && in_array($mToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $i;
		}

		return null;
	}
}
