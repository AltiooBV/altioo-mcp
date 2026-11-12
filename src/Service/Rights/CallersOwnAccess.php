<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use DBObject;
use MetaModel;
use Throwable;
use UserRights;

/**
 * Whether a write reaches the access the caller is connected with, or a row
 * that is somebody else's.
 *
 * The two questions {@see AccessGrants} asks about a row rather than a class:
 * does this write touch the account, the token or the profiles the caller
 * holds, and is this personal row the caller's own. Both fail closed - when the
 * answer cannot be established, the row is treated as the caller's in the first
 * case and as somebody else's in the second, and both land on a refusal.
 *
 * @internal
 * @since 2.0.0
 */
final class CallersOwnAccess
{
	/**
	 * Whether a personal row is the caller's own.
	 *
	 * The mirror of {@see ReachesTheCaller()}, and it fails closed in the
	 * opposite direction for the same reason: there, an undecidable answer
	 * means "this is yours" and refuses; here it means "this is not yours" and
	 * refuses. Both land on a refusal, which is the only thing the two rules
	 * have to agree about.
	 *
	 * A write that names no owner is the caller's own. That is not a guess:
	 * iTop's own appUserPreferences::SetPref() writes the row of the account
	 * it is called by, and core_set_obsolete_data goes through exactly that -
	 * so a call with no id and no userid is the tool this module ships, not a
	 * caller reaching for somebody else.
	 *
	 * @param array<string, mixed> $aFields
	 */
	public static function IsTheCallersOwnRow(string $sClass, ?int $iId, array $aFields): bool
	{
		$sOwnerAttCode = null;
		$bNamesAnOwner = false;
		foreach (AccessGrantRoots::OWNER_ATTRIBUTES as $sCandidate) {
			if (array_key_exists($sCandidate, $aFields)) {
				$sOwnerAttCode = $sCandidate;
				$bNamesAnOwner = true;
				break;
			}
		}

		if ($iId === null && !$bNamesAnOwner) {
			return true;
		}

		if (!class_exists('UserRights')) {
			return false;
		}

		try {
			$iCaller = (int) UserRights::GetUserId();
			if ($iCaller < 1) {
				return false;
			}

			// The values first, then the stored row: re-pointing your own
			// preference row at somebody else is a write the stored row still
			// describes as yours.
			if ($bNamesAnOwner) {
				return (int) $aFields[$sOwnerAttCode] === $iCaller;
			}

			if (!class_exists('MetaModel') || !MetaModel::IsValidClass($sClass)) {
				return false;
			}

			// Which spelling this class uses, asked of the datamodel rather
			// than guessed - a class declaring neither is one whose owner
			// cannot be established, which is a refusal.
			foreach (AccessGrantRoots::OWNER_ATTRIBUTES as $sCandidate) {
				if (MetaModel::IsValidAttCode($sClass, $sCandidate)) {
					$sOwnerAttCode = $sCandidate;
					break;
				}
			}

			if ($sOwnerAttCode === null) {
				return false;
			}

			$oRow = MetaModel::GetObject($sClass, (int) $iId, false, true);

			return $oRow !== null && (int) $oRow->Get($sOwnerAttCode) === $iCaller;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Whether this write reaches the access the caller is connected with.
	 *
	 * Asked of the row, not of the tool: the same escalation is a create, an
	 * update or a delete depending on which one is cheapest, and minting a
	 * second token is easier than editing the one in hand.
	 *
	 * Refuses whenever it cannot tell. No login, no MetaModel, an object that
	 * will not load, a create that names no owner - iTop's own controller
	 * fills the owner in with the current user, so a token created naming
	 * nobody is a token for the caller. Every one of those is answered "yes,
	 * this is yours", because the cost of being wrong the other way is the
	 * escalation this exists to prevent and the cost of being wrong this way
	 * is a refusal an administrator can satisfy from the console.
	 *
	 * @param array<string, mixed> $aFields
	 */
	public static function ReachesTheCaller(string $sClass, ?int $iId, array $aFields): bool
	{
		if (!class_exists('MetaModel') || !class_exists('UserRights')) {
			return true;
		}

		try {
			$iCaller = (int)UserRights::GetUserId();
			if ($iCaller < 1) {
				return true;
			}

			// The account itself. A create is never the caller: nobody makes
			// the user they are already logged in as.
			if (self::Is($sClass, 'User')) {
				return $iId !== null && $iId === $iCaller;
			}

			if (!MetaModel::IsValidClass($sClass)) {
				return true;
			}

			$oObject = ($iId !== null && $iId > 0)
				? MetaModel::GetObject($sClass, $iId, false, true)
				: null;

			// Whose row is it. user_id on the token classes, userid on the
			// rights links; both are asked of the values being written first,
			// since re-pointing someone else's token at yourself is a write
			// that the stored row still describes as theirs.
			foreach (['user_id', 'userid'] as $sAttCode) {
				if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
					continue;
				}
				if (array_key_exists($sAttCode, $aFields)) {
					if ((int)$aFields[$sAttCode] === $iCaller) {
						return true;
					}
					continue;
				}
				if ($oObject === null) {
					return true; // a create naming no owner is a create for the caller
				}
				if ((int)$oObject->Get($sAttCode) === $iCaller) {
					return true;
				}
			}

			$sProfile = self::ProfileOf($sClass, $oObject, $aFields);

			return $sProfile !== null && in_array($sProfile, UserRights::ListProfiles(), true);
		} catch (Throwable) {
			return true;
		}
	}

	/**
	 * The profile a row decides something about, by name, or null when it
	 * decides nothing about any profile.
	 *
	 * Names rather than ids, because that is the currency
	 * UserRights::ListProfiles() answers in. URP_AttributeGrant is the one
	 * that needs a hop: it names an action grant and nothing else, and the
	 * profile is on the grant.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function ProfileOf(string $sClass, ?DBObject $oObject, array $aFields): ?string
	{
		if (self::Is($sClass, 'URP_Profiles')) {
			if (array_key_exists('name', $aFields)) {
				return (string)$aFields['name'];
			}

			return $oObject !== null ? (string)$oObject->Get('name') : null;
		}

		if (MetaModel::IsValidAttCode($sClass, 'profileid')) {
			$iProfile = array_key_exists('profileid', $aFields)
				? (int)$aFields['profileid']
				: ($oObject !== null ? (int)$oObject->Get('profileid') : 0);

			return self::ProfileName($iProfile);
		}

		if (MetaModel::IsValidAttCode($sClass, 'actiongrantid')) {
			$iGrant = array_key_exists('actiongrantid', $aFields)
				? (int)$aFields['actiongrantid']
				: ($oObject !== null ? (int)$oObject->Get('actiongrantid') : 0);

			$oGrant = $iGrant > 0 ? MetaModel::GetObject('URP_ActionGrant', $iGrant, false, true) : null;

			return $oGrant !== null ? (string)$oGrant->Get('profile') : null;
		}

		return null;
	}

	/** One profile's name, read past the caller's silo because this is a gate. */
	private static function ProfileName(int $iProfile): ?string
	{
		if ($iProfile < 1) {
			return null;
		}

		$oProfile = MetaModel::GetObject('URP_Profiles', $iProfile, false, true);

		return $oProfile !== null ? (string)$oProfile->Get('name') : null;
	}

	/** $sClass is $sRoot or descends from it, spelled once. */
	private static function Is(string $sClass, string $sRoot): bool
	{
		return strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true);
	}
}
