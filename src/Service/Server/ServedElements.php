<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use UserRights;

/**
 * What this caller is served: which elements are kept out of the server for it, the policy that
 * the instance configuration and the token's scopes make, and the summary of what is left.
 *
 * Registration and the instructions ask the same questions through here, so the instructions
 * cannot name an element tools/list would withhold. In portal mode only what enforces the
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
		ServedAccess::Remember(static fn (): array => self::ServedAccess(), static fn (AccessPolicy $oServedPolicy): array => self::servedPromptNames($oServedPolicy));
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
	 * @param array<int, string> $aDisabled
	 */
	public static function isHidden(string $sIdentifier, object $oElement, array $aDisabled, AccessPolicy $oPolicy): bool
	{
		// Portal mode serves only what enforces the portal's rules itself.
		// Every other element asks UserRights, which grants "Portal user" read
		// on every bizmodel class - so an element not written for the portal,
		// a pack's included, would serve that user the instance. First, so
		// that no filter below can be what lets one through; the ones below
		// still narrow what it lets through, because portal mode widens
		// nothing.
		if (PortalMode::Current() !== null && !PortalAccess::Serves($oElement)) {
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

		if (in_array($sIdentifier, $aDisabled, true) || in_array(get_class($oElement), $aDisabled, true)) {
			return true;
		}

		return self::lacksRequiredProfiles($oElement->requiredProfiles());
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
