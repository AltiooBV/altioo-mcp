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
 * Grades an edit of an audit rule that is already watching something.
 *
 * A rule that exists is a control on the caller and not a thing the caller
 * configures: turning it off, making the change it would have flagged and turning
 * it back on leaves nothing for anyone to notice. So an existing rule is refused
 * when the class it audits is one the caller may change, and whenever the class
 * cannot be established. {@see AccessGrants} asks when an operator has allowed
 * privilege escalation.
 *
 * @internal
 * @since 2.0.0
 */
final class DetectionRuleTampering
{
	/**
	 * Whether an existing audit rule watches a class this caller can change.
	 *
	 * The audited class is in the category's definition_set, an OQL: a rule
	 * points at its category, a category names the query. So the question is
	 * asked of whichever of the two is in front of it, and answered by the
	 * class that query selects.
	 *
	 * Refuses whenever it cannot establish the class, which on an update or a
	 * delete is the same stance every other rule here takes - and it means an
	 * AuditDomain, which groups categories rather than naming a query, is not
	 * editable through this endpoint at all. That is the direction to be wrong
	 * in: the cost is a refusal an administrator satisfies from the console,
	 * and the cost the other way is the edit-and-revert loop.
	 *
	 * @param array<string, mixed> $aFields
	 */
	public static function RuleWatchesSomethingTheCallerCanChange(string $sClass, int $iId, array $aFields): ?string
	{
		$sRefusal = sprintf(AccessGrantRefusals::DETECTION_TAMPER_REFUSAL, $sClass);

		if (!class_exists('MetaModel') || !class_exists('UserRights') || !class_exists('DBObjectSearch')) {
			return $sRefusal;
		}

		try {
			$sAudited = self::AuditedClass($sClass, $iId, $aFields);
			if ($sAudited === null) {
				return $sRefusal;
			}

			foreach (['UR_ACTION_MODIFY', 'UR_ACTION_DELETE', 'UR_ACTION_CREATE'] as $sConstant) {
				if (!defined($sConstant)) {
					return $sRefusal;
				}
				if (UserRights::IsActionAllowed($sAudited, constant($sConstant))) {
					return sprintf(AccessGrantRefusals::DETECTION_TAMPER_REFUSAL, $sClass).sprintf(' It watches \'%s\', which you may change.', $sAudited);
				}
			}
		} catch (Throwable) {
			return $sRefusal;
		}

		return null;
	}

	/**
	 * The class an audit rule or category is written about.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function AuditedClass(string $sClass, int $iId, array $aFields): ?string
	{
		$sOql = null;

		if (array_key_exists(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE, $aFields)) {
			$sOql = trim((string) $aFields[AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE]);
		}

		if ($sOql === null || $sOql === '') {
			$oRow = MetaModel::GetObject($sClass, $iId, false, true);
			if ($oRow === null) {
				return null;
			}

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE)) {
				$sOql = trim((string) $oRow->Get(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE));
			} elseif (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::AUDIT_CATEGORY_ATTRIBUTE)) {
				$oCategory = MetaModel::GetObject('AuditCategory', (int) $oRow->Get(AccessGrantRoots::AUDIT_CATEGORY_ATTRIBUTE), false, true);
				$sOql = $oCategory === null ? '' : trim((string) $oCategory->Get(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE));
			}
		}

		if ($sOql === null || $sOql === '') {
			return null;
		}

		$sAudited = trim((string) DBObjectSearch::FromOQL($sOql)->GetClass());

		return $sAudited === '' ? null : $sAudited;
	}
}
