<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use DBObject;
use MetaModel;
use Throwable;
use UserRights;

/**
 * The classes that decide what this endpoint may do, which it may therefore
 * not write.
 *
 * Four gates decide a request: the profile gate, the token scope, the instance
 * capability grading, and iTop's own UserRights. Three of them hang off the
 * user or off the instance configuration, and neither is an object a tool can
 * reach. The token scope is the exception - it is an ordinary attribute on an
 * ordinary DBObject - and it is the only one of the four that grades a single
 * credential rather than everyone holding it.
 *
 * That is what makes it worth a barrier of its own. AccessPolicy promises that
 * a scope can only ever make a credential narrower than its owner's profiles,
 * and the promise is what an operator buys when they issue an MCP-write token
 * to an administrator instead of maintaining a second user account. A caller
 * that can write PersonalToken keeps the promise only by choosing to: it can
 * widen its own scope to MCP, or - easier, and without touching the row it
 * authenticated with - mint a fresh token that holds it. The same goes one
 * layer up, where writing User or a URP_ link grants the profiles that the
 * scope was narrowing.
 *
 * So the rule is structural rather than a matter of rights: the endpoint never
 * writes the things that decide what the endpoint may write. An administrator
 * reaching this through the console is out of scope and always was - they can
 * already edit the datamodel - but an administrator reaching it through a
 * credential minted to be narrow is exactly the escalation the scopes exist to
 * prevent, and "they were an administrator anyway" does not cover it.
 *
 * Reading is not refused. A caller listing its own tokens learns nothing it
 * did not bring with it, the secret is not readable after it is minted, and
 * an assistant that can report "this token expires on Friday" is useful.
 * Every read stays gated by UserRights as before.
 *
 * Every class named below is matched with its descendants, through is_a()
 * rather than by name: UserLocal and UserLDAP are Users, an authentication
 * pack adds more, and a datamodel extension can subclass any of them. A
 * barrier that matched only the exact names would be one <parent> away from
 * open, and nothing would report that it had been stepped around.
 *
 * The prefix is a second match on top of that, not a substitute for it. iTop's
 * rights model is a flat family - URP_Profiles, URP_UserProfile, URP_UserOrg,
 * URP_ActionGrant, URP_StimulusGrant, URP_AttributeGrant and URP_Dimensions
 * are siblings sharing a naming convention, not a parent - so a version that
 * adds an eighth one is covered the day it ships, before anyone here notices
 * it exists. The listed names catch subclasses; the prefix catches siblings.
 *
 * Names alone would still be a list that ages, though, and this is a part of
 * iTop that moves: personal tokens arrived in 3.1, application tokens after
 * them, and whatever grades a credential in 4.x has no name anyone can write
 * down today. So the datamodel is asked as well, about the class in front of
 * it rather than about a list:
 *
 *   - a class declaring a scope attribute that can hold an MCP scope grades
 *     this endpoint, so this endpoint must not write it - which is the
 *     definition the token classes satisfy, arrived at without naming them;
 *   - a class carrying an AttributeOneWayPassword holds a credential, which is
 *     how iTop stores one and the only thing every credential class has in
 *     common;
 *   - a class iTop files under its user-rights category is part of the rights
 *     model by iTop's own reckoning rather than by its spelling.
 *
 * The two layers are a union and the names are the floor. Nothing in the
 * datamodel can make a listed class writable; the datamodel is only ever asked
 * whether something else should be refused too. That ordering is what lets the
 * dynamic half fail quietly - no MetaModel, a category iTop renames, an
 * attribute definition that raises - without opening anything that was closed
 * before, and it is why the unit suite, which runs without an iTop at all,
 * still holds the barrier to the same classes.
 *
 * @api
 * @since 1.0.0
 */
final class AccessGrants
{
	/**
	 * Each of them, and everything descending from it.
	 *
	 * PersonalToken and UserToken carry the scope attribute this endpoint
	 * reads. User carries the login, the password and - through profile_list -
	 * the profiles behind every other gate. The URP_ classes are the rights
	 * model itself: what a profile grants, and who holds it.
	 *
	 * The last two are the parents the URP_ classes actually declare, in
	 * addons/userrights - so the family is matched by descent and not only by
	 * the convention it is named by. User is listed because it is abstract and
	 * carries no credential attribute of its own: UserInternal declares the
	 * password and UserExternal declares nothing, so a rule made of attributes
	 * would cover the second of those and not the first.
	 *
	 * The list is iTop's as of 3.2 and is not load-bearing on its own - the
	 * prefix and the datamodel below cover the family whether or not this
	 * stays current.
	 */
	private const GRANTING_ROOTS = [
		'PersonalToken',
		'UserToken',
		'User',
		'URP_Profiles',
		'URP_UserProfile',
		'URP_UserOrg',
		'URP_ActionGrant',
		'URP_StimulusGrant',
		'URP_AttributeGrant',
		'URP_Dimensions',
		'UserRightsBaseClass',
		'UserRightsBaseClassGUI',
	];

	/**
	 * "User Rights Profile": the convention every class of the rights family
	 * is named by, which covers one this module has never heard of.
	 */
	private const GRANTING_PREFIX = 'URP_';

	/**
	 * What every write tool says when the instance refuses these classes
	 * outright, spelled once so they all say the same thing and all name the
	 * setting that decides it - a model told only "no" retries a variation,
	 * and a model told which switch is off reports it.
	 */
	public const GRANT_REFUSAL = 'Class \'%s\' decides who may reach this endpoint and what they may do with it, so it cannot be written here unless mcp_allow_access_administration is on. Change it in the iTop console.';

	/**
	 * What they say when the instance allows these classes and the call
	 * reaches the caller's own access anyway.
	 *
	 * A different sentence from the one above on purpose: the first is an
	 * instance that has not opted in and can, the second is a rule no
	 * configuration lifts. Telling them apart is what stops a model - or the
	 * person reading over its shoulder - from asking an operator to turn on a
	 * setting that would not have helped.
	 */
	public const SELF_REFUSAL = 'Class \'%s\' can be administered here, but not for yourself: this call reaches the access you are connected with. Change your own token, account or profiles in the iTop console.';

	/**
	 * The category iTop files its rights model under.
	 *
	 * Every URP_ class carries it, in the XML for the three the core declares
	 * and in Init_Params for the ones the addon declares in PHP. Read through
	 * MetaModel::GetClasses(), which answers an unknown category with an empty
	 * list rather than an error - so a category renamed in a later version
	 * costs the dynamic half of the URP_ match and nothing else, the names and
	 * the prefix still standing.
	 */
	private const RIGHTS_CATEGORY = 'addon/userrights';

	/**
	 * The attribute type iTop verifies a credential with, and the sign that a
	 * class authenticates somebody.
	 *
	 * The distinction that matters is one-way, not "password". This type
	 * stores a salted hash and nothing else - two columns, _hash and _salt -
	 * so the secret cannot be read back out of it. A mailbox password, an
	 * OAuth client secret, a device login: every outbound credential has to be
	 * recoverable to be used, so none of them can be kept in this type, and
	 * iTop's own are not. The OAuth client's secret and the webhook's
	 * auth_pwd are AttributePassword; there is a whole module for
	 * AttributeEncryptedPassword. What is left, once a value can only ever be
	 * compared against and never replayed, is something iTop checks a caller
	 * against - which is the definition of the thing this barrier is for.
	 *
	 * The census agrees, for what it is worth: five classes in 3.2 declare
	 * one - UserInternal's password, UserLocal's, PersonalToken's and
	 * UserToken's auth_token, and the change log's record of a previous
	 * password, which ObjectHistory reserves already - and no CMDB class does.
	 * But the argument is the storage, not the count, because the count is
	 * what an extension can change.
	 *
	 * So the recoverable types are deliberately not matched. A datamodel
	 * extension may legitimately keep a mailbox or device password on an
	 * object, and refusing to write those would be an unrelated restriction
	 * wearing this one's name. iTop's own addon/authentication category is
	 * left alone for the same reason: SynchroDataSource carries it, and a
	 * synchro source is not a credential.
	 *
	 * An extension that stored a mailbox password in this type anyway would be
	 * storing one it can never use - and the cost here is that its class
	 * becomes read-only through this endpoint, which is the direction a
	 * barrier should fail in.
	 */
	private const CREDENTIAL_ATTRIBUTE = 'AttributeOneWayPassword';

	/** Answers already worked out, keyed by lowercase class name. */
	private static array $aDecided = [];

	/**
	 * Whether $sClass is one of them.
	 *
	 * Case-insensitive on the exact name for the same reason the rest of the
	 * surface is: iTop resolves class names case-insensitively in OQL, so a
	 * barrier that did not would be one lowercase letter away from open.
	 *
	 * @since 1.0.0
	 */
	public static function IsGranting(string $sClass): bool
	{
		$sKey = strtolower($sClass);
		if (array_key_exists($sKey, self::$aDecided)) {
			return self::$aDecided[$sKey];
		}

		return self::$aDecided[$sKey] = self::IsNamedGranting($sClass) || self::IsDeclaredGranting($sClass);
	}

	/** The floor: the classes above, their descendants, and the prefix. */
	private static function IsNamedGranting(string $sClass): bool
	{
		if (stripos($sClass, self::GRANTING_PREFIX) === 0) {
			return true;
		}

		foreach (self::GRANTING_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What this instance's datamodel says, which is how a class nobody here
	 * has heard of is refused.
	 *
	 * Never raises: it is asked on the way into every write, about ordinary
	 * classes, and the answer for almost all of them is no. A datamodel that
	 * cannot be read is a no here and a line in the log from the reader
	 * itself, which is the right place for it - the barrier is not the thing
	 * that went wrong.
	 */
	private static function IsDeclaredGranting(string $sClass): bool
	{
		if (!class_exists('MetaModel')) {
			return false;
		}

		if (TokenScopes::GradesThisEndpoint($sClass)) {
			return true;
		}

		try {
			foreach (MetaModel::GetClasses(self::RIGHTS_CATEGORY) as $sRightsClass) {
				if (strcasecmp($sClass, $sRightsClass) === 0 || is_a($sClass, $sRightsClass, true)) {
					return true;
				}
			}

			if (!MetaModel::IsValidClass($sClass)) {
				return false;
			}

			foreach (MetaModel::ListAttributeDefs($sClass) as $oAttDef) {
				if (is_a($oAttDef, self::CREDENTIAL_ATTRIBUTE)) {
					return true;
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
	}

	/**
	 * Why this write is refused, or null when it may go ahead.
	 *
	 * Three answers, not two. A class that grants nothing is nobody's business
	 * here and passes straight through. A granting class is refused outright
	 * unless the instance opted in, which is the default. And where the
	 * instance did opt in, one thing is still refused: a call that reaches the
	 * caller's own access.
	 *
	 * That last rule is the whole reason the setting is safe to offer. Without
	 * it, opting in would hand back exactly the escalation the barrier exists
	 * to stop - widen the scope of the token in your hand, or mint a second
	 * one that is wider, and the narrow credential an operator issued was
	 * never narrow. With it, the setting buys administration of *other*
	 * people's access and nothing whatever about your own, which is the shape
	 * an operator actually wants when they turn it on: onboard a user, retire
	 * somebody's leaked token, never promote yourself.
	 *
	 * It is also the rule iTop already follows for the console - a user may
	 * not delete themselves, may not strip their own last profile, and may not
	 * demote themselves out of being able to come back - applied here to the
	 * credential rather than to the session, because the credential is what a
	 * caller of this endpoint holds.
	 *
	 * @param string             $sClass  The class being written.
	 * @param int|null           $iId     The row, where there is one; null on a create.
	 * @param array<string, mixed> $aFields Values being written, which on a create are all there is to go on.
	 *
	 * @since 1.0.0
	 */
	public static function RefusalFor(string $sClass, ?int $iId = null, array $aFields = []): ?string
	{
		return self::RefusalGiven(MCPHelper::AllowsAccessAdministration(), $sClass, $iId, $aFields);
	}

	/**
	 * The same decision with the instance's answer handed in rather than read.
	 *
	 * Split for the reason AccessPolicy is a value object: the rule is worth
	 * testing without an iTop, a configuration or a request, and the half that
	 * needs all three is one line long and lives above.
	 *
	 * @param bool                 $bAdministrationAllowed What mcp_allow_access_administration says.
	 * @param array<string, mixed> $aFields
	 *
	 * @since 1.0.0
	 */
	public static function RefusalGiven(bool $bAdministrationAllowed, string $sClass, ?int $iId = null, array $aFields = []): ?string
	{
		if (!self::IsGranting($sClass)) {
			return null;
		}

		if (!$bAdministrationAllowed) {
			return sprintf(self::GRANT_REFUSAL, $sClass);
		}

		if (self::ReachesTheCaller($sClass, $iId, $aFields)) {
			return sprintf(self::SELF_REFUSAL, $sClass);
		}

		return null;
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
	private static function ReachesTheCaller(string $sClass, ?int $iId, array $aFields): bool
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

	/**
	 * Forgets what the datamodel said, for a test that changes it under us.
	 *
	 * @since 1.0.0
	 */
	public static function Forget(): void
	{
		self::$aDecided = [];
	}
}
