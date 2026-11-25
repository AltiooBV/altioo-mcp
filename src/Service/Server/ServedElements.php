<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\CoreElement;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalDescription;
use Altioo\iTop\Extension\MCP\Service\Read\NameSuggestion;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use MetaModel;
use UserRights;

/**
 * What this caller is served: which elements are kept out of the server for it, the policy that
 * the instance configuration and the token's scopes make, and the summary of what is left.
 *
 * Registration and the instructions ask the same questions through here, but the instructions are
 * narrowed by toolset and by grade, not tool by tool: a tool the operator turned off one by one in
 * `mcp_disabled_tools` is still named in them (a public tool's name, answered as an unknown one when
 * called), on `initialize` and on `server/discover` alike. In portal mode only what enforces the
 * portal's own rules is served, and that filter is asked first. It was the half of `MCPService`
 * in 1.x that decides, as `SettingsWarnings` is the half that warns and `MCPService` the half that
 * builds.
 *
 * @internal
 * @since 2.0.0
 */
final class ServedElements
{
	/**
	 * Tells Service\Rights\ServedAccess how to answer for this request, which
	 * the identity tool, its resource and the instructions ask.
	 *
	 * Before the server is built, because building it asks the instructions,
	 * which ask what is served. Called by run(); a test that reaches them
	 * without run() calls it too.
	 */
	public static function RememberServed(): void
	{
		ServedAccess::Remember(
			static fn (): array => self::ServedAccess(),
			static fn (AccessPolicy $oServedPolicy): array => self::servedPromptNames($oServedPolicy),
			static fn (): ?string => self::writesWithheldBy()
		);
		self::rememberSuggestions();
	}

	/**
	 * What "did you mean" may name in portal mode: the classes the portal offers and the fields its view form shows. The
	 * console's served names are asked by Service\Read\NameSuggestion itself. Both are read from what the caller is
	 * served, never from what exists (review 2026-11-23, C12).
	 */
	private static function rememberSuggestions(): void
	{
		NameSuggestion::Remember(
			static function (): array {
				try {
					return PortalDescription::OfferedClassNames(PortalPolicy::ForCurrentRequest());
				} catch (MCPPortalException) {
					return [];
				}
			},
			static function (string $sClass): array {
				try {
					$oPolicy = PortalPolicy::ForCurrentRequest();
					if (!in_array($sClass, PortalDescription::OfferedClassNames($oPolicy), true)) {
						return [];
					}

					return PortalAccess::Shown($sClass, $oPolicy->Fields($sClass, PortalPolicy::MODE_VIEW));
				} catch (MCPPortalException) {
					return [];
				}
			}
		);
		ClassRefusal::RememberSuggester(static fn (string $sClass): string => NameSuggestion::ForClass($sClass));
	}

	/**
	 * Why this caller is served no tool that changes an object, null when it is served one.
	 *
	 * Three answers, from what decides: the instance's own settings leave nobody a write tool (so the scope is
	 * not why), or they would serve one and this credential's scope narrows it away, or neither and the
	 * answer says nothing of why (a tool the operator turned off, a portal that offers no write). The scope is
	 * named only when the settings alone would have served a write: the credential is dropped before the
	 * instructions are built, so its scopes cannot be read again, and what is left to compare is the instance
	 * policy against the policy the controller decided.
	 *
	 * @since 2.2.0
	 */
	private static function writesWithheldBy(): ?string
	{
		$aGranted = self::ServedAccess()['capabilities'];
		if (in_array(AccessPolicy::CAPABILITY_WRITE, $aGranted, true) || in_array(AccessPolicy::CAPABILITY_DELETE, $aGranted, true)) {
			return null;
		}

		if (!self::policyMayWrite(self::InstancePolicy())) {
			return ServedAccess::WRITES_BY_SETTINGS;
		}

		return self::policyMayWrite(AccessPolicy::Current() ?? self::AccessPolicyOfCurrentRequest())
			? ServedAccess::WRITES_BY_OTHER
			: ServedAccess::WRITES_BY_SCOPE;
	}

	/** Whether a policy grades write or delete and serves a toolset a core write tool is in. */
	private static function policyMayWrite(AccessPolicy $oPolicy): bool
	{
		if (!$oPolicy->allowsCapability(AccessPolicy::CAPABILITY_WRITE) && !$oPolicy->allowsCapability(AccessPolicy::CAPABILITY_DELETE)) {
			return false;
		}

		foreach (['objects', 'documents', 'server'] as $sToolset) {
			if ($oPolicy->allowsToolset($sToolset)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The prompts this caller can actually fetch.
	 *
	 * Asked the same way registration asks, so the instructions cannot name a
	 * prompt tools/list would withhold - the rule the whole block follows.
	 *
	 * @return array<int, string>
	 */
	private static function servedPromptNames(AccessPolicy $oPolicy): array
	{
		$aDisabled = Settings::GetDisabledIdentifiers();
		$aNames = [];

		foreach (MCPRegistry::GetPrompts() as $sName => $oPrompt) {
			if (!self::isHidden((string)$sName, $oPrompt, $aDisabled, $oPolicy)) {
				$aNames[] = (string)$sName;
			}
		}

		sort($aNames);

		return $aNames;
	}

	/**
	 * Whether an element is kept out of the server being built.
	 *
	 * Everything registered goes through the same filters, whatever its kind:
	 * what the element says about itself (isAvailable), what the caller holds
	 * (requiredProfiles), which toolsets this instance serves
	 * (mcp_enabled_toolsets) and what the operator turned off
	 * (mcp_disabled_tools). Not being registered means it is neither listed nor
	 * callable - the SDK can only route to what the builder was given.
	 *
	 * The operator can name either the identifier or the class. The class is
	 * the only way to separate two extensions that picked the same identifier,
	 * which is exactly the case where one of them has to go.
	 *
	 * @param string             $sIdentifier Qualified tool/prompt name, or resource URI, as awarded by the registry.
	 * @param array<int, string>      $aDisabled
	 * @param array<int, string>|null $aPortalListed What mcp_portal_extensions lists; null reads the setting. For tests, which have no configuration.
	 */
	public static function isHidden(string $sIdentifier, object $oElement, array $aDisabled, AccessPolicy $oPolicy, ?array $aPortalListed = null): bool
	{
		// Portal mode serves only what enforces the portal's rules itself and
		// is this module's own or listed by the operator (ADR 0017). Every
		// other element asks UserRights, which grants "Portal user" read on
		// every bizmodel class - so an element not written for the portal
		// would serve that user the instance. First, so
		// that no filter below can be what lets one through; the ones below
		// still narrow what it lets through, because portal mode widens
		// nothing.
		if (PortalMode::Current() !== null && !PortalAccess::Serves($oElement, $sIdentifier, $aPortalListed)) {
			return true;
		}

		if (!$oElement->isAvailable()) {
			return true;
		}

		if (!$oPolicy->allowsToolset($oElement->getToolset())) {
			return true;
		}

		if ($oElement instanceof AbstractMCPTool) {
			[$bReadOnly, $bDestructive] = self::annotatedHints($oElement);
			if (!$oPolicy->allowsTool($bReadOnly, $bDestructive)) {
				return true;
			}
		}

		// An advisory credential is not served a pack's write tool (ADR 0022). This module's own tools
		// rehearse through DryRun::Simulated() whatever the caller sends; a pack's write tool does only if
		// its author called it, which nothing here can know, so the credential that must never write is not
		// given one. Absent from the registry the SDK builds, it is neither listed nor routable: a call by
		// name is answered as for a tool that does not exist.
		if ($oElement instanceof AbstractMCPTool && $oPolicy->isAdvisory() && !CoreElement::IsOwn($oElement)) {
			[$bReadOnlyHint, $bDestructiveHint] = self::annotatedHints($oElement);
			if (AccessPolicy::CapabilityOf($bReadOnlyHint, $bDestructiveHint) !== AccessPolicy::CAPABILITY_READ) {
				return true;
			}
		}

		if (in_array($sIdentifier, $aDisabled, true) || in_array(get_class($oElement), $aDisabled, true)) {
			return true;
		}

		if (self::cannotSucceedForThisCaller($sIdentifier, $oElement)) {
			return true;
		}

		return self::lacksRequiredProfiles($oElement->requiredProfiles());
	}

	/**
	 * Whether one of this module's own tools is served to a caller it can never succeed for: absent, as an
	 * element that is not available is, rather than listed and refused on every call (review 2026-11-23, C6).
	 *
	 * Two cases, both read from what decides the refusal itself:
	 *
	 * - `core_object_history` in portal mode, when the portal shows no history: no brick it grants reaches the
	 *   change log, which is what the tool asks first. A portal that cannot be read does not hide it: the tool
	 *   then answers in the portal's own words.
	 * - `core_object_bulk_delete`, when this account may delete no class in bulk that the tool would let it act
	 *   on (read, delete and bulk delete, a class that exists as objects and is not excluded). The tool
	 *   refuses every class otherwise; the grant is the account's profile and the answer is the same for the
	 *   whole request. Console mode only, as the tool is (it is not portal aware).
	 *
	 * Only this module's own tools, found by their identifier and by where their class is defined: a pack's
	 * tool that took the name is not asked. The per-class gates the tool checks beyond these (the access
	 * grants, a read-only database) stay its own, in its own source (ADR 0014); this hides only what no class can
	 * pass, so it never hides what could succeed.
	 */
	private static function cannotSucceedForThisCaller(string $sIdentifier, object $oElement): bool
	{
		if ($sIdentifier !== 'core_object_history' && $sIdentifier !== 'core_object_bulk_delete') {
			return false;
		}
		if (!CoreElement::IsOwn($oElement)) {
			return false;
		}

		if ($sIdentifier === 'core_object_history') {
			if (PortalMode::Current() === null) {
				return false;
			}
			try {
				return !PortalPolicy::ForCurrentRequest()->ShowsHistory();
			} catch (MCPPortalException $e) {
				return false;
			}
		}

		foreach (MetaModel::GetClasses() as $sClass) {
			if (MetaModel::IsAbstract($sClass) || !ExclusionPolicy::Serves($sClass) || ChangeLogReservation::IsReserved($sClass)) {
				continue;
			}
			if (UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE)
				&& UserRights::IsActionAllowed($sClass, UR_ACTION_DELETE)
				&& UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The toolsets this caller can actually reach, and the capabilities it
	 * holds - what it can see, not what exists.
	 *
	 * Reported so that a caller can tell "this server does not have it" from
	 * "I am not served it". Those look identical from tools/list, and a model
	 * that cannot tell them apart states the first: it concludes a capability
	 * is absent, tells the user so, and stops - when the answer was a scope
	 * nobody had ticked. That happened.
	 *
	 * Only what is served, never the catalogue. Naming a toolset this caller
	 * does not hold would teach it the shape of the withheld surface, which is
	 * the thing {@see \Altioo\iTop\Extension\MCP\Core\ServerInstructions}
	 * narrows itself to avoid. What comes back here never exceeds what
	 * tools/list already showed, so an unrestricted caller learns nothing new
	 * and a narrowed one learns only that its own view has edges.
	 *
	 * Derived by asking the same question registration asks, element by
	 * element, rather than by reading the policy's toolset list: empty there
	 * means "everything", and a toolset whose only element is disabled, or
	 * whose elements all want a profile this user lacks, is not one the caller
	 * can reach whatever the policy says.
	 *
	 * @return array{toolsets: array<int, string>, tools: array<int, string>, capabilities: array<int, string>, prompts: array<int, string>}
	 * @since 1.0.0
	 */
	public static function ServedAccess(): array
	{
		// The one the controller decided while the token was still at hand.
		// Deciding it again here runs after the credential is dropped, finds
		// no token, and answers with the instance-wide policy: a read-only
		// token was told it could write and delete.
		$oPolicy = AccessPolicy::Current() ?? self::AccessPolicyOfCurrentRequest();
		$aDisabled = Settings::GetDisabledIdentifiers();

		$aToolsets = [];
		$aServedGrades = [];
		$aTools = [];
		$aRegistries = [
			MCPRegistry::GetTools(),
			MCPRegistry::GetResources(),
			MCPRegistry::GetResourceTemplates(),
			MCPRegistry::GetPrompts(),
		];
		foreach ($aRegistries as $aElements) {
			foreach ($aElements as $sIdentifier => $oElement) {
				if (!self::isHidden((string)$sIdentifier, $oElement, $aDisabled, $oPolicy)) {
					$aToolsets[$oElement->getToolset()] = true;
					if ($oElement instanceof AbstractMCPTool) {
						[$bReadOnly, $bDestructive] = self::annotatedHints($oElement);
						$aServedGrades[AccessPolicy::CapabilityOf($bReadOnly, $bDestructive)] = true;
						$aTools[] = (string)$sIdentifier;
					}
				}
			}
		}

		$aNames = array_keys($aToolsets);
		sort($aNames);

		// What the caller may do *and* has a tool to do it with, for the same
		// reason the toolsets are the reachable set: a grade the token carries
		// that no served tool needs is not one the caller can use. In portal
		// mode a token graded MCP-delete holds a grade nothing there deletes
		// with, and reporting it sent a client looking for a delete tool.
		$aGranted = $oPolicy->capabilities() ?? AccessPolicy::CAPABILITIES;

		sort($aTools);

		return [
			'toolsets'     => $aNames,
			// The tools themselves, for a caller that cannot tell from a
			// toolset's name what is in it: the portal's tools share the
			// console's toolsets, so that one setting narrows both, and
			// "objects" alone does not say which kind this connection has.
			'tools'        => $aTools,
			'capabilities' => array_values(array_filter(
				AccessPolicy::CAPABILITIES,
				static fn (string $sGrade): bool => in_array($sGrade, $aGranted, true) && isset($aServedGrades[$sGrade])
			)),
			// Named here as well as in the instructions, for the same reason
			// the class schema is served as a tool as well as a resource: a
			// client that never calls prompts/list leaves its model unable to
			// discover a recipe written for exactly the question it is being
			// asked. This block is already where a model looks when something
			// seems to be missing.
			'prompts'      => self::servedPromptNames($oPolicy),
		];
	}

	/**
	 * What this caller is served: the instance configuration, narrowed by the
	 * scopes of the token it authenticated with.
	 *
	 * A token that presented itself but whose scopes cannot be read is graded
	 * read-only. The alternative - assuming the widest policy when the
	 * narrowing information is missing - turns a failure to read into a
	 * privilege escalation.
	 *
	 * Decided by the controller rather than here, and decided early: it is the
	 * last thing that needs the raw credential, so computing it up front is
	 * what lets the credential be dropped before the request object exists.
	 */
	public static function AccessPolicyOfCurrentRequest(): AccessPolicy
	{
		if (AccessPolicy::SettingsAreMalformed()) {
			return AccessPolicy::Nothing();
		}

		$oConfigured = self::InstancePolicy();

		if (!TokenScopes::RequestCarriesAToken()) {
			// Basic authentication or a reverse proxy: no token, no scopes,
			// and nothing to narrow with.
			return $oConfigured;
		}

		$aScopes = TokenScopes::OfCurrentRequest();
		if ($aScopes === null) {
			return $oConfigured->narrowedBy(AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []));
		}

		return $oConfigured->narrowedBy(AccessPolicy::FromScopes($aScopes));
	}

	/**
	 * What the instance's own settings serve, before any token narrows it.
	 *
	 * @since 2.2.0
	 */
	public static function InstancePolicy(): AccessPolicy
	{
		$oConfigured = AccessPolicy::Of(Settings::GetCapabilities(), Settings::GetEnabledToolsets());

		if (AccessPolicy::SettingsAreMalformed()) {
			// The readers above answered "everything" and logged why. A token
			// cannot widen nothing, so there is nothing left to narrow.
			return AccessPolicy::Nothing();
		}

		if (Settings::IsReadOnly()) {
			// mcp_read_only is narrowing, not overriding: an instance that
			// also names grades gets whichever of the two is smaller, and an
			// instance that names grades read-only cannot honour - write, say
			// - is left serving nothing rather than everything.
			$oConfigured = $oConfigured->narrowedBy(AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []));
		}

		return $oConfigured;
	}

	/**
	 * What a tool claims about itself, as [readOnlyHint, destructiveHint].
	 *
	 * Null for either means the tool makes no claim - which is the state of
	 * every tool whose author never annotated it, and is why AccessPolicy
	 * grades that case as the harshest one rather than the mildest.
	 *
	 * @return array{0: bool|null, 1: bool|null}
	 */
	private static function annotatedHints(AbstractMCPTool $oTool): array
	{
		$oAnnotations = $oTool->getAnnotations();
		if ($oAnnotations === null) {
			return [null, null];
		}

		$aSerialized = $oAnnotations->jsonSerialize();
		if (!is_array($aSerialized)) {
			return [null, null];
		}

		return [
			array_key_exists('readOnlyHint', $aSerialized) ? (bool)$aSerialized['readOnlyHint'] : null,
			array_key_exists('destructiveHint', $aSerialized) ? (bool)$aSerialized['destructiveHint'] : null,
		];
	}

	/**
	 * requiredProfiles() is an AND: the caller must hold every profile listed.
	 *
	 * That is the opposite of the endpoint gate mcp_allowed_profiles, which is
	 * an OR - and deliberately so: "allowed" means any of these lets you in,
	 * "required" means all of these are needed. For any-of semantics on an
	 * element, override isAvailable().
	 *
	 * @param array<int, string> $aProfiles
	 */
	private static function lacksRequiredProfiles(array $aProfiles): bool
	{
		foreach ($aProfiles as $sProfile) {
			if (!UserRights::HasProfile($sProfile)) {
				return true;
			}
		}

		return false;
	}
}
