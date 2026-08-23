<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Testing;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Throwable;

/**
 * Everything wrong with an element, for a pack to assert in its own suite.
 *
 * # Why this ships in the production autoload
 *
 * The obvious place for test support is `tests/`, under `autoload-dev`. That
 * is where this module's own contract tests live, and it is the wrong place
 * for the part a downstream pack needs: `autoload-dev` is absent from a
 * production dump, `tests/` is one of the paths iTop's discovery ignores, and
 * a pack that installed this module as an iTop module - which is how it is
 * installed - never ran `composer install --dev` against it and so has no
 * PHPUnit and no dev autoloader to reach into.
 *
 * So the rule this file keeps is narrower than "no tests in production": it is
 * that **nothing here may reference a dev dependency**. There is no PHPUnit
 * import, no assertion, no TestCase - failures are returned as strings and the
 * caller decides what to do with them. A pack asserts them through whatever
 * test framework it already has:
 *
 * ```php
 * self::assertSame([], ElementContract::Violations(new TicketAddLogEntry()));
 * ```
 *
 * That leaves no reason to hide this behind a mode, an environment flag or a
 * separate package. The class is not referenced by any code path that serves a
 * request, and the autoloader is a classmap, so an instance that never calls it
 * never reads the file: shipping it costs the few KB it occupies on disk and
 * nothing at runtime. A mode would cost more than that to build and would take
 * the checker away from the one audience it exists for.
 *
 * # What it reports
 *
 * Two kinds of finding, deliberately in one list because a pack author wants
 * one answer rather than a taxonomy:
 *
 *  - **Refusals** - what {@see MCPRegistry::Check()} throws on. The element
 *    would not register at all, and the endpoint would log it and move on
 *    without it. Same rules, same wording as at boot, because they *are* the
 *    boot rules rather than a copy.
 *  - **Warnings** - what registers cleanly and then disappoints. A tool with
 *    no annotations is the one that costs people an afternoon: it registers,
 *    it works for an administrator, and it is withheld from every scoped token
 *    and from any instance running with mcp_capabilities. The registry cannot
 *    refuse it - an unannotated tool is legal, and is merely graded at the
 *    harshest grade - so it is reported here instead.
 *
 * @api
 * @since 1.0.0
 */
final class ElementContract
{
	/** Prefix marking a finding that stops the element registering. */
	public const REFUSAL = 'refused: ';

	/** Prefix marking a finding that registers but behaves surprisingly. */
	public const WARNING = 'warning: ';

	/**
	 * Every contract failure of one element, most severe first.
	 *
	 * An empty list is the passing case, which is what makes this usable as a
	 * one-line assertion. A refusal is reported alone: the element does not
	 * register, so what its annotations would have graded it as is not yet a
	 * question worth answering.
	 *
	 * @return array<int, string>
	 *
	 * @since 1.0.0
	 */
	public static function Violations(object $oElement): array
	{
		try {
			MCPRegistry::Check($oElement);
		} catch (Throwable $e) {
			return [self::REFUSAL.$e->getMessage()];
		}

		return self::warnings($oElement);
	}

	/**
	 * The same, over everything a provider registers.
	 *
	 * Keyed by class name so a failing assertion names the element rather than
	 * an offset into a list. Elements that pass are dropped, so the passing
	 * case is again an empty array.
	 *
	 * @param iterable<object> $aElements
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @since 1.0.0
	 */
	public static function ViolationsOfAll(iterable $aElements): array
	{
		$aFindings = [];

		foreach ($aElements as $oElement) {
			$aViolations = self::Violations($oElement);
			if (!empty($aViolations)) {
				$aFindings[get_class($oElement)] = $aViolations;
			}
		}

		return $aFindings;
	}

	/**
	 * What registers and then behaves in a way its author did not intend.
	 *
	 * @return array<int, string>
	 */
	private static function warnings(object $oElement): array
	{
		$aWarnings = [];

		if ($oElement instanceof AbstractMCPTool) {
			$aWarnings = array_merge($aWarnings, self::toolWarnings($oElement));
		}

		$sDescription = method_exists($oElement, 'getDescription') ? $oElement->getDescription() : null;
		if (!$oElement instanceof AbstractMCPTool && (!is_string($sDescription) || trim($sDescription) === '')) {
			// Only tools have this refused outright, because only a tool is
			// chosen by a model off its description. It is still the text a
			// person reads in a client's resource list.
			$aWarnings[] = self::WARNING.'getDescription() is empty. It is what a client shows next to this element.';
		}

		return $aWarnings;
	}

	/**
	 * @return array<int, string>
	 */
	private static function toolWarnings(AbstractMCPTool $oTool): array
	{
		$aWarnings = [];

		$oAnnotations = $oTool->getAnnotations();
		if ($oAnnotations === null) {
			$aWarnings[] = self::WARNING.sprintf(
				'getAnnotations() returns null, so "%s" is graded "%s" - the harshest grade. It will be withheld from every token scoped MCP-read or MCP-write, and from any instance running with mcp_capabilities. Declare ToolAnnotations with readOnlyHint / destructiveHint.',
				$oTool->getQualifiedName(),
				AccessPolicy::CAPABILITY_DELETE
			);
		}

		$aOutputSchema = $oTool->getOutputSchema();
		if ($aOutputSchema !== null && ($aOutputSchema['type'] ?? null) !== 'object') {
			$aWarnings[] = self::WARNING.'getOutputSchema() is not a JSON Schema of type "object", so a client validating structured content against it will reject every result.';
		}

		// getToolset() defaulting to the namespace is deliberately not reported.
		// It is the documented default and the right answer for a pack with
		// four tools in it, so warning about it would make the passing case
		// non-empty for a well-written pack - which would cost the one-line
		// assertion this class exists to support.

		return $aWarnings;
	}
}
