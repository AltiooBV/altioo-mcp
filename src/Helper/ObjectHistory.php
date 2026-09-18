<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use DBObject;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

/**
 * What iTop recorded happening to an object, read as this caller may read it.
 *
 * iTop keeps no creation or update stamp on an object: DBObject declares
 * neither, so the only record that a ticket was opened on Tuesday by whom is
 * the change log. CMDBChangeOp carries objclass and objkey with an index on
 * the pair, and pulls date, userinfo and user_id through its change key as
 * external fields - so who and when are columns on the row and no join has to
 * be written by hand.
 *
 * Two things make this a helper rather than an OQL query a model writes.
 *
 * The first is that the log does not normalise itself. oldvalue and newvalue
 * live on CMDBChangeOpSetAttributeScalar; text, long text, HTML, case logs,
 * blobs and link sets each record differently or record only that they
 * changed. A model querying the parent class gets rows with no values, and one
 * querying the scalar subclass silently misses every case-log entry - which on
 * a ticket is most of what anyone wants to see. Every row is reported here,
 * with the values where the subclass has them and the operation named where it
 * does not, so "nothing else changed" and "this surface cannot show what
 * changed" stay different answers.
 *
 * The second is rights. objkey is an integer column and the rows say nothing
 * about the silo the object sits in, so reaching them directly is a way round
 * both. The gate is the object, which is how the console gates it -
 * ActivityPanelHelper reads these rows for whatever object is on screen and
 * asks UserRights nothing about CMDBChangeOp - and on top of that, a row
 * naming an attribute this caller may not read is dropped. That last part is
 * stricter than the UI on purpose: the console renders a page to a person who
 * is already looking at the object, while this hands values to a model.
 *
 * Today's rights, never the rights in force when the row was written. A
 * revocation that left the old value readable would be a revocation in name
 * only.
 *
 * @api
 * @since 1.0.0
 */
final class ObjectHistory
{
	/** The log itself, which a profile grants or does not. */
	public const HISTORY_CLASS = 'CMDBChangeOp';

	/** The subclass that carries attcode, and the one to query when asked about a single attribute. */
	private const ATTRIBUTE_CLASS = 'CMDBChangeOpSetAttribute';

	/**
	 * The one operation whose value is not in the change log.
	 *
	 * It declares lastentry - an integer - and nothing else of its own, so the
	 * text of a work note is not recorded here at all. It is on the object.
	 */
	private const CASELOG_CLASS = 'CMDBChangeOpSetAttributeCaseLog';

	public const DEFAULT_LIMIT = 50;

	public const MAX_LIMIT = 500;

	/**
	 * How many objects one page may ask attribution for.
	 *
	 * Attribution is two indexed single-row reads per object - the creation
	 * row, and the newest row of any kind. Each is cheap; the number of them
	 * is what has to be bounded, so a page that asks for it is refused rather
	 * than served slowly. A caller that wants attribution for more objects
	 * pages through them.
	 */
	public const MAX_AUDIT_PAGE = 25;

	/**
	 * How much of a recorded value is reported.
	 *
	 * oldvalue and newvalue are plain strings holding whatever the attribute
	 * held, and a long text attribute puts its whole previous body in one. The
	 * ceiling is ObjectSerializer's, for the same reason it has one.
	 */
	public const MAX_VALUE_CHARS = ObjectSerializer::MAX_TEXT_CHARS;

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

	/**
	 * How the console orders the same rows, and why.
	 *
	 * Not by date. iTop writes several CMDBChangeOp rows for one change, all
	 * carrying that change's timestamp, so a date sort leaves their order to
	 * the database - and the ActivityPanel says so where it does the same
	 * query: ordering by the id is "way much simpler and less DB CPU
	 * consuming" and it is the only one that separates rows written together.
	 */
	private const NEWEST_FIRST = ['id' => false];

	private const OLDEST_FIRST = ['id' => true];

	/**
	 * When the object was created and when it was last touched, with the user
	 * behind each.
	 *
	 * iTop stamps neither on the object, so both are read from the log: the
	 * CMDBChangeOpCreate row for the first, and the newest row of any kind for
	 * the second. Two single-row reads on the (objclass, objkey) index.
	 *
	 * Either half is null when the log has no row for it, which is a real
	 * state rather than an error - an object loaded before change tracking
	 * covered it, or created by a data load, has no creation row, and an
	 * object never modified has no operation newer than its creation.
	 *
	 * Gated by the object and by nothing else, which is how the console gates
	 * it: ActivityPanelHelper reads these rows for whatever object is on
	 * screen and asks UserRights nothing about CMDBChangeOp. A class grant
	 * checked here would make this stricter than the UI it mirrors - an
	 * instance where the ticket is visible and its "last updated by" is not.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function AttributionFor(DBObject $oObject): array
	{
		return [
			'created'      => self::firstRow($oObject, 'CMDBChangeOpCreate', self::OLDEST_FIRST),
			'last_updated' => self::firstRow($oObject, self::HISTORY_CLASS, self::NEWEST_FIRST),
		];
	}

	/**
	 * The oldest or newest recorded operation on one object, as who and when.
	 *
	 * @param array<string, bool> $aOrder self::OLDEST_FIRST or self::NEWEST_FIRST.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function firstRow(DBObject $oObject, string $sOpClass, array $aOrder): ?array
	{
		if (!MetaModel::IsValidClass($sOpClass)) {
			return null;
		}

		$oSearch = new DBObjectSearch($sOpClass);
		$oSearch->AddCondition('objclass', get_class($oObject), '=');
		$oSearch->AddCondition('objkey', (int)$oObject->GetKey(), '=');

		$oSet = new DBObjectSet($oSearch, $aOrder, [], null, 1, 0);
		$oOp = $oSet->Fetch();
		if ($oOp === null) {
			return null;
		}

		return [
			'when'    => $oOp->Get('date'),
			'who'     => $oOp->Get('userinfo'),
			'user_id' => $oOp->Get('user_id'),
		];
	}

	/**
	 * The recorded operations on one object, newest first.
	 *
	 * $oObject is the object as the caller was already allowed to read it -
	 * the object gate belongs to the tool, which is where the refusal has to
	 * be phrased. What is applied here is the attribute gate: a row naming an
	 * attribute this caller may not read is dropped whole, because which
	 * attribute changed and when is itself something the grant did not give.
	 *
	 * objclass is matched against the object's own class rather than the one
	 * the caller asked about. iTop writes get_class($this) into it, so a leaf
	 * object's rows are filed under the leaf and a query by the parent's name
	 * matches none of them.
	 *
	 * @param string $sAttCode Restrict to one attribute, or '' for every operation.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function For(DBObject $oObject, string $sAttCode = '', int $iLimit = self::DEFAULT_LIMIT, int $iOffset = 0): array
	{
		$sClass = get_class($oObject);
		$iKey = (int)$oObject->GetKey();

		$sQueried = $sAttCode === '' ? self::HISTORY_CLASS : self::ATTRIBUTE_CLASS;
		$oSearch = new DBObjectSearch($sQueried);
		$oSearch->AddCondition('objclass', $sClass, '=');
		$oSearch->AddCondition('objkey', $iKey, '=');
		if ($sAttCode !== '') {
			$oSearch->AddCondition('attcode', $sAttCode, '=');
		}

		$oSet = new DBObjectSet($oSearch, self::NEWEST_FIRST, [], null, $iLimit, $iOffset);
		$iTotal = $oSet->Count();

		$aEntries = [];
		$oInstanceSet = null;
		$aCaseLogs = [];
		while ($oOp = $oSet->Fetch()) {
			$aEntry = self::describe($oOp);
			$sOn = $aEntry['attribute'];
			if ($sOn !== null && !ObjectSerializer::MayReadAttribute($oObject, $sClass, $sOn, $oInstanceSet)) {
				continue;
			}

			$aEntry = self::masked($aEntry, $sClass);
			$aEntry = self::withCaseLogEntry($aEntry, $oObject, $sClass, $aCaseLogs);

			$aEntries[] = $aEntry;
		}

		return [
			'class'     => $sClass,
			'id'        => $iKey,
			'att_code'  => $sAttCode,
			'limit'     => $iLimit,
			'offset'    => $iOffset,
			// What the query matched. 'entries' can be shorter: a row naming an
			// attribute this caller may not read is dropped after counting, the
			// same way ObjectSerializer drops an unreadable attribute from a read.
			'total'     => $iTotal,
			'entries'   => $aEntries,
		];
	}

	/**
	 * A secret does not come back through the change log either.
	 *
	 * A read masks an attribute whose type iTop marks secret - the password and
	 * encrypted types implement iAttributeNoGroupBy, and ObjectSerializer
	 * returns the mask before any conversion. The change log is a second copy
	 * of the same values and was not masked: AttributeDefinition records
	 * oldvalue and newvalue generically, and AttributePassword does not
	 * override GetChangeRecordAdditionalData(), so changing an OAuth client's
	 * secret writes the old one and the new one into a CMDBChangeOpSetAttribute
	 * row. This tool then read them back for anyone allowed the attribute.
	 *
	 * Rights were checked and sensitivity was not, which is the gap: those are
	 * different questions, and iTop answers the second by type rather than by
	 * profile. SECURITY.md is explicit that a secret in a tracked attribute is
	 * a secret in five more places; this is one of the five.
	 *
	 * The row is kept, so the history still says the attribute changed, when,
	 * and by whom - which is the part an auditor needs and the part that
	 * discloses nothing.
	 *
	 * @param array<string, mixed> $aEntry
	 *
	 * @return array<string, mixed>
	 */
	private static function masked(array $aEntry, string $sClass): array
	{
		$sAttCode = (string)($aEntry['attribute'] ?? '');
		if ($sAttCode === '') {
			return $aEntry;
		}

		try {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				// An attribute the datamodel no longer declares: nothing can
				// say whether it was a secret, so it is treated as one.
				$aEntry['from'] = $aEntry['from'] === null ? null : ObjectSerializer::MASK;
				$aEntry['to'] = $aEntry['to'] === null ? null : ObjectSerializer::MASK;

				return $aEntry;
			}

			if (!ObjectSerializer::IsSensitive(MetaModel::GetAttributeDef($sClass, $sAttCode))) {
				return $aEntry;
			}
		} catch (\Throwable $e) {
			MCPHelper::LogError('Could not grade '.$sClass.'::'.$sAttCode.' for the history: '.$e->getMessage());
		}

		$aEntry['from'] = $aEntry['from'] === null ? null : ObjectSerializer::MASK;
		$aEntry['to'] = $aEntry['to'] === null ? null : ObjectSerializer::MASK;

		return $aEntry;
	}

	/**
	 * The text of a case-log entry, which the change log does not hold.
	 *
	 * CMDBChangeOpSetAttributeCaseLog declares one field of its own -
	 * lastentry, an integer - and no oldvalue or newvalue, so a row about a
	 * work note said who wrote one and when and nothing about what it said.
	 * That is not this module reading the wrong column: iTop never writes the
	 * text there. It lives in the object's own case log, which is why the
	 * console renders those entries from the object rather than from the
	 * history.
	 *
	 * So it is read from the object, which the caller has already been
	 * gated on: this runs after MayReadAttribute() has allowed the attribute,
	 * and the entry is part of that same attribute's value. One read of the
	 * case log per attribute, kept for the rest of the page.
	 *
	 * Matched on the date and the user rather than on lastentry. The index is
	 * an offset into a log that later entries push along, and an entry edited
	 * or removed leaves it pointing at somebody else's words - a wrong
	 * attribution being much worse here than a missing one, since this is the
	 * tab an auditor reads. No match answers null, as before.
	 *
	 * @param array<string, mixed>                       $aEntry
	 * @param array<string, array<int, array<string, mixed>>> $aCaseLogs Read once per attribute, by reference.
	 *
	 * @return array<string, mixed>
	 */
	private static function withCaseLogEntry(array $aEntry, DBObject $oObject, string $sClass, array &$aCaseLogs): array
	{
		if ($aEntry['operation'] !== self::CASELOG_CLASS || $aEntry['to'] !== null) {
			return $aEntry;
		}

		$sAttCode = (string)$aEntry['attribute'];
		if ($sAttCode === '') {
			return $aEntry;
		}

		try {
			if (!array_key_exists($sAttCode, $aCaseLogs)) {
				$oLog = $oObject->Get($sAttCode);
				$aCaseLogs[$sAttCode] = is_object($oLog) && method_exists($oLog, 'GetAsArray')
					? $oLog->GetAsArray()
					: [];
			}

			foreach ($aCaseLogs[$sAttCode] as $aLogEntry) {
				if (($aLogEntry['date'] ?? null) !== $aEntry['when']) {
					continue;
				}

				$mUser = $aLogEntry['user_id'] ?? null;
				if ($mUser !== null && $aEntry['user_id'] !== null && (int)$mUser !== (int)$aEntry['user_id']) {
					continue;
				}

				$sMessage = (string)($aLogEntry['message'] ?? '');
				$aEntry['to'] = mb_strlen($sMessage) > self::MAX_VALUE_CHARS
					? mb_substr($sMessage, 0, self::MAX_VALUE_CHARS).'…'
					: $sMessage;

				return $aEntry;
			}
		} catch (\Throwable $e) {
			// The row is worth reporting without its text; the history is not
			// worth losing over one entry that will not render.
			MCPHelper::LogError('Could not read a case log entry for '.$sClass.'::'.$sAttCode.': '.$e->getMessage());
		}

		return $aEntry;
	}

	/**
	 * One recorded operation, flattened.
	 *
	 * The operation is named from its own class in every case, so a row this
	 * code has no special knowledge of still reports what it was. Values are
	 * read only where the subclass declares them, which is asked of the
	 * datamodel rather than from a list written here - the list of subclasses
	 * is iTop's to extend, and a pack that adds one should not turn into a row
	 * reported as an empty change.
	 *
	 * @return array<string, mixed>
	 */
	private static function describe(DBObject $oOp): array
	{
		$sOpClass = get_class($oOp);

		return [
			'when'      => $oOp->Get('date'),
			'who'       => $oOp->Get('userinfo'),
			'user_id'   => $oOp->Get('user_id'),
			'operation' => $sOpClass,
			'attribute' => self::valueOf($oOp, $sOpClass, 'attcode'),
			'from'      => self::valueOf($oOp, $sOpClass, 'oldvalue'),
			'to'        => self::valueOf($oOp, $sOpClass, 'newvalue'),
		];
	}

	/**
	 * One field of an operation, where its subclass has one.
	 *
	 * Asking IsValidAttCode() rather than catching: reading an attribute a
	 * class does not declare is a coding error everywhere else in iTop, and
	 * turning it into a caught exception here would hide a renamed field
	 * behind a row that simply reports nothing.
	 */
	private static function valueOf(DBObject $oOp, string $sOpClass, string $sAttCode): ?string
	{
		if (!MetaModel::IsValidAttCode($sOpClass, $sAttCode)) {
			return null;
		}

		$sValue = $oOp->Get($sAttCode);
		if ($sValue === null || $sValue === '') {
			return $sValue === null ? null : '';
		}

		$sValue = (string)$sValue;

		return mb_strlen($sValue) > self::MAX_VALUE_CHARS
			? mb_substr($sValue, 0, self::MAX_VALUE_CHARS).'…'
			: $sValue;
	}
}
