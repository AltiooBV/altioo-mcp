<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use AttributeDateTime;
use AttributeDefinition;
use AttributeLinkedSet;
use AttributeSubItem;
use MetaModel;
use ormLinkSet;

/**
 * One attribute's value as iTop stores it, which is what OQL compares and a write accepts.
 *
 * AttributeDefinition::GetForJSON() is GetEditValue(), the value as a person types it into a
 * form, and for two kinds that is not the stored value. A date or date-time comes out in the
 * display format, which an operator configures per language (`date_and_time_format`): with
 * `d/m/Y`, `start_date` read "25/11/2026 12:54", contradicting the pattern the schema gives and
 * refused when written back. A stopwatch's sub-items come out as display dates and translated
 * labels: `sla_tto_passed` read "yes" (or "oui"), and `sla_tto_passed = 'yes'` in OQL - the
 * comparison a model makes with the value it was handed - matched every ticket that had NOT
 * breached, because the column holds 0 and 1. Every other kind is left to GetForJSON().
 *
 * @internal
 * @since 2.2.0
 */
final class StoredValue
{
	/** A threshold's items: `<percent>_deadline`, `_passed`, `_triggered`, `_overrun`. */
	private const THRESHOLD_ITEM = '/^\d+_(deadline|passed|triggered|overrun)$/';

	/**
	 * @param mixed $value As DBObject::Get() returns it.
	 *
	 * @psalm-suppress TypeDoesNotContainType Psalm runs without iTop and cannot see its attribute hierarchy.
	 */
	public static function Of(AttributeDefinition $oAttDef, mixed $value): mixed
	{
		if ($oAttDef instanceof AttributeSubItem) {
			return self::OfSubItem((string)$oAttDef->Get('item_code'), $value);
		}

		if ($oAttDef instanceof AttributeDateTime) {
			// AttributeDate is a subclass. An unset date stays '', as GetForJSON() gave it.
			// The zero dates are iTop's own "unset" (DateTimeFormat::Format()).
			$sValue = (string)$value;

			return in_array($sValue, ['0000-00-00', '0000-00-00 00:00:00'], true) ? '' : $sValue;
		}

		if ($oAttDef instanceof AttributeLinkedSet) {
			// Which rows a caller is shown is LinkedRows' question, asked in ObjectSerializer::Value() before OfLinkSet().
			throw new \LogicException('A link set is rendered by OfLinkSet(), after the rows shown are asked.');
		}

		return $oAttDef->GetForJSON($value);
	}

	/**
	 * A stopwatch sub-item, from the raw value AttributeStopWatch::GetSubItemValue() returns.
	 *
	 * Dates are Unix timestamps there; they are given in the internal date-time format. `passed`
	 * and `triggered` are given as 0 or 1, `overrun` and `timespent` as seconds.
	 */
	public static function OfSubItem(string $sItemCode, mixed $value): mixed
	{
		switch (self::kind($sItemCode)) {
			case 'started':
			case 'laststart':
			case 'stopped':
			case 'deadline':
				return (is_numeric($value) && (int)$value > 0)
					? date(AttributeDateTime::GetInternalFormat(), (int)$value)
					: '';

			case 'passed':
			case 'triggered':
				return ($value === null || $value === '') ? null : ((bool)$value ? 1 : 0);

			case 'overrun':
			case 'timespent':
				return is_numeric($value) ? (int)$value : null;
		}

		return $value;
	}

	/**
	 * A value the change log recorded for $oAttDef, as Of() gives the attribute's value.
	 *
	 * The log holds what was stored, as text, so only a stopwatch's dates differ: they are Unix
	 * timestamps there ("1795608575"), and in the internal date-time format everywhere else. The
	 * rest is returned as recorded; null stays null, since a history entry's null means the log
	 * has no value, not an unset one.
	 */
	public static function OfRecorded(AttributeDefinition $oAttDef, ?string $sValue): ?string
	{
		if ($sValue === null || !ctype_digit($sValue) || !self::IsDateSubItem($oAttDef)) {
			return $sValue;
		}

		return (string)self::OfSubItem('deadline', $sValue);
	}

	/**
	 * Whether $oAttDef is a stopwatch item Of() gives as a date-time: a start, a stop or a deadline.
	 *
	 * @psalm-suppress RedundantCondition Psalm runs without iTop and cannot see its attribute hierarchy.
	 */
	public static function IsDateSubItem(AttributeDefinition $oAttDef): bool
	{
		if (!$oAttDef instanceof AttributeSubItem) {
			return false;
		}

		return in_array(self::kind((string)$oAttDef->Get('item_code')), ['started', 'laststart', 'stopped', 'deadline'], true);
	}

	/** An item code without its threshold: `100_deadline` is a `deadline`, `started` is itself. */
	private static function kind(string $sItemCode): string
	{
		return preg_match(self::THRESHOLD_ITEM, $sItemCode, $aMatch) === 1 ? $aMatch[1] : $sItemCode;
	}

	/**
	 * AttributeLinkedSet::GetForJSON(), with each column through Of(): iTop's loop converts the
	 * columns with GetForJSON(), so a date on a link row came out in the display format too.
	 *
	 * Every row of the set. Called by ObjectSerializer::Value() alone, after LinkedRows::Of() has
	 * said the datamodel's rows are the ones shown (LinkedRowsContractTest).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function OfLinkSet(AttributeLinkedSet $oAttDef, mixed $value): array
	{
		$aRows = [];
		if (!$value instanceof ormLinkSet) {
			return $aRows;
		}

		$value->Rewind();
		while ($oLink = $value->Fetch()) {
			$sLinkClass = get_class($oLink);
			$aRow = [];
			foreach (MetaModel::ListAttributeDefs($sLinkClass) as $sCode => $oLinkAttDef) {
				// The same columns iTop's loop keeps.
				if ($sCode === 'finalclass' && $sLinkClass === $oAttDef->GetLinkedClass()) {
					continue;
				}
				if ($sCode === $oAttDef->GetExtKeyToMe() || $oLinkAttDef->IsExternalField()
					|| !$oLinkAttDef->IsBasedOnDBColumns() || !$oLinkAttDef->IsScalar()) {
					continue;
				}
				$aRow[$sCode] = self::Of($oLinkAttDef, $oLink->Get($sCode));
			}
			$aRows[] = $aRow;
		}

		return $aRows;
	}
}
