<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnavailableTool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the operator's kill switch accepts.
 *
 * Naming the identifier is the ordinary case. Naming the class matters when
 * two extensions claimed one identifier: the registry then serves it to
 * nobody, and the only way to say which of the two should come back is to
 * name the class, because the identifier no longer distinguishes them.
 *
 * The profile branch is exercised elsewhere - it calls UserRights and needs a
 * live iTop; everything here stays on the paths that do not.
 */
class MCPServiceGatingTest extends TestCase
{
	/**
	 * @param array<int, string> $aDisabled
	 * @param array<int, string> $aToolsets Empty means every toolset is served.
	 */
	private function isHidden(object $oElement, array $aDisabled, array $aToolsets = [], ?AccessPolicy $oPolicy = null): bool
	{
		// No setAccessible(): it has been a no-op since PHP 8.1 and is
		// deprecated in 8.5, which this suite treats as a failure.
		$oMethod = new ReflectionMethod(MCPService::class, 'isHidden');

		return $oMethod->invoke(
			null,
			$oElement->getQualifiedName(),
			$oElement,
			$aDisabled,
			$oPolicy ?? AccessPolicy::Of([], $aToolsets)
		);
	}

	/**
	 * @param array<int, string>  $aConfigured
	 * @param array<int, string>  $aKnown
	 *
	 * @return array<int, string>
	 */
	private function matchingNothing(array $aConfigured, array $aKnown): array
	{
		$oMethod = new ReflectionMethod(MCPService::class, 'entriesMatchingNothing');

		return $oMethod->invoke(null, $aConfigured, array_fill_keys($aKnown, true));
	}

	/**
	 * A kill-switch entry that matches nothing hides nothing, and looks
	 * exactly like one that is working. The usual cause is an upgrade renaming
	 * the element - at which point a tool somebody deliberately turned off is
	 * back on, silently.
	 */
	public function testAnEntryThatMatchesNothingIsReported(): void
	{
		$this->assertSame(
			['core_object_delet'],
			$this->matchingNothing(['core_object_delet'], ['core_object_delete', 'core_object_get'])
		);
	}

	public function testAnEntryThatMatchesIsNotReported(): void
	{
		$this->assertSame([], $this->matchingNothing(['core_object_delete'], ['core_object_delete']));
	}

	/**
	 * The setting accepts a class name as well as an identifier, so both
	 * spellings have to count as matched or naming the class would be
	 * reported as a typo.
	 */
	public function testAClassNameCountsAsAMatch(): void
	{
		$this->assertSame(
			[],
			$this->matchingNothing([FixtureTool::class], ['test_fixture_tool', FixtureTool::class])
		);
	}

	public function testNothingConfiguredReportsNothing(): void
	{
		$this->assertSame([], $this->matchingNothing([], ['core_object_delete']));
		$this->assertSame([], $this->matchingNothing([], []));
	}

	/**
	 * An instance with no elements at all makes every entry stale, which is
	 * worth saying rather than dividing by zero somewhere.
	 */
	public function testEveryEntryIsStaleWhenNothingIsRegistered(): void
	{
		$this->assertSame(['a', 'b'], $this->matchingNothing(['a', 'b'], []));
	}

	/**
	 * Reported once and in the order written, so the log entry reads like the
	 * config block the operator has open.
	 */
	public function testARepeatedEntryIsReportedOnce(): void
	{
		$this->assertSame(['b', 'a'], $this->matchingNothing(['b', 'a', 'b'], ['known']));
	}

	public function testAnElementIsServedWhenNothingDisablesIt(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), []));
	}

	public function testDisablingByIdentifier(): void
	{
		$this->assertTrue($this->isHidden(new FixtureTool(), ['test_fixture_tool']));
	}

	public function testDisablingByClass(): void
	{
		$this->assertTrue($this->isHidden(new FixtureTool(), [FixtureTool::class]));
	}

	public function testAnUnrelatedEntryDisablesNothing(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), ['core_object_delete', 'Acme\Tools\Whatever']));
	}

	public function testAnElementCanTakeItselfOut(): void
	{
		$this->assertTrue($this->isHidden(new UnavailableTool(), []));
	}

	/**
	 * Empty is the default and has to mean "everything", not "nothing": an
	 * instance that has never heard of toolsets must serve its whole surface.
	 */
	public function testNoToolsetListServesEverything(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), [], []));
	}

	public function testAToolsetThatIsServedLetsTheElementThrough(): void
	{
		$oTool = new FixtureTool();

		$this->assertFalse($this->isHidden($oTool, [], [$oTool->getToolset()]));
	}

	public function testAToolsetThatIsNotServedHidesTheElement(): void
	{
		$this->assertTrue($this->isHidden(new FixtureTool(), [], ['datamodel', 'something-else']));
	}

	/**
	 * A tool that claims nothing about itself is graded delete, so a narrowed
	 * credential does not get it by default. FixtureTool declares no
	 * annotations, which is the case this is about.
	 */
	public function testAnUnannotatedToolIsWithheldFromAReadOnlyCaller(): void
	{
		$oPolicy = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []);

		$this->assertTrue($this->isHidden(new FixtureTool(), [], [], $oPolicy));
	}

	public function testAnUnannotatedToolIsServedWhenNothingIsNarrowed(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), [], [], AccessPolicy::Unrestricted()));
	}

	/**
	 * A pack that says nothing about toolsets gets one named after its
	 * namespace, so an operator can still turn the pack on or off as a whole.
	 */
	public function testTheDefaultToolsetIsTheNamespace(): void
	{
		$oTool = new FixtureTool();

		$this->assertSame($oTool->getNamespace(), $oTool->getToolset());
	}

	/**
	 * What a caller is told it can reach is derived the same way registration
	 * decides what to serve it.
	 *
	 * Not from the policy's own toolset list, which answers a different
	 * question: empty there means "everything", and a toolset whose only
	 * element is disabled, or whose elements all want a profile this user
	 * lacks, is not one the caller can reach whatever the policy allows. The
	 * point of the block is to be believed when it is read, so it has to be
	 * the reachable set rather than the permitted one.
	 */
	public function testServedAccessIsDerivedElementByElement(): void
	{
		$sBody = $this->bodyOf('ServedAccess');

		$this->assertStringContainsString('isHidden', $sBody, 'the served set is not decided the way registration decides it');
		$this->assertStringNotContainsString('$oPolicy->toolsets()', $sBody, 'the permitted list is being reported as the reachable one');
	}

	/**
	 * Only what is served is named. A toolset this caller does not hold would
	 * teach it the shape of the withheld surface, which is what
	 * ServerInstructions narrows itself to avoid - and what comes back must
	 * never exceed what tools/list already showed.
	 */
	public function testServedAccessNamesNothingItDidNotServe(): void
	{
		$sBody = $this->bodyOf('ServedAccess');

		$this->assertStringContainsString('!self::isHidden', $sBody, 'toolsets are collected without asking whether the element is served');
		$this->assertStringNotContainsString('GetKnownToolsets', $sBody);
	}

	/**
	 * An unrestricted policy holds every capability, and says so with null -
	 * which has to be resolved before it is reported, or the block claims a
	 * caller holds nothing.
	 */
	public function testAnUnrestrictedPolicyHasToBeSpeltOut(): void
	{
		$this->assertNull(AccessPolicy::Unrestricted()->capabilities());
		$this->assertSame(
			[AccessPolicy::CAPABILITY_READ, AccessPolicy::CAPABILITY_WRITE, AccessPolicy::CAPABILITY_DELETE],
			AccessPolicy::CAPABILITIES
		);
		$this->assertStringContainsString('?? AccessPolicy::CAPABILITIES', $this->bodyOf('ServedAccess'));
	}

	/** The body of one MCPService method, comments stripped. */
	private function bodyOf(string $sMethod): string
	{
		$oMethod = new ReflectionMethod(MCPService::class, $sMethod);
		$aLines = file($oMethod->getFileName());
		$sBody = implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
