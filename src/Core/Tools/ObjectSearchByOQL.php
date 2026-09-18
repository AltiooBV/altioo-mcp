<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Mcp\Exception\ToolCallException;
use DBObjectSearch;
use DBObjectSet;
use UserRights;

/**
 * Search iTop objects using OQL.
 *
 * Example OQL: SELECT UserRequest WHERE status = 'open'
 *
 * @since 1.0.0
 */
class ObjectSearchByOQL extends AbstractObjectSearch
{
	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves. */
	public function getToolset(): string
	{
		return 'objects';
	}

	protected function defaultTitle(): string
	{
		return 'Search Objects by OQL';
	}

	public function getDescription(): ?string
	{
		return 'Search iTop objects using an OQL query. Returns a list of matching objects with their attributes. Call core_class_list for the class names and core_class_schema for the attribute codes before building a query.';
	}


	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'oql' => [
					'type'                 => 'string',
					'description'          => 'The OQL query to execute. OQL has no ORDER BY clause; sort with order_by instead. '
						.'SELECT on a parent class covers its children - SELECT Ticket answers across UserRequest, Incident and any other subclass - and each row reports the class it actually is.',
				],
			] + self::fieldsSchemaProperties() + self::pagingSchemaProperties() + self::orderingSchemaProperties() + self::archivedSchemaProperty() + self::auditSchemaProperties() + self::actionsSchemaProperties(),
			'required' => ['oql'],
		];
	}

	/**
	 * @param string $oql The OQL query to execute, e.g. 'SELECT UserRequest WHERE status = "open"'
	 * @param int $limit Maximum number of results to return (default: 50, max: 1000)
	 * @param int $offset Number of results to skip for pagination (default: 0)
	 * @param string $order_by Attribute code to sort on; '' for the order the datamodel declares
	 * @param string $order_direction 'asc' or 'desc'
	 * @param string $output_fields Comma-separated attribute codes to return, or '*' for all of them
	 * @param bool $audit Also report when each object was created and last changed, and by whom; limited to a page of ObjectHistory::MAX_AUDIT_PAGE objects
	 * @param bool $actions Also report, per object, the write gates answered for that object and the stimuli its state accepts; same page limit
	 * @param string $archived 'exclude' (default), 'include' or 'only'
	 * @return array An array containing the class, total count, limit, offset, and list of matching objects with their attributes
	 * @throws ToolCallException if the OQL query is invalid, if the class is unknown, or if access is denied.
	 */
	public static function execute(
		string $oql,
		int    $limit = self::DEFAULT_LIMIT,
		int    $offset = self::DEFAULT_OFFSET,
		string $order_by = '',
		string $order_direction = self::DEFAULT_SORT,
		string $output_fields = ObjectSerializer::DEFAULT_LIST_FIELDS,
		bool   $audit = false,
		bool   $actions = false,
		string $archived = self::DEFAULT_ARCHIVED,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException("Invalid limit. Please specify a limit between ".self::MIN_LIMIT." and ".self::MAX_LIMIT.".");
		}
		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException("Invalid offset. Please specify a non-negative offset.");
		}

		self::refuseUnattributablePage($audit, $limit, $actions);

		self::checkQuery($oql);

		$oSearch = DBObjectSearch::FromOQL($oql);
		$class  = $oSearch->GetClass();
		self::applyArchived($oSearch, $class, $archived);

		// The class comes from the query rather than from an argument, which is
		// exactly why this one matters: "SELECT CMDBChangeOpSetAttributeScalar"
		// is the shortest way round every gate core_object_history applies.
		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_BULK_READ)) {
			throw new ToolCallException("Bulk read access denied to class '{$class}'.");
		}

		$aOrderBy = self::orderBy($class, $order_by, $order_direction);
		$aFields = self::fieldsForPage($class, $output_fields, $limit);

		$aResults = [];
		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ, $oSet)) {
			return ToolOutput::Json([
				'class' => $class,
				'oql' => $oql,
				'total'   => 0,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => $aResults, // hides objects that the user shouldn't see
			] + self::pagingFooter(0, $limit, $offset));
		}

		// Execute the search and fetch results
		try {
			$aResults = [];
			while ($oObject = $oSet->Fetch()) {
				$sObjectFinalClass = self::finalClassIfReadable($oObject, $class);
				if ($sObjectFinalClass === null) {
					continue;
				}

				$aResults[] = self::serializeObject($oObject, $sObjectFinalClass, $aFields, $audit, $actions);
			}

			$iTotal = $oSet->Count();

			return ToolOutput::Json([
				'class' => $class,
				'oql'       => $oql,
				'total'     => $iTotal,
				'limit'     => $limit,
				'offset'    => $offset,
				'objects'   => $aResults,
			] + self::pagingFooter($iTotal, $limit, $offset));
		} catch (\OQLException $e) {
			// Building a DBObjectSet runs no query - it assigns and returns -
			// so a malformed filter can only surface here, where the set is
			// first read. Probing for it beforehand caught nothing.
			throw new ToolCallException("Invalid filter condition. ".$e->getMessage());
		} catch (\Throwable $e) {
			// The OQLException above is the one a caller can act on, and it
			// keeps its message. Anything else came out of the query layer.
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to execute the search', $e));
		}
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
	private static function checkQuery(string $oql): void
	{
		require_once(APPROOT.'core/oql/check_oql.php');

		try {
			$oInterpreter = new \OqlInterpreter($oql);
			$oQuery = $oInterpreter->ParseQuery();
			$oQuery->Check(new \ModelReflectionRuntime(), $oql);
		} catch (\OQLException $e) {
			$sReason = method_exists($e, 'GetUserFriendlyDescription')
				? (string)$e->GetUserFriendlyDescription()
				: $e->getMessage();

			if (trim($sReason) === '') {
				$sReason = $e->getMessage();
			}

			throw new ToolCallException('Invalid OQL query. Reason: '.self::withoutEmptySuggestion($sReason).self::orderByHint($oql));
		} catch (\Throwable $e) {
			// Not an OQL problem at all - a datamodel that will not reflect, or
			// something under it. The caller cannot act on that one.
			throw new ToolCallException(MCPHelper::OpaqueFailure('Could not check the OQL query', $e));
		}
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
			.'with order_direction as "'.self::SORT_ASC.'" or "'.self::SORT_DESC.'".';
	}
}
