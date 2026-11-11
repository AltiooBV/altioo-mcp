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
 * settings and their defaults are this class's constants; the earlier
 * 1.x spellings that lived elsewhere are gone in 2.0.
 *
 * @api
 * @since 1.3.0
 */
final class Settings
{
	const MODULE_SETTING_SOURCE_URL = 'mcp_source_url';

	/** Whether callers must hold one of mcp_allowed_profiles. */
	const MODULE_SETTING_SECURE = 'secure_mcp_services';

	const MODULE_SETTING_LOG = 'log_mcp_service';

	const DEFAULT_LOG_SETTING = true;

	const MODULE_SETTING_LOG_METHOD = 'log_mcp_method';

	const MODULE_SETTING_LOG_LEVEL = 'log_mcp_level';

	const DEFAULT_LOG_LEVEL = 'info';

	const LOG_LEVEL_INFO = 'info';
	const LOG_LEVEL_DEBUG = 'debug';
	const LOG_LEVEL_ERROR = 'error';

	const MODULE_SETTING_ALLOWED_ORIGINS = 'mcp_allowed_origins';

	/**
	 * Hostnames this endpoint answers to, checked against Origin - or against
	 * Host when there is no Origin - before anything else happens.
	 *
	 * Left empty it is derived rather than defaulted, because the alternative
	 * defaults are both wrong: the SDK's own list is localhost only, which
	 * refuses every production request, and accepting anything gives up the
	 * check. An installed iTop already knows the name it is served under, so
	 * that is what the derivation reads.
	 */
	const MODULE_SETTING_ALLOWED_HOSTS = 'mcp_allowed_hosts';

	/**
	 * How many elements one tools/list, resources/list or prompts/list page
	 * carries.
	 *
	 * The SDK defaults to 50 and pages the rest behind a cursor. That is
	 * correct protocol and a trap in practice: a client that does not follow
	 * nextCursor - and several do not - simply never sees the 51st tool, with
	 * no error anywhere. The base extension alone is nowhere near that, but an
	 * instance with three tool packs installed is, and the operator would have
	 * no way to tell what happened.
	 *
	 * Set high enough that a normal installation is one page, and left
	 * configurable for the ones that are not.
	 */
	const MODULE_SETTING_PAGINATION_LIMIT = 'mcp_pagination_limit';

	const DEFAULT_PAGINATION_LIMIT = 200;

	/**
	 * Distinct objects one call may @mention in the case-log entries it
	 * writes. Each mention can fire TriggerOnObjectMention - usually a mail -
	 * and the markup that carries it is whatever the caller types here, where
	 * the console only offers it one autocomplete pick at a time. 0 turns
	 * mentions off for this endpoint. See MentionPolicy.
	 *
	 * @since 1.1.0
	 */
	const MODULE_SETTING_MAX_MENTIONS = 'mcp_max_mentions';

	const DEFAULT_MAX_MENTIONS = 5;

	/**
	 * Whether a text value that a spreadsheet would run as a formula is
	 * refused on the way in. On unless explicitly false. See FormulaPolicy.
	 *
	 * @since 1.1.0
	 */
	const MODULE_SETTING_REFUSE_FORMULA_VALUES = 'mcp_refuse_formula_values';

	/**
	 * URL of the RFC 9728 protected-resource metadata document, when an
	 * OAuth-terminating proxy in front of iTop serves one. Advertised in the
	 * WWW-Authenticate challenge of a 401; empty means no such parameter.
	 */
	const MODULE_SETTING_RESOURCE_METADATA = 'mcp_protected_resource_metadata';

	/**
	 * Toolsets served by this instance. Empty means every toolset, which is
	 * the right default for an instance that has not been asked to narrow.
	 */
	const MODULE_SETTING_ENABLED_TOOLSETS = 'mcp_enabled_toolsets';

	/**
	 * What this instance allows, for everyone: any of read, write and delete.
	 * Empty means all three.
	 */
	const MODULE_SETTING_CAPABILITIES = 'mcp_capabilities';

	/** Shorthand for mcp_capabilities = array('read'). */
	const MODULE_SETTING_READ_ONLY = 'mcp_read_only';

	/**
	 * Whether the classes that decide what a caller may do can be written
	 * through this endpoint at all - tokens, users, and iTop's rights model.
	 *
	 * Off, and off is the answer for almost every instance. On, the caller may
	 * administer other people's access and still never its own: see
	 * {@see \Altioo\iTop\Extension\MCP\Service\Rights\AccessGrants}, where the
	 * self-guard is unconditional and this setting cannot reach it.
	 */
	const MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION = 'mcp_allow_access_administration';

	/**
	 * Whether an operator consents to this endpoint handing out capabilities
	 * no caller of it holds.
	 *
	 * **This is an escalation switch, not a feature switch**, and it is named
	 * for what it does rather than for what it governs. It was
	 * mcp_allow_automation_administration first, because the classes behind it
	 * are iTop's automation - and that name read like "let the assistant manage
	 * our notifications", which is not what turning it on means. An operator
	 * reading a setting decides from its name, so the name is where the
	 * sentence had to go.
	 *
	 * Everything else this endpoint refuses, it refuses because the caller
	 * could not do the same thing directly - so permitting it would hand back
	 * rights the caller does not hold. These classes are refused for the same
	 * reason taken to its limit: no tool here sends mail, calls a URL, invokes
	 * a static method by name, or authenticates outward as this instance, so
	 * "could the caller have done this itself" answers no for **every** caller,
	 * administrator included. There is no profile that makes it yes.
	 *
	 * Turning this on is therefore an operator saying "this endpoint may grant
	 * more than the credential it was called with". It is not saying "an
	 * assistant may tidy up the notification rules", and an operator who reads
	 * it that way has enabled something else than they meant.
	 *
	 * What it is emphatically *not* for: managing automation as ordinary work.
	 * A caller that can already do a thing directly does not need this setting
	 * to arrange the same thing through a mechanism - that case is graded on
	 * the caller's own rights and passes or fails on them alone. See
	 * AccessGrants: the rule is that you cannot arrange what you cannot do,
	 * and this setting is the one place an operator overrides it.
	 */
	const MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION = 'mcp_allow_privilege_escalation';

	/**
	 * Classes the operator adds to what the setting above governs.
	 *
	 * The built-in list names what iTop ships. An extension can add a class
	 * that stores work something else carries out later - a scheduled mass
	 * update, a script runner - and nothing here has heard of it, so writing
	 * one would be graded as an ordinary write while the work it stages runs
	 * outside this endpoint with nobody's rights checked. The operator is the
	 * one who knows which extensions are installed; this is where they say so.
	 */
	const MODULE_SETTING_ESCALATION_CLASSES = 'mcp_privilege_escalation_classes';

	/**
	 * Operator kill switch: names of tools and prompts, and URIs of resources
	 * and resource templates, that must never be advertised nor callable.
	 * Sits next to mcp_allowed_profiles as the other operator-side gate.
	 */
	const MODULE_SETTING_DISABLED = 'mcp_disabled_tools';

	/**
	 * Portals whose users are served in portal mode. Empty means portal mode
	 * is off, which is the default and the behaviour of every earlier version.
	 *
	 * @since 1.2.0
	 */
	const MODULE_SETTING_PORTALS = 'mcp_portals';

	/**
	 * How many days ahead a token may still be valid to be accepted in portal
	 * mode. 0 lifts the bound.
	 *
	 * @since 1.2.0
	 */
	const MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS = 'mcp_portal_token_max_days';

	/**
	 * How many days an audit row is kept before the hourly clean-up deletes
	 * it. 0, the default, keeps everything: nothing is deleted on an instance
	 * that never set it.
	 *
	 * iTop has no retention for its event log of its own, so the table grows
	 * until an operator does something about it (README, "Data flow and
	 * privacy").
	 *
	 * @since 1.3.0
	 */
	const MODULE_SETTING_AUDIT_RETENTION_DAYS = 'mcp_audit_retention_days';

	/** The default bound: altioo-oauth-server's own max_grant_days. */
	const DEFAULT_PORTAL_TOKEN_MAX_DAYS = 90;

	/**
	 * The methods audited when the operator has not said otherwise.
	 *
	 * An empty default would read as "audit nothing", leaving an instance with
	 * log_mcp_service on - which is its default - with an audit trail that
	 * stays empty for ever. So the default is the documented list, plus
	 * initialize.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.0.0
	 */
	const DEFAULT_LOG_METHODS = [
		MCPMethod::INITIALIZE,
		MCPMethod::TOOLS_CALL,
		MCPMethod::RESOURCES_READ,
		MCPMethod::PROMPTS_GET,
		MCPMethod::EXCEPTION,
	];

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
		return self::SwitchFrom($sSetting, utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, $sSetting, $bDefault), $bSafe);
	}

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
		return self::EscalationClassesFrom(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_ESCALATION_CLASSES, []));
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
				self::MODULE_SETTING_ESCALATION_CLASSES,
				$sProblem,
				self::MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION
			));

			return null;
		}

		return $aClasses;
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
		return self::RetentionDaysFrom(utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, self::MODULE_SETTING_AUDIT_RETENTION_DAYS, 0));
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
			self::MODULE_SETTING_AUDIT_RETENTION_DAYS,
			is_scalar($mValue) ? var_export($mValue, true) : gettype($mValue)
		));

		return 0;
	}
}
