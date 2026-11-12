<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use utils;

/**
 * What the operator configured for this module, read from its module
 * parameters, each with the default and the failure answer that setting has.
 *
 * Static, as iTop's own utils and MetaModel helpers are: PHP serves one
 * request per process and nothing here is held between them. The names of the
 * settings and their defaults are the constants of {@see SettingNames}, which
 * this class extends so `Settings::MODULE_SETTING_*` resolves; what a mistyped
 * value is read as is {@see SettingParser}. The earlier 1.x spellings that
 * lived elsewhere are gone in 2.0.
 *
 * @api
 * @since 1.3.0
 */
final class Settings extends SettingNames
{
	/**
	 * The MCP methods that earn an audit row on this instance.
	 *
	 * @return array<int, string>
	 *
	 * @since 1.3.0
	 */
	public static function GetAuditedMethods(): array
	{
		$aMethods = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_LOG_METHOD, self::DEFAULT_LOG_METHODS);

		if (!is_array($aMethods)) {
			$sType = gettype($aMethods);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_LOG_METHOD."' should be an array instead of $sType");

			return self::DEFAULT_LOG_METHODS;
		}

		return array_values(array_filter($aMethods, 'is_string'));
	}

	/**
	 * What mcp_capabilities names, on its own.
	 *
	 * mcp_read_only is the other half of the answer and is deliberately not
	 * applied here. It is shorthand - turning the whole endpoint read-only is
	 * what an operator wants to do in one line and without looking anything
	 * up, and spelling it as a list of grades is not that - and it narrows
	 * rather than overrides, so setting both cannot come out wider than
	 * either. Narrowing two lists correctly means telling "named nothing" from
	 * "agreed on nothing", which a list of grades cannot say and AccessPolicy
	 * can, so the two are combined there: see
	 * ServedElements::AccessPolicyOfCurrentRequest().
	 *
	 * @return array<int, string> Granted grades, or an empty list for all of them.
	 * @since 1.3.0
	 */
	public static function GetCapabilities(): array
	{
		$aCapabilities = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_CAPABILITIES, []);
		if (!is_array($aCapabilities)) {
			$sType = gettype($aCapabilities);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_CAPABILITIES."' should be an array instead of $sType. Nothing is served until it is corrected.");
			$aCapabilities = [];
		}

		return array_values(array_filter($aCapabilities, 'is_string'));
	}

	/**
	 * Whether this instance serves reads only.
	 *
	 * @since 1.3.0
	 */
	public static function IsReadOnly(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_READ_ONLY, false, true);
	}

	/**
	 * Whether mcp_capabilities or mcp_enabled_toolsets holds something other
	 * than a list.
	 *
	 * GetCapabilities() and GetEnabledToolsets() answer an empty list then,
	 * which is the spelling of "everything" - so a line meant to narrow the
	 * endpoint, written as 'read' instead of array('read'), opened it fully.
	 * Something was meant and none of it can be honoured, which is the case
	 * AccessPolicy::Of() already answers with nothing for a list of unknown
	 * grades. The caller serves nothing on true; the readers above log why.
	 *
	 * Only the two lists: {@see \Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy::SettingsAreMalformed()}
	 * adds the exclusions, which a setting reader does not judge.
	 *
	 * @since 2.0.0
	 */
	public static function AccessListsAreMalformed(): bool
	{
		foreach ([self::MODULE_SETTING_CAPABILITIES, self::MODULE_SETTING_ENABLED_TOOLSETS] as $sSetting) {
			if (!is_array(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, $sSetting, []))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether callers must hold one of mcp_allowed_profiles.
	 *
	 * @since 1.3.0
	 */
	public static function IsAccessRestricted(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_SECURE, true, true);
	}

	/**
	 * Whether a call earns an audit row at all.
	 *
	 * @since 1.3.0
	 */
	public static function LogsCalls(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_LOG, self::DEFAULT_LOG_SETTING, true);
	}

	/**
	 * A boolean module setting, read so that a mistyped value fails safe.
	 *
	 * Comparing against true reads 'true', 1 and 'yes' as false, and comparing
	 * against false reads 'false' and 0 as true; either one fails open for
	 * half the switches it is used on. Neither guess is made here: a value
	 * that is not a PHP boolean is logged and read as $bSafe, which is
	 * whichever answer refuses more - the profile check on, the instance
	 * read-only, the audit written, the escalation switches off.
	 *
	 * @since 1.1.1
	 */
	private static function ReadSwitch(string $sSetting, bool $bDefault, bool $bSafe): bool
	{
		return SettingParser::SwitchFrom($sSetting, utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, $sSetting, $bDefault), $bSafe);
	}

	/**
	 * Whether this instance lets the endpoint write the classes that grant
	 * access.
	 *
	 * Refused outright when this is off, which is the default and the right
	 * answer unless an operator has decided otherwise: no ordinary assistant
	 * workflow mints a token or grants a profile. Turning it on buys the
	 * ability to administer *other* accounts - onboarding a user, retiring
	 * somebody's token - and buys nothing at all where the caller's own
	 * access is concerned, because the self-guard does not consult this.
	 *
	 * Read through ReadSwitch(), like every other boolean here: a
	 * configuration holding the string 'false' must not read as permission.
	 *
	 * @since 1.3.0
	 */
	public static function AllowsAccessAdministration(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION, false, false);
	}

	/**
	 * Whether this instance consents to the endpoint granting more than the
	 * credential it was called with.
	 *
	 * Off by default, and the default is the answer for almost every instance.
	 * See the constant above for what turning it on actually means, which is
	 * exactly what the name says.
	 *
	 * @since 1.3.0
	 */
	public static function AllowsPrivilegeEscalation(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION, false, false);
	}

	/**
	 * Classes the operator added to the escalation barrier, or null for every
	 * class.
	 *
	 * @return array<int, string>|null
	 * @since 1.3.0
	 */
	public static function GetEscalationClasses(): ?array
	{
		return SettingParser::EscalationClassesFrom(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_ESCALATION_CLASSES, []));
	}

	/**
	 * Toolsets the operator turned on, or an empty list meaning all of them.
	 *
	 * The positive counterpart to mcp_disabled_tools: that one names what must
	 * go, this one names what may stay. Naming what may stay is what an
	 * operator wants when a pack they did not write adds tools they have not
	 * read, since a tool added by an update is off until someone says
	 * otherwise.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	public static function GetEnabledToolsets(): array
	{
		$aToolsets = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_ENABLED_TOOLSETS, []);
		if (!is_array($aToolsets)) {
			$sType = gettype($aToolsets);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_ENABLED_TOOLSETS."' should be an array instead of $sType. Nothing is served until it is corrected.");

			return [];
		}

		return array_values(array_filter($aToolsets, 'is_string'));
	}

	/**
	 * Browser origins allowed to read this endpoint's responses.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	public static function GetAllowedOrigins(): array
	{
		$aOrigins = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_ALLOWED_ORIGINS, []);
		if (!is_array($aOrigins)) {
			$sType = gettype($aOrigins);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_ORIGINS."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aOrigins, 'is_string'));
	}

	/**
	 * Hostnames this endpoint answers to.
	 *
	 * What the operator wrote, if they wrote anything: an explicit list is a
	 * decision, and second-guessing it by adding to it would make the setting
	 * unable to express "only this name".
	 *
	 * @return array<int, string> Hostnames without port, or [MCPHttp::ANY_HOST] for no check.
	 * @since 1.3.0
	 */
	public static function GetAllowedHosts(): array
	{
		$aHosts = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_ALLOWED_HOSTS, []);
		if (!is_array($aHosts)) {
			$sType = gettype($aHosts);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_HOSTS."' should be an array instead of $sType");
			$aHosts = [];
		}

		$aHosts = array_values(array_filter($aHosts, 'is_string'));

		return empty($aHosts) ? self::DerivedAllowedHosts() : $aHosts;
	}

	/**
	 * The hostnames an instance that configured none is served under.
	 *
	 * Three sources, and each is there because leaving it out breaks a
	 * deployment that works today: app_root_url is the name the setup recorded
	 * and the one production traffic arrives on; the localhost variants keep a
	 * developer, the docker image and an on-box health check working; and an
	 * origin already allow-listed for CORS is by definition one this endpoint
	 * is meant to answer, so refusing its host here would contradict the other
	 * setting.
	 *
	 * @return array<int, string>
	 */
	private static function DerivedAllowedHosts(): array
	{
		$sAppRootUrl = utils::GetConfig()->Get('app_root_url');
		$sAppRootUrl = is_string($sAppRootUrl) ? trim($sAppRootUrl) : '';

		if ($sAppRootUrl !== '' && str_contains($sAppRootUrl, SERVER_NAME_PLACEHOLDER)) {
			// app_root_url written with iTop's placeholder says the instance
			// answers to whatever name it is reached at. There is no hostname
			// to check against, and inventing one would refuse valid traffic.
			return [MCPHttp::ANY_HOST];
		}

		$aHosts = ['localhost', '127.0.0.1', '[::1]'];

		foreach (array_merge([$sAppRootUrl], self::GetAllowedOrigins()) as $sUrl) {
			if ($sUrl === '') {
				continue;
			}

			$sHost = parse_url($sUrl, PHP_URL_HOST);
			if (is_string($sHost) && $sHost !== '') {
				$aHosts[] = $sHost;
			}
		}

		return array_values(array_unique($aHosts));
	}

	/**
	 * Where a caller can obtain the corresponding source of what they are
	 * talking to.
	 *
	 * Defaults to upstream. An operator running a modified copy sets
	 * `mcp_source_url` to wherever *their* source is: under AGPL 13 the offer
	 * has to lead to the version actually running, and pointing a user at
	 * upstream while serving them something else satisfies nobody.
	 *
	 * @since 1.3.0
	 */
	public static function GetSourceUrl(): string
	{
		$sUrl = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_SOURCE_URL, '');

		return is_string($sUrl) && trim($sUrl) !== '' ? trim($sUrl) : MCPModule::SOURCE_URL;
	}

	/**
	 * Elements per listing page, as configured.
	 *
	 * @since 1.3.0
	 */
	public static function GetPaginationLimit(): int
	{
		$iLimit = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_PAGINATION_LIMIT, self::DEFAULT_PAGINATION_LIMIT);
		if (!is_int($iLimit) || $iLimit < 1) {
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_PAGINATION_LIMIT."' should be a positive integer");

			return self::DEFAULT_PAGINATION_LIMIT;
		}

		return $iLimit;
	}

	/**
	 * Distinct objects one call may mention, as configured.
	 *
	 * @since 1.3.0
	 */
	public static function GetMaxMentions(): int
	{
		$iMax = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_MAX_MENTIONS, self::DEFAULT_MAX_MENTIONS);
		if (!is_int($iMax) || $iMax < 0) {
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_MAX_MENTIONS."' should be a non-negative integer");

			return self::DEFAULT_MAX_MENTIONS;
		}

		return $iMax;
	}

	/**
	 * Whether formula-shaped text is refused on the way in.
	 *
	 * Anything but an explicit false keeps it on - logged, through
	 * ReadSwitch(): a setting that guards data leaving the instance should
	 * not be turned off by a typo.
	 *
	 * @since 1.3.0
	 */
	public static function RefusesFormulaValues(): bool
	{
		return self::ReadSwitch(self::MODULE_SETTING_REFUSE_FORMULA_VALUES, true, true);
	}

	/**
	 * Identifiers disabled by the operator, normalised to a list of strings.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	public static function GetDisabledIdentifiers(): array
	{
		$aDisabled = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_DISABLED, []);
		if (!is_array($aDisabled)) {
			$sType = gettype($aDisabled);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_DISABLED."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aDisabled, 'is_string'));
	}

	/**
	 * Pack elements the operator lets portal mode serve, normalised to a list
	 * of strings.
	 *
	 * A value that is not a list opens nothing: this setting only widens what
	 * portal mode serves, so the safe reading of a malformed one is the empty
	 * list, not stopping the endpoint (ADR 0017).
	 *
	 * @return array<int, string>
	 * @since 2.0.0
	 */
	public static function GetPortalExtensions(): array
	{
		return SettingParser::PortalExtensionsFrom(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_PORTAL_EXTENSIONS, []));
	}

	/**
	 * Portal ids served in portal mode, or an empty list when it is off.
	 * PortalMode::ANY_PORTAL, the default, serves every portal: who is let in
	 * is then decided by the profiles a user holds.
	 *
	 * A value that is not a list turns portal mode off rather than on: the
	 * safe reading of a setting that admits people is the one admitting
	 * nobody, which is also what every earlier version did.
	 *
	 * @return array<int, string>
	 * @since 1.3.0
	 */
	public static function GetServedPortals(): array
	{
		$aPortals = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_PORTALS, ['*']);
		if (!is_array($aPortals)) {
			$sType = gettype($aPortals);
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_PORTALS."' should be an array instead of $sType. Portal mode is off until it is corrected.");

			return [];
		}

		return array_values(array_filter($aPortals, 'is_string'));
	}

	/**
	 * The bound on a portal-mode token's remaining validity, in days; 0 for
	 * none.
	 *
	 * A value that is not a non-negative integer falls back to the default
	 * rather than to 0: lifting the bound is a decision, and a typo is not
	 * one.
	 *
	 * @since 1.3.0
	 */
	public static function GetPortalTokenMaxDays(): int
	{
		$iDays = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS, self::DEFAULT_PORTAL_TOKEN_MAX_DAYS);
		if (!is_int($iDays) || $iDays < 0) {
			ErrorReport::Log("Itop configuration parameter '".self::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS."' should be a non-negative integer. Using ".self::DEFAULT_PORTAL_TOKEN_MAX_DAYS.' until it is corrected.');

			return self::DEFAULT_PORTAL_TOKEN_MAX_DAYS;
		}

		return $iDays;
	}

	/**
	 * Days an audit row is kept; 0 keeps everything.
	 *
	 * A value that is not a non-negative integer reads as 0, which is "keep",
	 * and is logged: a purge is the one thing here that cannot be undone, so
	 * a typo must not become one.
	 *
	 * @since 1.3.0
	 */
	public static function GetAuditRetentionDays(): int
	{
		return SettingParser::RetentionDaysFrom(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_AUDIT_RETENTION_DAYS, 0));
	}

}
