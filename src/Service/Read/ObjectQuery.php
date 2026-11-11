<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Api\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Rights\ExcludingModelReflection;
use DBObjectSearch;
use Mcp\Exception\ToolCallException;
use MetaModel;

/**
 * The searches the object tools build for themselves.
 *
 * One shape, written once. Every tool that reads, writes, deletes or moves a
 * single object first builds "this class, this id" so that
 * UserRights::IsActionAllowed() can be asked about that object rather than
 * about the class - which is the check that stops a caller learning an object
 * exists by being refused it.
 *
 * @api
 * @since 1.0.0
 */
final class ObjectQuery
{
	/** The name the identifier is bound under. */
	private const ID_PARAMETER = 'id';

	/** The name a list of identifiers is bound under. */
	private const IDS_PARAMETER = 'ids';

	/**
	 * A search for one object, by class and identifier.
	 *
	 * The identifier is bound rather than interpolated into the OQL. Nothing
	 * here is exploitable as it stands - $iId is typed int by the tool
	 * signatures, and the class names come from MetaModel rather than from the
	 * caller - but that is an argument about the current callers, and it has to
	 * be re-made every time one is added. Binding removes the question instead
	 * of answering it, and it is what iTop's own code does.
	 *
	 * The class stays interpolated because OQL names the class in its FROM
	 * clause, where no parameter can go; it is validated with
	 * MetaModel::IsValidClass() before reaching here.
	 *
	 * @since 1.0.0
	 */
	public static function ById(string $sClass, int $iId): DBObjectSearch
	{
		$sKey = MetaModel::DBGetKey($sClass);

		$oSearch = DBObjectSearch::FromOQL(
			"SELECT {$sClass} WHERE {$sKey} = :".self::ID_PARAMETER,
			[self::ID_PARAMETER => $iId]
		);
		self::keepObsolete($oSearch);

		return $oSearch;
	}

	/**
	 * A search for a set of objects of one class, by identifier.
	 *
	 * The IN list is bound as a list, which OQL takes directly - see
	 * cmdbabstract.class.inc.php, which binds ":triggers" the same way. Built
	 * by hand this is an implode() into the query text, which is the one shape
	 * where an unexpected value in the list turns into query syntax.
	 *
	 * @param array<int|string, int|string> $aIds Keys are ignored; the values are the identifiers.
	 * @since 1.0.0
	 */
	public static function ByIds(string $sClass, array $aIds): DBObjectSearch
	{
		$sKey = MetaModel::DBGetKey($sClass);

		$oSearch = DBObjectSearch::FromOQL(
			"SELECT {$sClass} WHERE {$sKey} IN (:".self::IDS_PARAMETER.')',
			[self::IDS_PARAMETER => array_values(array_map('intval', $aIds))]
		);
		self::keepObsolete($oSearch);

		return $oSearch;
	}

	/**
	 * A read by id answers about the object that was named, obsolete or not.
	 *
	 * The searches honour the account's "show obsolete data" preference, which
	 * is what the console does and what makes a result set mean the same thing
	 * in both places. A lookup by id is a different question: the caller has
	 * the identifier in hand, usually because it just wrote the object, and
	 * "not found" for a row that exists is the wrong answer to it. The case
	 * that makes this concrete is a status the datamodel counts as obsolete -
	 * the write succeeds and the object disappears from the writer's own view.
	 *
	 * Set explicitly rather than left to DBSearch's default, which is true
	 * today: a guarantee this module makes should not rest on a default it
	 * does not own.
	 *
	 * Archived objects are deliberately not covered. Archiving is soft
	 * deletion asked for on purpose, the searches expose it through their own
	 * argument, and widening a by-id read to it would be a decision about
	 * deleted data rather than about a computed condition.
	 */
	private static function keepObsolete(DBObjectSearch $oSearch): void
	{
		$oSearch->SetShowObsoleteData(true);
	}

	/**
	 * Parses the query, and refuses it the way iTop would explain it to a
	 * person.
	 *
	 * CheckOQL() does the same two steps and then throws the exception away,
	 * keeping only getMessage() - and for an unknown class that message ends in
	 * every class the instance has. A single typo answered with an alphabetical
	 * list of a few hundred class names is an expensive way to say "no", and it
	 * says nothing about which one was meant.
	 *
	 * iTop already knows: UnknownClassOqlException::GetUserFriendlyDescription()
	 * runs FindClosestString() over the candidates and answers "did you mean X",
	 * and the candidate list it searches has already been filtered by read
	 * rights in the constructor - so the suggestion cannot name a class this
	 * caller may not see. Keeping the exception rather than its message is all
	 * that was needed to reach it.
	 *
	 * Any other OQL error keeps getMessage(), which is where the position and
	 * the offending token live, and where a caller fixes its own query from.
	 *
	 * @throws ToolCallException When the query does not parse or does not match the datamodel.
	 */
	public static function CheckQuery(string $oql): void
	{
		require_once(APPROOT.'core/oql/check_oql.php');

		try {
			$oInterpreter = new \OqlInterpreter($oql);
			$oQuery = $oInterpreter->ParseQuery();
			// Against the datamodel as this channel serves it (doc/adr/0012): a class or an attribute the
			// operator excluded is unknown to the checker, so the query is refused in its own words, in
			// a join, a sub-query or a condition alike - and a query it cannot prove is refused.
			$oQuery->Check(new ExcludingModelReflection(), $oql);
		} catch (\OQLException $e) {
			$sReason = method_exists($e, 'GetUserFriendlyDescription')
				? (string)$e->GetUserFriendlyDescription()
				: $e->getMessage();

			if (trim($sReason) === '') {
				$sReason = $e->getMessage();
			}

			throw new ToolCallException('Invalid OQL query. Reason: '.self::withoutEmptySuggestion($sReason).self::orderByHint($oql));
		} catch (\Throwable $e) {
			// An unknown class inside a sub-query reaches iTop's query builder, which refuses it with a
			// plain exception. It is the same mistake as one the checker names, and is told the same way:
			// otherwise the class the operator excluded - which the checker names cleanly - would be
			// told apart from one that does not exist (doc/adr/0012).
			$sUnknown = self::invalidClassIn($e);
			if ($sUnknown !== null) {
				throw new ToolCallException('Invalid OQL query. Reason: Unknown class "'.$sUnknown.'"');
			}
			// Not an OQL problem at all - a datamodel that will not reflect, or
			// something under it. The caller cannot act on that one.
			throw new ToolCallException(ErrorReport::OpaqueFailure('Could not check the OQL query', $e));
		}
	}

	/**
	 * The class a query builder refused as invalid, when that is why it threw: the class name only, which
	 * the caller typed, and nothing else of what was thrown.
	 */
	private static function invalidClassIn(\Throwable $e): ?string
	{
		return preg_match('/called for an invalid class: "([A-Za-z_][A-Za-z0-9_]*)"/', $e->getMessage(), $aMatch) === 1 ? $aMatch[1] : null;
	}

	/**
	 * Drops iTop's suggestion clause when it suggests nothing.
	 *
	 * OQLException builds its message with ", I would suggest to use
	 * '$sSuggest'" appended whenever the parser had any expectations at all -
	 * and FindClosestString() answers '' when none of them is close, so a
	 * refusal can end in "use ''". iTop's own HTML renderer guards that clause
	 * on the suggestion being non-empty; the plain message it hands an API
	 * does not.
	 *
	 * Removed rather than rewritten: everything before it is the useful half -
	 * what was found, where, and what was expected - and a caller reading a
	 * trailing fragment of a sentence learns nothing except that something
	 * upstream was not finished.
	 *
	 * Matched literally, because that string is not translated: the class's
	 * own GetUserFriendlyDescription() returns getMessage() with a "Todo -
	 * translate all errors" beside it. A message that does not match is
	 * returned untouched.
	 */
	private static function withoutEmptySuggestion(string $sReason): string
	{
		return trim(str_replace(", I would suggest to use ''", '', $sReason));
	}

	/**
	 * The sentence a parser error about ORDER BY is missing.
	 *
	 * OQL has no ORDER BY, and writing one is the mistake a model arrives
	 * with - every other query language it knows has the clause. What comes
	 * back is the parser's own complaint about an unexpected token, which says
	 * nothing about the two parameters this tool has for exactly that, and the
	 * server instructions say to use them only to a caller that read them.
	 *
	 * Appended to the refusal rather than replacing it: the parser message
	 * names the position, which is worth keeping when the query has a second
	 * problem as well.
	 *
	 * Matched on the query text, not on the message, because the message is
	 * iTop's and moves. A query that carries the words inside a literal and
	 * fails for some other reason gets one sentence of advice it did not need,
	 * which is the cheap side of the trade.
	 */
	private static function orderByHint(string $sOql): string
	{
		if (preg_match('/\border\s+by\b/i', $sOql) !== 1) {
			return '';
		}

		// The direction values come from the constants the enum is built from,
		// so advice and schema cannot name different strings.
		return ' OQL has no ORDER BY clause: remove it and pass the attribute to order_by, '
			.'with order_direction as "'.AbstractObjectSearch::SORT_ASC.'" or "'.AbstractObjectSearch::SORT_DESC.'".';
	}
}
