<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Dict;
use Throwable;
use utils;

/**
 * @api
 * @since 1.0.0
 */
class MCPHelper
{
	const MODULE_NAME = 'altioo-mcp';

	/**
	 * Single source of truth for the module version.
	 *
	 * extension.xml and module.altioo-mcp.php are read by the iTop setup before
	 * this module's autoloader exists, so they carry the same string literally;
	 * ModuleMetadataTest pins the three together.
	 *
	 * Semver applies to what a downstream tool pack can touch: the abstracts
	 * (AbstractMCPTool / Resource / ResourceTemplate / Prompt), MCPRegistry,
	 * MCPExtensionCollector, iMCPServiceProvider and the helpers under
	 * Helper/. A breaking change to any of those is a major bump; a new
	 * optional hook with a default implementation is a minor one.
	 */
	const VERSION = '1.0.0';

	/**
	 * The mcp/sdk release line this module vendors, loads and is tested
	 * against.
	 *
	 * A tool pack has a real runtime dependency on the SDK - it references
	 * ToolAnnotations and throws ToolCallException, both of which have to
	 * resolve when a client calls it - and must nevertheless not ship a copy,
	 * because two copies in one PHP process resolve to whichever autoloader
	 * answered first and there is no way to predict which. The dependency is
	 * therefore real and satisfied by this module rather than by the pack;
	 * this constant is the constraint a pack declares against so that the two
	 * cannot drift apart unnoticed. See the README, Extending.
	 *
	 * @since 1.0.0
	 */
	const SDK_CONSTRAINT = '^0.7.1';

	/**
	 * The licence this module is distributed under, and where its source is.
	 *
	 * Served to remote callers by the core/version resource, which is what
	 * makes them AGPL 13's "prominent offer": this module is the network-facing
	 * surface of the combined work, and the people interacting with it over a
	 * network are the ones entitled to ask for the corresponding source. An
	 * offer nobody can find from the running instance is not an offer, and a
	 * link in a README on GitHub is not reachable from an MCP session.
	 *
	 * SOURCE_URL is upstream, which is correct for an unmodified install. An
	 * operator who modifies this module and lets third parties reach it owes
	 * *their* source, not ours - `mcp_source_url` overrides this for exactly
	 * that case. See the README, License.
	 *
	 * @since 1.0.0
	 */
	const LICENSE = 'AGPL-3.0-or-later';
	const SOURCE_URL = 'https://github.com/altioo/mcp-server-extension';

	const MODULE_SETTING_SOURCE_URL = 'mcp_source_url';

	const MODULE_SETTING_LOG = 'log_mcp_service';
	const DEFAULT_LOG_SETTING = true;
	const MODULE_SETTING_LOG_METHOD = 'log_mcp_method';

	const MODULE_SETTING_LOG_LEVEL = 'log_mcp_level';
	const DEFAULT_LOG_LEVEL = 'error';
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
	 * {@see \Altioo\iTop\Extension\MCP\Helper\AccessGrants}, where the
	 * self-guard is unconditional and this setting cannot reach it.
	 */
	const MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION = 'mcp_allow_access_administration';

	/**
	 * Operator kill switch: names of tools and prompts, and URIs of resources
	 * and resource templates, that must never be advertised nor callable.
	 * Sits next to mcp_allowed_profiles as the other operator-side gate.
	 */
	const MODULE_SETTING_DISABLED = 'mcp_disabled_tools';

	/**
	 * Pseudo-method stamped on the audit row when the request died before its
	 * JSON-RPC method could be read. Must match an entry of log_mcp_method,
	 * otherwise exceptions are silently dropped from the audit trail.
	 */
	const MCP_METHOD_EXCEPTION = 'exceptions';

	/**
	 * The JSON-RPC method a client sends to open a session.
	 *
	 * Audited apart from the rest: it is the only record that a client
	 * connected at all, and the row is worth having whether or not the
	 * connection went on to call anything.
	 *
	 * @since 1.0.0
	 */
	const MCP_METHOD_INITIALIZE = 'initialize';

	/**
	 * The three JSON-RPC methods that actually do something on this endpoint:
	 * call a tool, read a resource, render a prompt.
	 *
	 * Named rather than spelled out at each use because the module branches on
	 * them - ChangeTracking attributes a write to the tool named by a
	 * tools/call body, the controller reads the element name out of a
	 * different params key for each - and a literal that decides behaviour in
	 * one file and appears as data in another is one typo away from a silent
	 * failure: the attribution falls back to nothing, or the audit row loses
	 * its name, with no error either way.
	 *
	 * @since 1.0.0
	 */
	const MCP_METHOD_TOOLS_CALL = 'tools/call';
	const MCP_METHOD_RESOURCES_READ = 'resources/read';
	const MCP_METHOD_PROMPTS_GET = 'prompts/get';

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
		self::MCP_METHOD_INITIALIZE,
		self::MCP_METHOD_TOOLS_CALL,
		self::MCP_METHOD_RESOURCES_READ,
		self::MCP_METHOD_PROMPTS_GET,
		self::MCP_METHOD_EXCEPTION,
	];

	/**
	 * The MCP methods that earn an audit row on this instance.
	 *
	 * @return array<int, string>
	 *
	 * @since 1.0.0
	 */
	public static function GetAuditedMethods(): array
	{
		$aMethods = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_LOG_METHOD, self::DEFAULT_LOG_METHODS);

		if (!is_array($aMethods)) {
			$sType = gettype($aMethods);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_LOG_METHOD."' should be an array instead of $sType");

			return self::DEFAULT_LOG_METHODS;
		}

		return array_values(array_filter($aMethods, 'is_string'));
	}

	/**
	 * @since 1.0.0
	 */
	public function __construct()
	{
		MCPLog::Enable(APPROOT.'log/error.log');
	}

	/**
	 * Whether the running base extension is at least $sVersion.
	 *
	 * For a pack that wants to degrade rather than refuse: hide the one
	 * element that needs a newer hook through isAvailable() and serve the rest.
	 *
	 * @since 1.0.0
	 */
	public static function AtLeast(string $sVersion): bool
	{
		return version_compare(self::VERSION, $sVersion, '>=');
	}

	/**
	 * Stops a provider that needs a newer base extension than this one.
	 *
	 * iTop's module dependencies already express "altioo-mcp/1.0.0 or later"
	 * and are the right place to say it: the setup refuses the install and the
	 * administrator reads why. This is for the case that gets past them - an
	 * instance where the base was downgraded after the pack was installed, or
	 * a pack shipped as files rather than through the setup - where the
	 * alternative is a fatal error on an undefined method, inside a request,
	 * with the endpoint down for every other pack too.
	 *
	 * Call it first thing in RegisterServiceProvider(). The collector catches
	 * what a provider throws, logs it and skips that provider alone, so the
	 * failure mode becomes "this pack is missing and the log says why".
	 *
	 * @param string $sVersion  Minimum base version, e.g. '1.0.0'.
	 * @param string $sRequires What needs it, named in the log entry.
	 *
	 * @throws MCPRegistrationException When this module is older than that.
	 *
	 * @since 1.0.0
	 */
	public static function RequireVersion(string $sVersion, string $sRequires = ''): void
	{
		if (self::AtLeast($sVersion)) {
			return;
		}

		throw new MCPRegistrationException(sprintf(
			'%s requires %s %s or later; this instance runs %s. Upgrade the base extension, or install a release of %s built for it.',
			$sRequires === '' ? 'This tool pack' : $sRequires,
			self::MODULE_NAME,
			$sVersion,
			self::VERSION,
			$sRequires === '' ? 'the pack' : $sRequires
		));
	}

	/**
	 * A dictionary entry, or $sDefault when there is nothing to translate it
	 * with.
	 *
	 * Three cases have to come out as the default rather than as a key: no
	 * iTop at all (the unit suite runs without one), a dictionary that has not
	 * been loaded yet - Dict::S() answers with the key itself there, which
	 * would put "MCP:tool:acme_x:title" in front of a user - and an entry
	 * nobody has written. Falling back to the English literal the element
	 * already carries means a pack that ships no dictionary reads exactly as
	 * it did before, and a pack that ships one is translated.
	 *
	 * @since 1.0.0
	 */
	public static function Translate(string $sKey, string $sDefault): string
	{
		if (!class_exists(Dict::class)) {
			return $sDefault;
		}

		try {
			$sLabel = Dict::S($sKey, $sDefault);
		} catch (Throwable $e) {
			return $sDefault;
		}

		if (!is_string($sLabel) || $sLabel === '' || $sLabel === $sKey) {
			return $sDefault;
		}

		return $sLabel;
	}

	/**
	 * Server-side error channel.
	 *
	 * Detail that must not reach the caller goes here: the response gets a
	 * generic message, the log gets everything.
	 *
	 * @since 1.0.0
	 */
	public static function LogError(string $sMessage, array $aContext = []): void
	{
		MCPLog::Error($sMessage, null, $aContext);
	}

	/**
	 * A fresh correlation reference for one failure.
	 *
	 * Eight random bytes: short enough that a person can read it out of a
	 * response and grep for it, long enough that two failures on a busy
	 * instance do not collide.
	 *
	 * @since 1.0.0
	 */
	public static function NewErrorReference(): string
	{
		return bin2hex(random_bytes(8));
	}

	/**
	 * Tells the caller that something failed, and tells the log what.
	 *
	 * The rule this implements is provenance, not severity. A message this
	 * module composed - a CheckToWrite() issue, "Unknown attribute 'x'", a
	 * validation refusal - is written for whoever is on the other end and is
	 * exactly what lets a model correct its own call, so it goes back
	 * unchanged. A message that came out of the ORM is written for whoever
	 * maintains iTop: it routinely carries SQL, table names, class internals
	 * and occasionally row values, and a caller cannot act on any of it.
	 *
	 * That second kind is what this is for. Everything the ORM said goes to
	 * log/error.log under a reference, and the caller gets the reference.
	 *
	 * Nothing is lost for the model, either: by the time an ORM call throws,
	 * the pre-write check has already run and refused everything the caller
	 * could have fixed. What is left is the instance's problem - a deadlock, a
	 * broken trigger, a column that no longer matches the datamodel - so the
	 * honest thing to return is "this is not yours to fix" rather than a
	 * fragment of SQL the model will try to reason about.
	 *
	 * @param string    $sWhat A phrase naming the operation, e.g. "Failed to create the object".
	 * @param Throwable $e     What the ORM threw. Never shown to the caller.
	 *
	 * @return string The message to put in front of the caller.
	 *
	 * @since 1.0.0
	 */
	public static function OpaqueFailure(string $sWhat, Throwable $e): string
	{
		$sReference = self::NewErrorReference();

		self::LogError(sprintf(
			'[%s] %s - %s: %s in %s:%d',
			$sReference,
			$sWhat,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		return sprintf(
			'%s. The cause is server-side and was not something this call could have avoided; '
			.'it is recorded in the iTop log under reference %s. Report that reference rather than retrying.',
			$sWhat,
			$sReference
		);
	}

	/**
	 * Tells the caller iTop refused a value they supplied, and tells the log why.
	 *
	 * The sibling of {@see self::OpaqueFailure()} and the same provenance rule;
	 * what differs is whose problem it is. OpaqueFailure() answers a server-side
	 * failure nothing the caller does will fix, so it says not to retry. This
	 * one answers a value the caller chose, so it names the attribute and leaves
	 * another attempt open - while still keeping iTop's own words out of the
	 * response.
	 *
	 * Those words are the point. RestUtils::MakeValue() catches whatever it hit
	 * and rethrows it as "<attcode>: <message>", and one of the things it can
	 * hit is a database error: an external key given as a string is run as OQL
	 * by RestUtils::FindObjectFromKey(), and CoreException folds its context
	 * array into the message it exposes - so a MySQLException raised on that
	 * path arrives carrying 'query' => the SQL that was issued, with the table
	 * names in it and the MySQL error beside them. Passing that straight back
	 * hands a caller the schema in exchange for a malformed value.
	 *
	 * @param string    $sWhat A phrase naming the value, e.g. "Invalid value for attribute 'org_id'".
	 * @param Throwable $e     What iTop threw. Never shown to the caller.
	 *
	 * @return string The message to put in front of the caller.
	 *
	 * @since 1.0.0
	 */
	public static function RejectedValue(string $sWhat, Throwable $e): string
	{
		$sReference = self::NewErrorReference();

		self::LogError(sprintf(
			'[%s] %s - %s: %s in %s:%d',
			$sReference,
			$sWhat,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		// The advice is addressed to a model, so it names what a model can
		// actually do. The reference belongs in iTop's log, which nothing on
		// this server reads: telling a caller to "quote that reference" sent it
		// looking for a tool that does not exist. It is worth reporting to the
		// user, who has a console; it is not a step the caller can take.
		return sprintf(
			'%s. iTop refused the value and its reason is in the instance log, not in this answer. '
			.'Check the attribute against core_class_schema - its type, its allowed values, its pattern - and correct the call. '
			.'If the value looks right, tell the user it failed and give them this reference for the log: %s. '
			.'No tool here reads that log.',
			$sWhat,
			$sReference
		);
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
	 * MCPService::AccessPolicyOfCurrentRequest().
	 *
	 * @return array<int, string> Granted grades, or an empty list for all of them.
	 * @since 1.0.0
	 */
	public static function GetCapabilities(): array
	{
		$aCapabilities = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_CAPABILITIES, []);
		if (!is_array($aCapabilities)) {
			$sType = gettype($aCapabilities);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_CAPABILITIES."' should be an array instead of $sType");
			$aCapabilities = [];
		}

		return array_values(array_filter($aCapabilities, 'is_string'));
	}

	/**
	 * Whether this instance serves reads only.
	 *
	 * @since 1.0.0
	 */
	public static function IsReadOnly(): bool
	{
		return utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_READ_ONLY, false) === true;
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
	 * Compared strictly against true, like every other boolean here: a
	 * configuration holding the string 'false' must not read as permission.
	 *
	 * @since 1.0.0
	 */
	public static function AllowsAccessAdministration(): bool
	{
		return utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION, false) === true;
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
	 * @since 1.0.0
	 */
	public static function GetEnabledToolsets(): array
	{
		$aToolsets = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ENABLED_TOOLSETS, []);
		if (!is_array($aToolsets)) {
			$sType = gettype($aToolsets);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ENABLED_TOOLSETS."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aToolsets, 'is_string'));
	}

	/**
	 * Browser origins allowed to read this endpoint's responses.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function GetAllowedOrigins(): array
	{
		$aOrigins = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ALLOWED_ORIGINS, []);
		if (!is_array($aOrigins)) {
			$sType = gettype($aOrigins);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_ORIGINS."' should be an array instead of $sType");

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
	 * @since 1.0.0
	 */
	public static function GetAllowedHosts(): array
	{
		$aHosts = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_ALLOWED_HOSTS, []);
		if (!is_array($aHosts)) {
			$sType = gettype($aHosts);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_ALLOWED_HOSTS."' should be an array instead of $sType");
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
	 * The protected-resource metadata URL to advertise, or null.
	 *
	 * @since 1.0.0
	 */
	public static function GetProtectedResourceMetadataUrl(): ?string
	{
		$sUrl = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_RESOURCE_METADATA, '');

		return is_string($sUrl) && $sUrl !== '' ? $sUrl : null;
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
	 * @since 1.0.0
	 */
	public static function GetSourceUrl(): string
	{
		$sUrl = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_SOURCE_URL, '');

		return is_string($sUrl) && trim($sUrl) !== '' ? trim($sUrl) : self::SOURCE_URL;
	}

	/**
	 * Elements per listing page, as configured.
	 *
	 * @since 1.0.0
	 */
	public static function GetPaginationLimit(): int
	{
		$iLimit = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_PAGINATION_LIMIT, self::DEFAULT_PAGINATION_LIMIT);
		if (!is_int($iLimit) || $iLimit < 1) {
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_PAGINATION_LIMIT."' should be a positive integer");

			return self::DEFAULT_PAGINATION_LIMIT;
		}

		return $iLimit;
	}

	/**
	 * Identifiers disabled by the operator, normalised to a list of strings.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function GetDisabledIdentifiers(): array
	{
		$aDisabled = utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, self::MODULE_SETTING_DISABLED, []);
		if (!is_array($aDisabled)) {
			$sType = gettype($aDisabled);
			self::LogError("Itop configuration parameter '".self::MODULE_SETTING_DISABLED."' should be an array instead of $sType");

			return [];
		}

		return array_values(array_filter($aDisabled, 'is_string'));
	}
}
