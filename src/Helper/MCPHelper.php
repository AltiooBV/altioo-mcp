<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Dict;
use MetaModel;
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
	const VERSION = '1.2.0';

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
	const SDK_CONSTRAINT = '^0.9.0';

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
	const SOURCE_URL = 'https://github.com/AltiooBV/altioo-mcp';

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_SOURCE_URL */
	const MODULE_SETTING_SOURCE_URL = Settings::MODULE_SETTING_SOURCE_URL;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_SECURE */
	const MODULE_SETTING_SECURE = Settings::MODULE_SETTING_SECURE;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_LOG */
	const MODULE_SETTING_LOG = Settings::MODULE_SETTING_LOG;

	/** @deprecated 1.3.0 use Settings::DEFAULT_LOG_SETTING */
	const DEFAULT_LOG_SETTING = Settings::DEFAULT_LOG_SETTING;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_LOG_METHOD */
	const MODULE_SETTING_LOG_METHOD = Settings::MODULE_SETTING_LOG_METHOD;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_LOG_LEVEL */
	const MODULE_SETTING_LOG_LEVEL = Settings::MODULE_SETTING_LOG_LEVEL;

	/** @deprecated 1.3.0 use Settings::DEFAULT_LOG_LEVEL */
	const DEFAULT_LOG_LEVEL = Settings::DEFAULT_LOG_LEVEL;

	const LOG_LEVEL_INFO = 'info';
	const LOG_LEVEL_DEBUG = 'debug';
	const LOG_LEVEL_ERROR = 'error';

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ALLOWED_ORIGINS */
	const MODULE_SETTING_ALLOWED_ORIGINS = Settings::MODULE_SETTING_ALLOWED_ORIGINS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ALLOWED_HOSTS */
	const MODULE_SETTING_ALLOWED_HOSTS = Settings::MODULE_SETTING_ALLOWED_HOSTS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_PAGINATION_LIMIT */
	const MODULE_SETTING_PAGINATION_LIMIT = Settings::MODULE_SETTING_PAGINATION_LIMIT;

	/** @deprecated 1.3.0 use Settings::DEFAULT_PAGINATION_LIMIT */
	const DEFAULT_PAGINATION_LIMIT = Settings::DEFAULT_PAGINATION_LIMIT;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_MAX_MENTIONS */
	const MODULE_SETTING_MAX_MENTIONS = Settings::MODULE_SETTING_MAX_MENTIONS;

	/** @deprecated 1.3.0 use Settings::DEFAULT_MAX_MENTIONS */
	const DEFAULT_MAX_MENTIONS = Settings::DEFAULT_MAX_MENTIONS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_REFUSE_FORMULA_VALUES */
	const MODULE_SETTING_REFUSE_FORMULA_VALUES = Settings::MODULE_SETTING_REFUSE_FORMULA_VALUES;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_RESOURCE_METADATA */
	const MODULE_SETTING_RESOURCE_METADATA = Settings::MODULE_SETTING_RESOURCE_METADATA;

	/**
	 * A class altioo-oauth-server's datamodel defines, read as "it is
	 * installed": a compiled class is what an installed module leaves, and
	 * asking costs no query.
	 */
	private const OAUTH_SERVER_CLASS = 'AltiooOAuthClient';

	/**
	 * That module's API for the metadata of the resource at a URL, and for
	 * which resource a token was granted for. Named as strings, never
	 * imported: the module is optional (doc/adr/0003).
	 */
	private const OAUTH_RESOURCE_METADATA_CLASS = '\\Altioo\\iTop\\Extension\\OAuthServer\\ResourceMetadata';
	public const OAUTH_TOKEN_AUDIENCE_CLASS = '\\Altioo\\iTop\\Extension\\OAuthServer\\TokenAudience';

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ENABLED_TOOLSETS */
	const MODULE_SETTING_ENABLED_TOOLSETS = Settings::MODULE_SETTING_ENABLED_TOOLSETS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_CAPABILITIES */
	const MODULE_SETTING_CAPABILITIES = Settings::MODULE_SETTING_CAPABILITIES;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_READ_ONLY */
	const MODULE_SETTING_READ_ONLY = Settings::MODULE_SETTING_READ_ONLY;

	/**
	 * What a tool says when it will not act on the class it was given.
	 *
	 * One sentence for two cases, and deliberately: a class that does not
	 * exist and a class this account may not read answer identically, because
	 * telling them apart is a class enumerator for a customer datamodel whose
	 * class names are themselves information.
	 *
	 * What changed is the claim, not the discretion. "Unknown class 'X'." on
	 * its own asserts something false half the time - a reviewer reading it
	 * for URP_UserProfile concluded the class was absent from the datamodel and
	 * went looking for why, when the truth was that iTop reserves its rights
	 * model for administrators and no profile would ever have changed it. The
	 * answer now names both possibilities, which costs the oracle nothing: a
	 * caller learns the same thing in both cases, and learns which two things
	 * that is.
	 *
	 * Used by every site that refuses a class, so the two cases cannot drift
	 * into different wording by being written twice.
	 *
	 * A value that could not be a class name is not repeated back: see
	 * {@see self::LooksLikeClassName()}.
	 *
	 * @since 1.0.0
	 */
	public static function UnreadableClassRefusal(string $sClass): string
	{
		if (!self::LooksLikeClassName($sClass)) {
			return "That is not a class name: a class name is letters, digits and underscores, and does not start with a digit. "
				."core_class_list shows the classes you can reach.";
		}

		if (PortalMode::Current() !== null) {
			return "Unknown class '{$sClass}': the portal does not offer it. core_class_list shows the classes it offers you.";
		}

		return "Unknown class '{$sClass}': either this datamodel has no such class, or this account may not read it. "
			."iTop reserves its rights model, its credentials and its automation for administrators, so if you named one "
			."of those, no profile you can be granted changes this. core_class_list shows the classes you can reach.";
	}

	/**
	 * Whether a value has the shape of an iTop class name, so that a refusal
	 * may quote it.
	 *
	 * Refusals quote the class they were given, because a client that typed
	 * `UserReqest` fixes it from the quote. A value that is no class name at
	 * all - `UserRequest' OR 1=1 --`, markup, a paragraph - gains nothing from
	 * being repeated, and repeating it puts the caller's text, verbatim, into
	 * the audit row's message and whatever shows it next. iTop escapes that
	 * row in the console; this keeps the text out rather than relying on every
	 * later reader of it to escape.
	 *
	 * The shape is the PHP class name a compiled iTop class becomes, in ASCII:
	 * a non-ASCII class name, legal in PHP and unseen in any datamodel, is
	 * refused without the quote and is still refused. It is checked on the
	 * value alone, never against the datamodel, so it tells a caller nothing
	 * about which classes exist. `\z` rather than `$`, which would let a
	 * trailing newline through.
	 *
	 * @since 1.2.0
	 */
	public static function LooksLikeClassName(string $sValue): bool
	{
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $sValue) === 1;
	}

	/**
	 * The JSON Schema type of an input property that is a key/value map.
	 *
	 * `['object', 'array']` rather than `'object'`, for a reason that is
	 * entirely PHP's and entirely visible to the caller. A JSON body is
	 * decoded with json_decode($sBody, true), so `{}` and `[]` both become the
	 * empty PHP array - and the SDK's validator only converts an array back to
	 * an object when it is non-empty and has non-sequential keys
	 * (SchemaValidator::convertDataForValidator()). An empty map therefore
	 * reaches the validator as a list and is refused against `type: object`
	 * with "Invalid type. Expected `object`, but received `array`."
	 *
	 * Which is a type error for a value whose type was right. `fields: {}` on
	 * an update is a caller with nothing to update - an ordinary mistake with
	 * an ordinary answer, "No fields provided for update." - and answering it
	 * with a schema violation sends the caller looking for a shape it already
	 * sent. Widening the declared type lets the empty case through to the
	 * tool, which says the useful thing.
	 *
	 * The cost is that a genuine list gets through the schema too, and is then
	 * refused by the tool for naming attributes like "0" - a worse message
	 * than the validator's, for a call nobody makes by accident, in exchange
	 * for a better one for the call that is made by accident.
	 *
	 * Input only. Output maps stay objects and stay correct, because they are
	 * built by WritePlan::Map(), which casts to stdClass for this same reason
	 * on the way out.
	 *
	 * @since 1.0.0
	 */
	const MAP_TYPE = ['object', 'array'];

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION */
	const MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION = Settings::MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION */
	const MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION = Settings::MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_ESCALATION_CLASSES */
	const MODULE_SETTING_ESCALATION_CLASSES = Settings::MODULE_SETTING_ESCALATION_CLASSES;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_DISABLED */
	const MODULE_SETTING_DISABLED = Settings::MODULE_SETTING_DISABLED;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_PORTALS */
	const MODULE_SETTING_PORTALS = Settings::MODULE_SETTING_PORTALS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS */
	const MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS = Settings::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS;

	/** @deprecated 1.3.0 use Settings::MODULE_SETTING_AUDIT_RETENTION_DAYS */
	const MODULE_SETTING_AUDIT_RETENTION_DAYS = Settings::MODULE_SETTING_AUDIT_RETENTION_DAYS;

	/** @deprecated 1.3.0 use Settings::DEFAULT_PORTAL_TOKEN_MAX_DAYS */
	const DEFAULT_PORTAL_TOKEN_MAX_DAYS = Settings::DEFAULT_PORTAL_TOKEN_MAX_DAYS;

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

	/** @deprecated 1.3.0 use Settings::DEFAULT_LOG_METHODS */
	const DEFAULT_LOG_METHODS = Settings::DEFAULT_LOG_METHODS;

	/**
	 * @return array<int, string>
	 * @deprecated 1.3.0 use Settings::GetAuditedMethods()
	 * @since 1.0.0
	 */
	public static function GetAuditedMethods(): array
	{
		return Settings::GetAuditedMethods();
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
		$sReference = bin2hex(random_bytes(8));

		// Kept so the audit row can carry it. Minted deep inside a tool and
		// handed to the caller inside a message, a reference used to reach the
		// iTop log file and nothing else - so the row recording that very call
		// had error_ref empty, and the identifier the caller was told to quote
		// resolved to nothing anyone could query. A review found 118 error rows
		// and not one populated.
		//
		// Static, and safe to be: this endpoint serves one request per process
		// and holds nothing between them. Bounded because a bulk call mints one
		// per failing row and the column is not a log.
		if (count(self::$aErrorReferences) < self::MAX_ERROR_REFERENCES) {
			self::$aErrorReferences[] = $sReference;
		}

		return $sReference;
	}

	/** Most references one audit row carries; the log has the rest. */
	private const MAX_ERROR_REFERENCES = 12;

	/** @var array<int, string> */
	private static array $aErrorReferences = [];

	/**
	 * Every reference this request minted, in the order it minted them.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function MintedErrorReferences(): array
	{
		return self::$aErrorReferences;
	}

	/** For tests, and for a runner that serves more than one request. @since 1.0.0 */
	public static function ForgetErrorReferences(): void
	{
		self::$aErrorReferences = [];
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
			.'it is recorded in the iTop log, and on this call\'s own audit row, under reference %s. '
			.'Report that reference rather than retrying%s',
			$sWhat,
			$sReference,
			self::auditLookup()
		);
	}

	/**
	 * Where the audit row is found, for the account that may read it. A portal
	 * user may not, and the tools that would find it are not served to them.
	 */
	private static function auditLookup(): string
	{
		return PortalMode::Current() !== null
			? '.'
			: ': an account that may read AltiooEventMCPService can find the row with core_object_search_by_class on error_ref.';
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
			.'If the value looks right, tell the user it failed and give them reference %s: it is in the instance log, and on '
			.'this call\'s own audit row%s',
			$sWhat,
			$sReference,
			self::auditLookup()
		);
	}

	/**
	 * @return array<int, string> Granted grades, or an empty list for all of them.
	 * @deprecated 1.3.0 use Settings::GetCapabilities()
	 * @since 1.0.0
	 */
	public static function GetCapabilities(): array
	{
		return Settings::GetCapabilities();
	}

	/**
	 * @deprecated 1.3.0 use Settings::IsReadOnly()
	 * @since 1.0.0
	 */
	public static function IsReadOnly(): bool
	{
		return Settings::IsReadOnly();
	}

	/**
	 * @deprecated 1.3.0 use Settings::AccessSettingsAreMalformed()
	 * @since 1.1.1
	 */
	public static function AccessSettingsAreMalformed(): bool
	{
		return Settings::AccessSettingsAreMalformed();
	}

	/**
	 * @deprecated 1.3.0 use Settings::IsAccessRestricted()
	 * @since 1.1.1
	 */
	public static function IsAccessRestricted(): bool
	{
		return Settings::IsAccessRestricted();
	}

	/**
	 * @deprecated 1.3.0 use Settings::LogsCalls()
	 * @since 1.1.1
	 */
	public static function LogsCalls(): bool
	{
		return Settings::LogsCalls();
	}

	/**
	 * @internal
	 * @deprecated 1.3.0 use Settings::SwitchFrom()
	 * @since 1.1.1
	 */
	public static function SwitchFrom(string $sSetting, mixed $mValue, bool $bSafe): bool
	{
		return Settings::SwitchFrom($sSetting, $mValue, $bSafe);
	}

	/**
	 * @deprecated 1.3.0 use Settings::AllowsAccessAdministration()
	 * @since 1.0.0
	 */
	public static function AllowsAccessAdministration(): bool
	{
		return Settings::AllowsAccessAdministration();
	}

	/**
	 * @deprecated 1.3.0 use Settings::AllowsPrivilegeEscalation()
	 * @since 1.0.0
	 */
	public static function AllowsPrivilegeEscalation(): bool
	{
		return Settings::AllowsPrivilegeEscalation();
	}

	/**
	 * @return array<int, string>|null
	 * @deprecated 1.3.0 use Settings::GetEscalationClasses()
	 * @since 1.2.0
	 */
	public static function GetEscalationClasses(): ?array
	{
		return Settings::GetEscalationClasses();
	}

	/**
	 * @return array<int, string>|null
	 * @internal
	 * @deprecated 1.3.0 use Settings::EscalationClassesFrom()
	 * @since 1.2.0
	 */
	public static function EscalationClassesFrom(mixed $mValue): ?array
	{
		return Settings::EscalationClassesFrom($mValue);
	}

	/**
	 * @return array<int, string>
	 * @deprecated 1.3.0 use Settings::GetEnabledToolsets()
	 * @since 1.0.0
	 */
	public static function GetEnabledToolsets(): array
	{
		return Settings::GetEnabledToolsets();
	}

	/**
	 * @return array<int, string>
	 * @deprecated 1.3.0 use Settings::GetAllowedOrigins()
	 * @since 1.0.0
	 */
	public static function GetAllowedOrigins(): array
	{
		return Settings::GetAllowedOrigins();
	}

	/**
	 * @return array<int, string> Hostnames without port, or [MCPHttp::ANY_HOST] for no check.
	 * @deprecated 1.3.0 use Settings::GetAllowedHosts()
	 * @since 1.0.0
	 */
	public static function GetAllowedHosts(): array
	{
		return Settings::GetAllowedHosts();
	}

	/**
	 * The portal the endpoint URL names in portal_id, or null when it names
	 * none; '' when the value is not shaped like a portal id, which then
	 * matches no portal and is never quoted back (doc/adr/0007).
	 *
	 * @since 1.2.0
	 */
	public static function RequestedPortal(): ?string
	{
		$mRaw = utils::ReadParam(PortalMode::PARAMETER, null, false, utils::ENUM_SANITIZATION_FILTER_RAW_DATA);
		if ($mRaw === null || $mRaw === '') {
			return null;
		}

		return is_string($mRaw) && PortalMode::IsPortalId($mRaw) ? $mRaw : '';
	}

	/**
	 * The URL this request called the endpoint at, as an OAuth resource is
	 * configured with it: the compiled environment's, with portal_id when the
	 * request names a portal. One resource per portal is one URL per portal
	 * (doc/adr/0007), and an authorization server compares them exactly.
	 *
	 * @since 1.2.0
	 */
	public static function CalledEndpointUrl(): string
	{
		$sUrl = rtrim(utils::GetAbsoluteUrlModulesRoot(), '/').'/'.self::MODULE_NAME.'/index.php';
		$sPortal = self::RequestedPortal();

		return $sPortal === null ? $sUrl : $sUrl.'?'.PortalMode::PARAMETER.'='.rawurlencode($sPortal);
	}

	/**
	 * The protected-resource metadata URL to advertise, or null.
	 *
	 * The setting when it is set. Otherwise, when altioo-oauth-server is
	 * installed beside this module, the document it serves for this endpoint
	 * - which is what its README tells an operator to copy into the setting,
	 * so an install of both connects from a "Connect" button with nothing
	 * typed. That module stays optional in both directions (doc/adr/0003):
	 * without it, nothing changes.
	 *
	 * The document for the URL this request was made at, when that module
	 * has a resource configured with exactly it: a client that called one
	 * portal's URL is sent to consent for that portal (doc/adr/0007).
	 *
	 * @since 1.0.0
	 */
	public static function GetProtectedResourceMetadataUrl(): ?string
	{
		$bOAuthServerInstalled = class_exists('MetaModel') && MetaModel::IsValidClass(self::OAUTH_SERVER_CLASS);

		$mForCalledUrl = $bOAuthServerInstalled && class_exists(self::OAUTH_RESOURCE_METADATA_CLASS)
			? call_user_func([self::OAUTH_RESOURCE_METADATA_CLASS, 'UrlFor'], self::CalledEndpointUrl())
			: null;

		return self::protectedResourceMetadataUrl(
			utils::GetConfig()->GetModuleSetting(self::MODULE_NAME, Settings::MODULE_SETTING_RESOURCE_METADATA, ''),
			$bOAuthServerInstalled,
			utils::GetAbsoluteUrlModulesRoot(),
			is_string($mForCalledUrl) ? $mForCalledUrl : null
		);
	}

	/**
	 * The decision, without iTop.
	 *
	 * @param mixed       $mConfigured  The setting, as the configuration holds it.
	 * @param string      $sModulesRoot utils::GetAbsoluteUrlModulesRoot(), the compiled environment's URL.
	 * @param string|null $sForCalledUrl What that module serves for the URL called, when it knows it.
	 */
	private static function protectedResourceMetadataUrl(mixed $mConfigured, bool $bOAuthServerInstalled, string $sModulesRoot, ?string $sForCalledUrl = null): ?string
	{
		if (is_string($mConfigured) && $mConfigured !== '') {
			return $mConfigured;
		}

		if (!$bOAuthServerInstalled || $sModulesRoot === '') {
			return null;
		}

		if ($sForCalledUrl !== null && $sForCalledUrl !== '') {
			return $sForCalledUrl;
		}

		return rtrim($sModulesRoot, '/').'/altioo-oauth-server/index.php?route=protected-resource&for=mcp';
	}

	/**
	 * @deprecated 1.3.0 use Settings::GetSourceUrl()
	 * @since 1.0.0
	 */
	public static function GetSourceUrl(): string
	{
		return Settings::GetSourceUrl();
	}

	/**
	 * @deprecated 1.3.0 use Settings::GetPaginationLimit()
	 * @since 1.0.0
	 */
	public static function GetPaginationLimit(): int
	{
		return Settings::GetPaginationLimit();
	}

	/**
	 * @deprecated 1.3.0 use Settings::GetMaxMentions()
	 * @since 1.1.0
	 */
	public static function GetMaxMentions(): int
	{
		return Settings::GetMaxMentions();
	}

	/**
	 * @deprecated 1.3.0 use Settings::RefusesFormulaValues()
	 * @since 1.1.0
	 */
	public static function RefusesFormulaValues(): bool
	{
		return Settings::RefusesFormulaValues();
	}

	/**
	 * @return array<int, string>
	 * @deprecated 1.3.0 use Settings::GetDisabledIdentifiers()
	 * @since 1.0.0
	 */
	public static function GetDisabledIdentifiers(): array
	{
		return Settings::GetDisabledIdentifiers();
	}

	/**
	 * @return array<int, string>
	 * @deprecated 1.3.0 use Settings::GetServedPortals()
	 * @since 1.2.0
	 */
	public static function GetServedPortals(): array
	{
		return Settings::GetServedPortals();
	}

	/**
	 * @deprecated 1.3.0 use Settings::GetPortalTokenMaxDays()
	 * @since 1.2.0
	 */
	public static function GetPortalTokenMaxDays(): int
	{
		return Settings::GetPortalTokenMaxDays();
	}

	/**
	 * @deprecated 1.3.0 use Settings::GetAuditRetentionDays()
	 * @since 1.3.0
	 */
	public static function GetAuditRetentionDays(): int
	{
		return Settings::GetAuditRetentionDays();
	}

	/**
	 * @internal
	 * @deprecated 1.3.0 use Settings::RetentionDaysFrom()
	 * @since 1.3.0
	 */
	public static function RetentionDaysFrom(mixed $mValue): int
	{
		return Settings::RetentionDaysFrom($mValue);
	}

}
