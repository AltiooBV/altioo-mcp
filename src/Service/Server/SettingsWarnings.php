<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use MetaModel;

/**
 * What the log says, on every request, about a setting that matches nothing: a disabled
 * identifier, an enabled toolset or an escalation class nobody answers to, and a single/bulk pair
 * with one half turned off and the other left on.
 *
 * Each of those settings fails silently by construction, so a stale entry looks exactly like a
 * switch that works. Warned rather than refused: taking the endpoint down over a stale line would
 * be worse than the line. It was the warning half of `MCPService` in 1.x.
 *
 * @internal
 * @since 2.0.0
 */
final class SettingsWarnings
{
	/**
	 * Logs each mcp_privilege_escalation_classes entry that names no class.
	 *
	 * A misspelt class refuses nothing, and nothing else would say so: the
	 * class it was meant to protect stays writable while the configuration
	 * reads as though it were not. Warned at every request, like the stale
	 * entries above, until the name is corrected or removed - the usual cause
	 * being an extension renamed or uninstalled.
	 */
	public static function warnAboutEscalationClassesThatMatchNothing(): void
	{
		$aListed = Settings::GetEscalationClasses();
		if ($aListed === null || $aListed === []) {
			return;
		}

		// Case-insensitive, as the barrier matches: IsValidClass() is not, and
		// would report an entry that works as one that does not.
		$aKnown = array_flip(array_map('strtolower', MetaModel::GetClasses()));
		$aStale = array_values(array_filter($aListed, static fn (string $sClass): bool => !isset($aKnown[strtolower($sClass)])));
		if ($aStale !== []) {
			ErrorReport::Log(sprintf(
				"'%s' names %s this instance's datamodel does not have: %s. Nothing is refused by those entries. "
				.'Check the spelling against the class name core_class_schema reports, or remove an entry for an extension that is no longer installed.',
				Settings::MODULE_SETTING_ESCALATION_CLASSES,
				count($aStale) === 1 ? 'a class' : 'classes',
				implode(', ', $aStale)
			));
		}
	}

	/**
	 * Tells the log when mcp_disabled_tools or mcp_enabled_toolsets names
	 * something that is not there.
	 *
	 * Both settings fail silently by construction. Disabling is "hide anything
	 * whose identifier or class is in this list", and a list entry matching
	 * nothing hides nothing - which looks exactly like a kill switch that is
	 * working. Enabling toolsets is the same shape from the other side: a
	 * misspelt toolset name serves nothing rather than everything, so the
	 * failure at least announces itself, but it announces itself as "the tools
	 * are gone" rather than as "this line is wrong".
	 *
	 * The case this is really for is an upgrade. An element renamed by a new
	 * release leaves the operator's entry pointing at a name nobody answers
	 * to, and the tool they had turned off comes back on - on an instance
	 * where somebody once decided it should not be callable, without anything
	 * having been said.
	 *
	 * Warned rather than refused: an operator may legitimately keep an entry
	 * for a pack that is temporarily uninstalled, and taking the endpoint down
	 * over a stale line would be worse than the line.
	 *
	 * @param array<int, string> $aDisabled
	 */
	public static function warnAboutSettingsThatMatchNothing(array $aDisabled): void
	{
		$aToolsets = Settings::GetEnabledToolsets();

		// The overwhelmingly common case is both empty, and walking the whole
		// registry to confirm that nothing matches nothing is not worth doing
		// on every request.
		if ($aDisabled === [] && $aToolsets === []) {
			return;
		}

		$aKnownIdentifiers = [];
		$aKnownToolsets    = [];

		foreach (self::everyRegisteredElement() as $sIdentifier => $oElement) {
			$aKnownIdentifiers[$sIdentifier]        = true;
			$aKnownIdentifiers[get_class($oElement)] = true;

			$sToolset = $oElement->getToolset();
			if (is_string($sToolset) && $sToolset !== '') {
				$aKnownToolsets[$sToolset] = true;
			}
		}

		$aStale = self::entriesMatchingNothing($aDisabled, $aKnownIdentifiers);
		if ($aStale !== []) {
			ErrorReport::Log(sprintf(
				"'%s' names %s that no registered element answers to: %s. "
				.'Nothing is being turned off by those entries. An element renamed by an upgrade is the usual cause - '
				.'check the CHANGELOG of the extension that used to provide it, or list the current names with tools/list.',
				Settings::MODULE_SETTING_DISABLED,
				count($aStale) === 1 ? 'an identifier or class' : 'identifiers or classes',
				implode(', ', $aStale)
			));
		}

		$aStale = self::entriesMatchingNothing($aToolsets, $aKnownToolsets);
		if ($aStale !== []) {
			ErrorReport::Log(sprintf(
				"'%s' names %s that no registered element declares: %s. "
				.'Those entries serve nothing rather than everything, so the elements they were meant to enable are absent. '
				.'Known toolsets on this instance: %s.',
				Settings::MODULE_SETTING_ENABLED_TOOLSETS,
				count($aStale) === 1 ? 'a toolset' : 'toolsets',
				implode(', ', $aStale),
				$aKnownToolsets === [] ? '(none)' : implode(', ', array_keys($aKnownToolsets))
			));
		}
	}

	/**
	 * Logs each mcp_portal_extensions entry that serves nothing in portal mode.
	 *
	 * The list only ever adds, so a stale line is not a leak. It is the opposite
	 * failure: the operator believes portal users have a tool they do not, and
	 * nothing says so. Three causes, each named: no registered element answers
	 * to the entry (a typo, a renamed or uninstalled pack); the element it names
	 * does not implement PortalAware, which the operator's consent cannot
	 * replace (ADR 0017); and the entry is an identifier now held by an element
	 * that took it with overrides(), which is listed by its class name only.
	 */
	public static function warnAboutPortalExtensionsThatServeNothing(): void
	{
		$aListed = Settings::GetPortalExtensions();
		if ($aListed === []) {
			return;
		}

		$aElements = [];
		foreach (self::everyRegisteredElement() as $sIdentifier => $oElement) {
			$aElements[] = [$sIdentifier, $oElement];
		}

		foreach (self::portalListingProblems($aListed, $aElements) as $sProblem => $aEntries) {
			if ($aEntries === []) {
				continue;
			}
			ErrorReport::Log(sprintf(
				"'%s' lists %s that %s: %s.",
				Settings::MODULE_SETTING_PORTAL_EXTENSIONS,
				count($aEntries) === 1 ? 'an entry' : 'entries',
				self::PORTAL_PROBLEMS[$sProblem],
				implode(', ', $aEntries)
			));
		}
	}

	/** What each problem says about the entries it names. */
	private const PORTAL_PROBLEMS = [
		'missing'    => 'no registered element answers to, so portal users are served nothing by it. Check the spelling, or remove an entry for an extension that is no longer installed',
		'unaware'    => 'names an element that does not implement PortalAware, so portal users are not served it whatever the listing says. The element has to declare that it enforces the portal\'s rules',
		'overridden' => 'is an identifier held by an element that took it with overrides(), which portal mode serves only when listed by its class name. List the class name instead',
	];

	/**
	 * Each listed entry that serves nothing, grouped by why.
	 *
	 * An entry matches an element by its identifier or its class name. It serves
	 * that element when the element is PortalAware and either the entry is the
	 * class name or the element did not take its identifier with overrides()
	 * ({@see \Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess::Serves()}).
	 *
	 * @param array<int, string>                      $aListed
	 * @param array<int, array{0: string, 1: object}> $aElements Identifier or URI the registry holds each under, and the element.
	 *
	 * @return array{missing: array<int, string>, unaware: array<int, string>, overridden: array<int, string>}
	 */
	private static function portalListingProblems(array $aListed, array $aElements): array
	{
		$aProblems = ['missing' => [], 'unaware' => [], 'overridden' => []];

		foreach (array_values(array_unique($aListed)) as $sEntry) {
			$bMatched = $bServes = $bUnaware = false;
			foreach ($aElements as [$sIdentifier, $oElement]) {
				$bByClass = $sEntry === get_class($oElement);
				if (!$bByClass && $sEntry !== $sIdentifier) {
					continue;
				}
				$bMatched = true;
				if (!$oElement instanceof PortalAware) {
					$bUnaware = true;
				} elseif ($bByClass || !method_exists($oElement, 'overrides') || $oElement->overrides() === null) {
					$bServes = true;
				}
			}

			if ($bServes) {
				continue;
			}
			$aProblems[!$bMatched ? 'missing' : ($bUnaware ? 'unaware' : 'overridden')][] = $sEntry;
		}

		return $aProblems;
	}

	/**
	 * Tells the log when mcp_disabled_tools turns off one half of a
	 * single/bulk pair and leaves the other half callable.
	 *
	 * The setting withdraws the names it lists, not the job they do, and the
	 * pair is where that surprises people: an operator who lists
	 * core_object_delete to stop deletions still has core_object_bulk_delete,
	 * up to a hundred objects per call. The README says so; this says it where
	 * the operator is looking when the switch "does not work". Warned rather
	 * than refused, like the stale entries above - and silenced the only way
	 * that means anything, by listing the sibling too.
	 *
	 * @param array<int, string> $aDisabled
	 */
	public static function warnAboutBulkSiblingsLeftOn(array $aDisabled): void
	{
		if ($aDisabled === []) {
			return;
		}

		$aTools = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aTools[$sName] = ['class' => get_class($oTool), 'bulk' => $oTool instanceof AbstractBulkTool];
		}

		foreach (self::bulkSiblingsLeftOn($aDisabled, $aTools) as $sDisabled => $sSibling) {
			ErrorReport::Log(sprintf(
				"'%s' turns off %s but not %s, which does the same job %s. List %s as well, "
				."or leave the grade out of '%s' to stop the operation whichever tool asks for it.",
				Settings::MODULE_SETTING_DISABLED,
				$sDisabled,
				$sSibling,
				$aTools[$sSibling]['bulk'] ? 'for many objects in one call' : 'one object at a time',
				$sSibling,
				Settings::MODULE_SETTING_CAPABILITIES
			));
		}
	}

	/**
	 * Each disabled tool whose single/bulk sibling is still on, mapped to
	 * that sibling.
	 *
	 * A pair is declared rather than listed: a tool extending
	 * AbstractBulkTool is the bulk form of the registered tool whose
	 * qualified name is its own without "bulk_" - core_object_bulk_delete and
	 * core_object_delete - so a pack's pairs are found the same way as ours.
	 * An entry may name the tool or its class, as the kill switch accepts
	 * both.
	 *
	 * @param array<int, string>                               $aDisabled
	 * @param array<string, array{class: string, bulk: bool}> $aTools Keyed by qualified name.
	 *
	 * @return array<string, string>
	 */
	private static function bulkSiblingsLeftOn(array $aDisabled, array $aTools): array
	{
		$isOff = static fn (string $sName): bool => in_array($sName, $aDisabled, true)
			|| in_array($aTools[$sName]['class'], $aDisabled, true);

		$aLeftOn = [];
		foreach ($aTools as $sBulk => $aBulk) {
			if (!$aBulk['bulk'] || !str_contains($sBulk, 'bulk_')) {
				continue;
			}

			$sSingle = preg_replace('/(^|_)bulk_/', '$1', $sBulk, 1);
			if ($sSingle === null || $sSingle === $sBulk || !isset($aTools[$sSingle])) {
				continue;
			}

			if ($isOff($sSingle) && !$isOff($sBulk)) {
				$aLeftOn[$sSingle] = $sBulk;
			} elseif ($isOff($sBulk) && !$isOff($sSingle)) {
				$aLeftOn[$sBulk] = $sSingle;
			}
		}

		return $aLeftOn;
	}

	/**
	 * Configured entries that match nothing known, in the order they were
	 * written and without repeats.
	 *
	 * @param array<int, string>     $aConfigured
	 * @param array<string, true>    $aKnown Keyed by name, for the membership test.
	 *
	 * @return array<int, string>
	 */
	private static function entriesMatchingNothing(array $aConfigured, array $aKnown): array
	{
		$aStale = [];

		foreach ($aConfigured as $sEntry) {
			if (!isset($aKnown[$sEntry]) && !in_array($sEntry, $aStale, true)) {
				$aStale[] = $sEntry;
			}
		}

		return $aStale;
	}

	/**
	 * Every element the registry holds, of every kind.
	 *
	 * Read from the registry rather than from what was just registered: an
	 * element the operator disabled is exactly the one that did not make it
	 * into the builder, and it is the one whose name has to still count as
	 * known.
	 *
	 * @return iterable<string, object>
	 */
	private static function everyRegisteredElement(): iterable
	{
		yield from MCPRegistry::GetTools();
		yield from MCPRegistry::GetResources();
		yield from MCPRegistry::GetResourceTemplates();
		yield from MCPRegistry::GetPrompts();
	}
}
