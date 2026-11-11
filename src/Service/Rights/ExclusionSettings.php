<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use MetaModel;
use Throwable;
use UserRights;

/**
 * The three settings that say what is excluded and who is exempt (doc/adr/0012, 0013): their
 * names, how they parse, and what the datamodel and the profile table say is wrong with a setting
 * that does parse.
 *
 * Nothing here decides whether a class or an attribute is excluded; {@see ExclusionPolicy} reads
 * these answers once per request and decides. It was the settings half of `ExclusionPolicy` in 1.x.
 *
 * @internal
 * @since 2.0.0
 */
final class ExclusionSettings
{
	public const SETTING_CLASSES = 'mcp_excluded_classes';

	public const SETTING_ATTRIBUTES = 'mcp_excluded_attributes';

	/** doc/adr/0013: the profiles whose holders are served what is excluded. Empty by default. */
	public const SETTING_EXEMPT_PROFILES = 'mcp_exclusion_exempt_profiles';

	/** Never excludable: what an object is, not what it holds. */
	private const IDENTITY_CODES = ['id', 'finalclass', 'friendlyname'];

	/**
	 * The settings as the datamodel-free half of the rule reads them.
	 *
	 * Public for the unit suite, which has no configuration to read from: no promise to a tool pack, whatever the class's own
	 * tag says. {@see ExclusionPolicy::IsMalformed()} and {@see ExclusionPolicy::ExcludesClass()} are what to call.
	 *
	 * @internal
	 *
	 * @return array{classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string}
	 */
	public static function Parse(mixed $mClasses, mixed $mAttributes): array
	{
		$aClasses = [];
		$aAttributes = [];

		if (!is_array($mClasses)) {
			return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class names, not %s", self::SETTING_CLASSES, gettype($mClasses))];
		}
		foreach ($mClasses as $mClass) {
			if (!is_string($mClass) || !ClassRefusal::LooksLikeClassName(trim($mClass))) {
				return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class names, and holds %s", self::SETTING_CLASSES, is_scalar($mClass) ? var_export($mClass, true) : gettype($mClass))];
			}
			$aClasses[strtolower(trim($mClass))] = trim($mClass);
		}

		if (!is_array($mAttributes)) {
			return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class => array of attribute codes, not %s", self::SETTING_ATTRIBUTES, gettype($mAttributes))];
		}
		foreach ($mAttributes as $mClass => $mCodes) {
			if (!is_string($mClass) || !ClassRefusal::LooksLikeClassName(trim($mClass)) || !is_array($mCodes) || $mCodes === []) {
				return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class => array of attribute codes, and holds a bad entry for %s", self::SETTING_ATTRIBUTES, var_export($mClass, true))];
			}
			foreach ($mCodes as $mCode) {
				if (!is_string($mCode) || !ClassRefusal::LooksLikeClassName(trim($mCode))) {
					return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should list attribute codes for %s, and holds %s", self::SETTING_ATTRIBUTES, trim($mClass), is_scalar($mCode) ? var_export($mCode, true) : gettype($mCode))];
				}
				if (in_array(strtolower(trim($mCode)), self::IDENTITY_CODES, true)) {
					return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' cannot exclude '%s' of %s: it is what the object is, not what it holds", self::SETTING_ATTRIBUTES, trim($mCode), trim($mClass))];
				}
				$aAttributes[strtolower(trim($mClass))][strtolower(trim($mCode))] = trim($mCode);
			}
		}

		return ['classes' => $aClasses, 'attributes' => $aAttributes, 'problem' => null];
	}

	/**
	 * The exempt profiles as the datamodel-free half of the rule reads them: a list of non-empty names.
	 *
	 * Public for the unit suite, which has no configuration to read from: no promise to a tool pack, whatever the class's own
	 * tag says. {@see ExclusionPolicy::IsExempt()} is what to call.
	 *
	 * @internal
	 *
	 * @return array{profiles: array<int, string>, problem: ?string}
	 */
	public static function ParseExempt(mixed $mProfiles): array
	{
		if (!is_array($mProfiles)) {
			return ['profiles' => [], 'problem' => sprintf("'%s' should be an array of profile names, not %s", self::SETTING_EXEMPT_PROFILES, gettype($mProfiles))];
		}

		$aProfiles = [];
		foreach ($mProfiles as $mProfile) {
			if (!is_string($mProfile) || trim($mProfile) === '' || strlen($mProfile) > 255) {
				return ['profiles' => [], 'problem' => sprintf("'%s' should be an array of profile names, and holds %s", self::SETTING_EXEMPT_PROFILES, is_scalar($mProfile) ? var_export($mProfile, true) : gettype($mProfile))];
			}
			$aProfiles[] = trim($mProfile);
		}

		return ['profiles' => array_values(array_unique($aProfiles)), 'problem' => null];
	}

	/**
	 * Whether the real user holds one of the profiles, outside portal mode. Read past the silos, by the account's
	 * key; an account that cannot be found holds nothing.
	 *
	 * @param array<int, string> $aProfiles
	 */
	public static function holdsOneOf(array $aProfiles): bool
	{
		try {
			if (PortalMode::Current() !== null || !class_exists('UserRights') || !class_exists('DBObjectSearch')) {
				return false;
			}
			$iUser = (int)\CMDBChange::GetCurrentUserId();
			if ($iUser < 1) {
				return false;
			}

			$oSearch = \DBObjectSearch::FromOQL('SELECT URP_Profiles AS p JOIN URP_UserProfile AS l ON l.profileid = p.id WHERE l.userid = :user');
			$oSearch->AllowAllData();
			$oSet = new \DBObjectSet($oSearch, [], ['user' => $iUser]);
			while ($oProfile = $oSet->Fetch()) {
				if (in_array((string)$oProfile->Get('name'), $aProfiles, true)) {
					return true;
				}
			}
		} catch (Throwable $e) {
			return false; // fails closed: an account that cannot be read is not exempt
		}

		return false;
	}

	/**
	 * The profile names the datamodel does not have, as a problem; null when every one exists.
	 *
	 * @param array<int, string> $aProfiles
	 */
	public static function exemptProfilesProblem(array $aProfiles): ?string
	{
		if ($aProfiles === [] || !class_exists('MetaModel')) {
			return null;
		}

		try {
			foreach ($aProfiles as $sProfile) {
				$oSearch = \DBObjectSearch::FromOQL('SELECT URP_Profiles WHERE name = :name');
				$oSearch->AllowAllData();
				if ((new \DBObjectSet($oSearch, [], ['name' => $sProfile]))->Count() === 0) {
					return sprintf("'%s' names the profile '%s', which this instance does not have", self::SETTING_EXEMPT_PROFILES, $sProfile);
				}
			}
		} catch (Throwable $e) {
			return ErrorReport::OpaqueFailure('The exempt profiles could not be checked against the instance', $e);
		}

		return null;
	}

	/**
	 * What the datamodel says is wrong with a setting that parses: a name it
	 * does not have, an attribute that is not on its class, or one that is part
	 * of the class's friendly name.
	 *
	 * @param array<string, string>                $aClasses
	 * @param array<string, array<string, string>> $aAttributes
	 */
	public static function datamodelProblem(array $aClasses, array $aAttributes): ?string
	{
		if (!class_exists('MetaModel') || ($aClasses === [] && $aAttributes === [])) {
			return null;
		}

		try {
			foreach ($aClasses as $sKey => $sClass) {
				if (self::canonicalClass((string)$sKey) === null) {
					return sprintf("'%s' names '%s', which this datamodel does not have", self::SETTING_CLASSES, $sClass);
				}
			}
			foreach ($aAttributes as $sKey => $aCodes) {
				$sClass = self::canonicalClass($sKey);
				if ($sClass === null) {
					return sprintf("'%s' names '%s', which this datamodel does not have", self::SETTING_ATTRIBUTES, $sKey);
				}
				foreach ($aCodes as $sCode) {
					if (!MetaModel::IsValidAttCode($sClass, $sCode)) {
						return sprintf("'%s' names '%s' on %s, which has no such attribute", self::SETTING_ATTRIBUTES, $sCode, $sClass);
					}
					foreach (array_merge([$sClass], MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL)) as $sOn) {
						$aInName = (array)(MetaModel::GetNameSpec($sOn)[1] ?? []);
						// The complementary name too (a ticket's title is part of what a name search matches),
						// which is asked on its own: the combined long name calls a function iTop 3.2 does not define.
						if (class_exists('Combodo\iTop\Core\MetaModel\FriendlyNameType')) {
							try {
								$aInName = array_merge($aInName, (array)(MetaModel::GetNameSpec($sOn, \Combodo\iTop\Core\MetaModel\FriendlyNameType::COMPLEMENTARY)[1] ?? []));
							} catch (Throwable $e) {
								// Nothing to add.
							}
						}
						if (in_array($sCode, $aInName, true)) {
							return sprintf("'%s' excludes '%s' of %s, which is part of the friendly name of %s: the friendly name would still serve its value", self::SETTING_ATTRIBUTES, $sCode, $sClass, $sOn);
						}
					}
				}
			}
		} catch (Throwable $e) {
			return ErrorReport::OpaqueFailure('The datamodel could not be checked against the exclusions', $e);
		}

		return null;
	}

	public static function canonicalClass(string $sLowerName): ?string
	{
		foreach (MetaModel::GetClasses() as $sClass) {
			if (strtolower((string)$sClass) === $sLowerName) {
				return (string)$sClass;
			}
		}

		return null;
	}
}
