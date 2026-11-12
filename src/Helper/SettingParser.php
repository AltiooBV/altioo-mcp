<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * What a module setting's raw value means, given the value and nothing else.
 *
 * The decisions {@see Settings} makes after it has read a parameter, split
 * from the readers so the unit suite can exercise each without a configuration.
 * A mistyped value is logged and read as the answer that refuses more.
 *
 * @internal
 * @since 2.0.0
 */
final class SettingParser
{
	/**
	 * The decision ReadSwitch() makes, given the value it read.
	 *
	 * Public for the unit suite, which has no configuration to read from.
	 *
	 * @internal
	 * @since 1.3.0
	 */
	public static function SwitchFrom(string $sSetting, mixed $mValue, bool $bSafe): bool
	{
		if (is_bool($mValue)) {
			return $mValue;
		}

		ErrorReport::Log(sprintf(
			"Itop configuration parameter '%s' should be true or false, not %s. It is read as %s until it is corrected.",
			$sSetting,
			is_scalar($mValue) ? var_export($mValue, true) : gettype($mValue),
			$bSafe ? 'true' : 'false'
		));

		return $bSafe;
	}

	/**
	 * The decision GetEscalationClasses() makes, given the value it read.
	 *
	 * Null - every class - when the value is not a list of class names. This
	 * setting only ever adds refusals, so the answer that refuses more is all
	 * of them, the way 1.1.1 reads a mistyped switch: an operator who wrote
	 * 'MassUpdateJob' instead of array('MassUpdateJob') meant to refuse
	 * something, and refusing nothing would be the one reading certain to be
	 * wrong.
	 *
	 * Public for the unit suite, which has no configuration to read from.
	 *
	 * @return array<int, string>|null
	 * @internal
	 * @since 1.3.0
	 */
	public static function EscalationClassesFrom(mixed $mValue): ?array
	{
		$sProblem = null;
		$aClasses = [];
		if (!is_array($mValue)) {
			$sProblem = 'an array of class names, not '.gettype($mValue);
		} else {
			foreach ($mValue as $mClass) {
				if (!is_string($mClass) || trim($mClass) === '') {
					$sProblem = 'an array of class names, and holds '.(is_scalar($mClass) ? var_export($mClass, true) : gettype($mClass));
					break;
				}
				$aClasses[] = trim($mClass);
			}
		}

		if ($sProblem !== null) {
			ErrorReport::Log(sprintf(
				"Itop configuration parameter '%s' should be %s. Every class is read as listed - nothing can be written through this endpoint "
				."unless '%s' is on - until it is corrected.",
				SettingNames::MODULE_SETTING_ESCALATION_CLASSES,
				$sProblem,
				SettingNames::MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION
			));

			return null;
		}

		return $aClasses;
	}

	/**
	 * What a mcp_portal_extensions value lists: its strings, or nothing when it
	 * is not a list (logged). Split from the reader so it can be exercised
	 * without a configuration.
	 *
	 * @return array<int, string>
	 * @since 2.0.0
	 */
	public static function PortalExtensionsFrom(mixed $mListed): array
	{
		if (!is_array($mListed)) {
			$sType = gettype($mListed);
			ErrorReport::Log("Itop configuration parameter '".SettingNames::MODULE_SETTING_PORTAL_EXTENSIONS."' should be an array instead of $sType. No pack element is served in portal mode until it is corrected.");

			return [];
		}

		return array_values(array_filter($mListed, 'is_string'));
	}

	/**
	 * The decision GetAuditRetentionDays() makes, given the value it read.
	 *
	 * Public for the unit suite, which has no configuration to read from.
	 *
	 * @internal
	 * @since 1.3.0
	 */
	public static function RetentionDaysFrom(mixed $mValue): int
	{
		if (is_int($mValue) && $mValue >= 0) {
			return $mValue;
		}

		ErrorReport::Log(sprintf(
			"Itop configuration parameter '%s' should be a non-negative integer of days, not %s. It is read as 0, which keeps every audit row, until it is corrected.",
			SettingNames::MODULE_SETTING_AUDIT_RETENTION_DAYS,
			is_scalar($mValue) ? var_export($mValue, true) : gettype($mValue)
		));

		return 0;
	}
}
