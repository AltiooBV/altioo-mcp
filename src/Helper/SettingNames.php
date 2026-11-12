<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * The catalogue of this module's parameters: the name of each setting, the
 * default it has when the operator wrote nothing, and what it means.
 *
 * Names and meanings only. Reading a value, and what to do with one that is
 * mistyped, is {@see Settings}, which extends this class so that every
 * `Settings::MODULE_SETTING_*` and `Settings::DEFAULT_*` an extension wrote
 * keeps resolving. A new parameter is declared here, with its docblock, and
 * read there.
 *
 * @api
 * @since 2.0.0
 */
abstract class SettingNames
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
	 * Pack elements portal mode may serve, by identifier or class name, the two
	 * forms mcp_disabled_tools accepts. Empty, the default, serves none: the
	 * operator's consent, element by element, on top of the element declaring
	 * Service\Portal\PortalAware (ADR 0017). Not part of
	 * AccessListsAreMalformed(): it only widens, so a malformed value serves no
	 * pack element and the endpoint keeps running.
	 *
	 * @since 2.0.0
	 */
	const MODULE_SETTING_PORTAL_EXTENSIONS = 'mcp_portal_extensions';

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
}
