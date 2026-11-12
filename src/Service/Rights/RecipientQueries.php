<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use DBObjectSearch;
use MetaModel;
use Throwable;
use UserRights;

/**
 * Grades the recipient queries of an email action against what the caller may read.
 *
 * An email action's to, cc and bcc are queries the mailer runs with the rights and
 * the silo switched off, so each is a read of a class the caller may or may not be
 * allowed to read: the class it selects, and the one address attribute the mailer
 * would take from it. {@see AccessGrants} asks when it lets an operator override
 * the automation barrier.
 *
 * @internal
 * @since 2.0.0
 */
final class RecipientQueries
{
	/**
	 * Whether any recipient query reaches something this caller may not read.
	 *
	 * Graded per field, on the values being written first and the stored row
	 * second - the same order the rest of this class uses, because changing
	 * one recipient field of an existing action is a write the stored row
	 * still describes the old way.
	 *
	 * A field that is empty, absent or unparseable yields nothing to grade:
	 * empty is no recipients, and OQL that does not parse is refused by
	 * CheckToWrite() before it ever runs. Only a query that resolves to a
	 * class is graded, and it is graded on the two things the mailer actually
	 * reads - the class, and the attribute it would take the address from.
	 *
	 * @param array<string, mixed> $aFields
	 */
	public static function RecipientQueryRefusal(string $sClass, ?int $iId, array $aFields): ?string
	{
		if (!class_exists('MetaModel') || !class_exists('DBObjectSearch')) {
			return null;
		}

		try {
			if (!MetaModel::IsValidClass($sClass)) {
				return null;
			}

			$oRow = null;

			foreach (AccessGrantRoots::RECIPIENT_ATTRIBUTES as $sAttCode) {
				if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
					continue;
				}

				if (array_key_exists($sAttCode, $aFields)) {
					$sOql = trim((string) $aFields[$sAttCode]);
				} else {
					if ($oRow === null && $iId !== null && $iId > 0) {
						$oRow = MetaModel::GetObject($sClass, $iId, false, true);
					}
					$sOql = $oRow === null ? '' : trim((string) $oRow->Get($sAttCode));
				}

				if ($sOql === '') {
					continue;
				}

				$sSelected = trim((string) DBObjectSearch::FromOQL($sOql)->GetClass());
				if ($sSelected === '' || !MetaModel::IsValidClass($sSelected)) {
					continue;
				}

				$sRefusal = self::ReadRefusalForRecipients($sClass, $sAttCode, $sSelected);
				if ($sRefusal !== null) {
					return $sRefusal;
				}
			}
		} catch (Throwable) {
			// An unparseable query is CheckToWrite()'s refusal, not this one's,
			// and it never reaches the mailer.
			return null;
		}

		return null;
	}

	/**
	 * The read grade for one recipient query: the class, and the one address
	 * attribute the mailer would take.
	 */
	private static function ReadRefusalForRecipients(string $sClass, string $sAttCode, string $sSelected): ?string
	{
		if (AccessGrantFamilies::IsBarred($sSelected)) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'this endpoint does not read it');
		}

		if (!class_exists('UserRights') || !defined('UR_ACTION_READ') || !defined('UR_ACTION_BULK_READ')) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'your rights on it could not be established here');
		}

		if (!UserRights::IsActionAllowed($sSelected, UR_ACTION_READ)
			|| !UserRights::IsActionAllowed($sSelected, UR_ACTION_BULK_READ)) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'you may not read it here');
		}

		// The address attribute the mailer picks: the first AttributeEmailAddress
		// on the class, which is how FindRecipients() chooses it.
		$sEmailAttCode = null;
		foreach (MetaModel::ListAttributeDefs($sSelected) as $sCandidate => $oAttDef) {
			if ($oAttDef instanceof \AttributeEmailAddress) {
				$sEmailAttCode = $sCandidate;
				break;
			}
		}

		if ($sEmailAttCode === null) {
			// No address to take: the mailer reports this as a wrong target and
			// sends nothing, so there is nothing here to refuse.
			return null;
		}

		if (defined('UR_ALLOWED_NO')
			&& UserRights::IsActionAllowedOnAttribute($sSelected, $sEmailAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return sprintf(
				AccessGrantRefusals::RECIPIENT_REFUSAL,
				$sClass,
				$sAttCode,
				$sSelected,
				"you may not read '{$sEmailAttCode}' on it here, and that is the attribute the address is taken from"
			);
		}

		return null;
	}
}
