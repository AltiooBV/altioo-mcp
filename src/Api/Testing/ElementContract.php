<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Api\Testing;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Helper\CoreElement;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use PhpToken;
use ReflectionClass;
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
 *    harshest grade - so it is reported here instead. The portal check is
 *    one too, and for the same reason: an element declaring PortalAware whose
 *    source never calls ObjectAccess::Current() registers cleanly, and is
 *    served to portal users only if the operator lists it (ADR 0017), so it
 *    is the author who has to be told, not the registry that can refuse. Only
 *    this module's own elements, files under its src/Core/, are exempt from
 *    it; a class declared in the Core\ namespace by a pack is not.
 *
 * @api
 * @since 1.0.0
 */
final class ElementContract
{
	/**
	 * Named, not imported: Api\ may not import Service\Portal (ADR 0015), and
	 * both names are public contract, so they cannot move without a major.
	 */
	private const PORTAL_AWARE = 'Altioo\\iTop\\Extension\\MCP\\Service\\Portal\\PortalAware';

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

		if (is_a($oElement, self::PORTAL_AWARE) && !CoreElement::IsOwn($oElement) && !self::asksObjectAccess($oElement)) {
			$aWarnings[] = self::WARNING.sprintf(
				'%s declares PortalAware but its source (nor that of its parent classes) never calls ObjectAccess::Current(). In portal mode answer through ObjectAccess::Current() (Readable, Bounded, Hides, RefusalToSet and the other RefusalTo...) for every object read or written, never UserRights alone, which grants a portal user the whole CMDB. The operator must also list the element in mcp_portal_extensions before portal mode serves it.',
				get_class($oElement)
			);
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

	/**
	 * Whether the element's class, or a parent of it, calls
	 * ObjectAccess::Current() in code.
	 *
	 * Read from the PHP tokens, so a comment, docblock or string naming the
	 * call does not count, and the class is resolved through the file's `use`
	 * lines. Necessary, not sufficient: it catches the element that never
	 * asks, not the one that asks and ignores the answer.
	 */
	private static function asksObjectAccess(object $oElement): bool
	{
		for ($oClass = new ReflectionClass($oElement); $oClass !== false; $oClass = $oClass->getParentClass()) {
			$sFile = $oClass->getFileName();
			if ($sFile === false) {
				continue;
			}
			$sCode = @file_get_contents($sFile);
			if ($sCode !== false && self::callsObjectAccessCurrent($sCode)) {
				return true;
			}
		}

		return false;
	}

	/** @internal Reads one source text: whether it calls ObjectAccess::Current(). */
	private static function callsObjectAccessCurrent(string $sCode): bool
	{
		try {
			$aAll = PhpToken::tokenize($sCode, TOKEN_PARSE);
		} catch (Throwable) {
			return false;
		}

		$aTokens = array_values(array_filter(
			$aAll,
			static fn (PhpToken $oToken): bool => !in_array($oToken->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)
		));
		$aNames = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
		$sNamespace = '';
		$aImports = [];
		$iCount = count($aTokens);

		for ($i = 0; $i < $iCount; $i++) {
			$oToken = $aTokens[$i];

			if ($oToken->id === T_NAMESPACE && isset($aTokens[$i + 1]) && in_array($aTokens[$i + 1]->id, $aNames, true)) {
				$sNamespace = $aTokens[$i + 1]->text;
				continue;
			}

			if (in_array($oToken->id, [T_CLASS, T_TRAIT, T_INTERFACE], true) && ($i === 0 || $aTokens[$i - 1]->id !== T_DOUBLE_COLON)) {
				break;
			}

			if ($oToken->id !== T_USE) {
				continue;
			}

			// use A\B [as C], D\E; and the group form use A\{B, C as D};
			$sPrefix = '';
			$sName = '';
			for ($i++; $i < $iCount && $aTokens[$i]->text !== ';'; $i++) {
				$sText = $aTokens[$i]->text;
				if ($aTokens[$i]->id === T_FUNCTION || $aTokens[$i]->id === T_CONST) {
					$sName = '';
					while ($i < $iCount && $aTokens[$i]->text !== ';') {
						$i++;
					}
					break;
				}
				if (in_array($aTokens[$i]->id, $aNames, true)) {
					$sName = ltrim($sText, '\\');
					$sAlias = substr($sName, (int)strrpos('\\'.$sName, '\\'));
					if (isset($aTokens[$i + 1]) && $aTokens[$i + 1]->id === T_AS && isset($aTokens[$i + 2])) {
						$sAlias = $aTokens[$i + 2]->text;
						$i += 2;
					}
					$aImports[strtolower($sAlias)] = $sPrefix.$sName;
				} elseif ($sText === '{') {
					$sPrefix = $sName.'\\';
					if (str_ends_with($sPrefix, '\\\\')) {
						$sPrefix = substr($sPrefix, 0, -1);
					}
				}
			}
		}

		$sTarget = strtolower(ObjectAccess::class);
		for ($i = 0; $i + 3 < $iCount; $i++) {
			$oName = $aTokens[$i];
			if (!in_array($oName->id, $aNames, true) || $aTokens[$i + 1]->id !== T_DOUBLE_COLON || strtolower($aTokens[$i + 2]->text) !== 'current' || $aTokens[$i + 3]->text !== '(') {
				continue;
			}
			if ($i > 0 && in_array($aTokens[$i - 1]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NEW], true)) {
				continue;
			}

			$sText = $oName->text;
			if ($oName->id === T_NAME_FULLY_QUALIFIED) {
				$sResolved = ltrim($sText, '\\');
			} else {
				$iSlash = strpos($sText, '\\');
				$sFirst = strtolower($iSlash === false ? $sText : substr($sText, 0, $iSlash));
				$sResolved = isset($aImports[$sFirst])
					? $aImports[$sFirst].($iSlash === false ? '' : substr($sText, $iSlash))
					: ($sNamespace === '' ? '' : $sNamespace.'\\').$sText;
			}

			if (strtolower($sResolved) === $sTarget) {
				return true;
			}
		}

		return false;
	}
}
