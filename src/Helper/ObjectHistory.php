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
 * The second is rights, and it is the one that matters. CMDBChangeOp is
 * granted per profile, as a class - the grant says nothing about the object
 * the row points at, nor about the attribute it names. objkey is an integer
 * column, so a caller holding that grant can read the history of objects its
 * silo hides and the former values of attributes it may not read today. Both
 * gates are therefore applied here, against the object in hand and against
 * today's rights rather than the rights in force when the row was written.
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
	 * Whether this caller may read the change log at all.
	 *
	 * Asked before the object is looked at, so that an instance which grants
	 * nobody the log answers the same way for an object that exists and one
	 * that does not.
	 *
	 * @since 1.0.0
	 */
	public static function IsReadable(): bool
	{
		return MetaModel::IsValidClass(self::HISTORY_CLASS)
			&& UserRights::IsActionAllowed(self::HISTORY_CLASS, UR_ACTION_READ) !== UR_ALLOWED_NO;
	}

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
	 * Absent entirely, rather than null, when this caller may not read the log
	 * at all: who last touched an object is exactly what the grant on
	 * CMDBChangeOp decides, and a read is not the place to hand it out anyway.
	 *
	 * Note for an operator: this is gated by that grant, not by the history
	 * toolset. Withholding the toolset withholds core_object_history, which is
	 * the surface; withholding attribution everywhere is a profile decision on
	 * CMDBChangeOp.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.0.0
	 */
	public static function AttributionFor(DBObject $oObject): ?array
	{
		if (!self::IsReadable()) {
			return null;
		}

		return [
			'created'      => self::firstRow($oObject, 'CMDBChangeOpCreate', true),
			'last_updated' => self::firstRow($oObject, self::HISTORY_CLASS, false),
		];
	}

	/**
	 * The oldest or newest recorded operation on one object, as who and when.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function firstRow(DBObject $oObject, string $sOpClass, bool $bOldest): ?array
	{
		if (!MetaModel::IsValidClass($sOpClass)) {
			return null;
		}

		$oSearch = new DBObjectSearch($sOpClass);
		$oSearch->AddCondition('objclass', get_class($oObject), '=');
		$oSearch->AddCondition('objkey', (int)$oObject->GetKey(), '=');

		$oSet = new DBObjectSet($oSearch, ['date' => $bOldest], [], null, 1, 0);
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

		$oSet = new DBObjectSet($oSearch, ['date' => false], [], null, $iLimit, $iOffset);
		$iTotal = $oSet->Count();

		$aEntries = [];
		$oInstanceSet = null;
		while ($oOp = $oSet->Fetch()) {
			$aEntry = self::describe($oOp);
			$sOn = $aEntry['attribute'];
			if ($sOn !== null && !ObjectSerializer::MayReadAttribute($oObject, $sClass, $sOn, $oInstanceSet)) {
				continue;
			}

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
