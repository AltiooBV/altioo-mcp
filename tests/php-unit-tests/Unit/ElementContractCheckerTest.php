<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Api\Testing\ElementContract;
use Altioo\iTop\Extension\MCP\Test\Support\BadlyNamedTool;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwareCoreSubclassInheriting;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwareCoreSubclassNoAsk;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackTool;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackToolAsking;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackToolMentioning;
use Altioo\iTop\Extension\MCP\Test\Support\UndescribedTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * The checker a downstream pack runs against its own elements.
 *
 * What is worth pinning here is not that it finds defects - MCPRegistryTest
 * already covers every clause - but that it finds *the same* defects, and
 * that a clean element comes back with an empty list. A checker that reports
 * something for a correct pack is a checker nobody keeps in their suite.
 */
class ElementContractCheckerTest extends TestCase
{
	protected function setUp(): void
	{
		MCPRegistry::Clear();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
	}

	public function testAWellFormedElementHasNoViolations(): void
	{
		$this->assertSame([], ElementContract::Violations(new FixtureResource()));
		$this->assertSame([], ElementContract::Violations(new FixturePrompt()));
	}

	public function testCheckingDoesNotRegister(): void
	{
		MCPRegistry::Check(new FixtureTool());

		$this->assertSame([], MCPRegistry::GetTools(), 'Check() must not claim an identifier.');
	}

	public function testARefusalCarriesTheSameMessageRegistrationWouldThrow(): void
	{
		$oTool = new BadlyNamedTool();

		try {
			MCPRegistry::RegisterTool($oTool);
			$this->fail('Expected the registry to refuse this tool.');
		} catch (MCPRegistrationException $e) {
			$sThrown = $e->getMessage();
		}

		$aViolations = ElementContract::Violations($oTool);

		$this->assertCount(1, $aViolations);
		$this->assertSame(ElementContract::REFUSAL.$sThrown, $aViolations[0]);
	}

	public function testARefusalIsReportedAloneRatherThanAlongsideWarnings(): void
	{
		// UndescribedTool is both refused (empty description) and unannotated.
		$aViolations = ElementContract::Violations(new UndescribedTool());

		$this->assertCount(1, $aViolations);
		$this->assertStringStartsWith(ElementContract::REFUSAL, $aViolations[0]);
	}

	public function testAnUnannotatedToolIsWarnedAboutRatherThanRefused(): void
	{
		$oTool = new FixtureTool();

		// It registers: an unannotated tool is legal, merely graded harshly.
		MCPRegistry::RegisterTool($oTool);
		$this->assertNotEmpty(MCPRegistry::GetTools());

		$aViolations = ElementContract::Violations($oTool);

		$this->assertCount(1, $aViolations);
		$this->assertStringStartsWith(ElementContract::WARNING, $aViolations[0]);
		$this->assertStringContainsString('getAnnotations()', $aViolations[0]);
	}

	public function testViolationsOfAllKeepsOnlyTheFailingElements(): void
	{
		$aFindings = ElementContract::ViolationsOfAll([
			new FixtureResource(),
			new BadlyNamedTool(),
		]);

		$this->assertSame([BadlyNamedTool::class], array_keys($aFindings));
	}

	public function testSomethingThatIsNotAnElementIsRejectedLegibly(): void
	{
		$aViolations = ElementContract::Violations(new \stdClass());

		$this->assertCount(1, $aViolations);
		$this->assertStringContainsString('AbstractMCPTool', $aViolations[0]);
	}

	/**
	 * ADR 0017: a pack element declaring PortalAware is held to answering
	 * through ObjectAccess::Current(). Reported as a warning, since it
	 * registers and the operator's listing is what serves it.
	 */
	public function testAPortalAwarePackElementThatNeverAsksObjectAccessIsReported(): void
	{
		foreach ([new PortalAwarePackTool(), new PortalAwarePackToolMentioning()] as $oElement) {
			$aPortal = $this->portalFindings($oElement);

			$this->assertCount(1, $aPortal, get_class($oElement));
			$this->assertStringStartsWith(ElementContract::WARNING, $aPortal[0]);
			$this->assertStringContainsString('ObjectAccess::Current()', $aPortal[0]);
			$this->assertStringContainsString('mcp_portal_extensions', $aPortal[0]);
		}
	}

	public function testAPortalAwarePackElementThatCallsObjectAccessCurrentIsNotReported(): void
	{
		$this->assertSame([], $this->portalFindings(new PortalAwarePackToolAsking()));
	}

	public function testAPackElementThatDoesNotDeclarePortalAwareIsNotAskedToServeThePortal(): void
	{
		$this->assertSame([], $this->portalFindings(new FixtureTool()));
	}

	/**
	 * A class declared in the Core\ PHP namespace by a pack is not this
	 * module's own, so it gets no exemption.
	 */
	public function testASpoofedCoreNamespaceDoesNotExemptAPortalAwareElement(): void
	{
		require_once dirname(__DIR__).'/Support/SpoofedCorePortalTool.php';

		$aPortal = $this->portalFindings(new \Altioo\iTop\Extension\MCP\Core\Spoof\SpoofedCorePortalTool());

		$this->assertCount(1, $aPortal);
		$this->assertStringStartsWith(ElementContract::WARNING, $aPortal[0]);
	}

	/**
	 * Red-team F1 (2026-11-12): a core parent's source does not stand in for the
	 * pack's own. A subclass of a core tool that replaces execute() and reads
	 * through UserRights alone is reported, as any pack element would be.
	 */
	public function testASubclassOfACoreToolThatReplacesExecuteWithoutAskingIsReported(): void
	{
		$aPortal = $this->portalFindings(new PortalAwareCoreSubclassNoAsk());

		$this->assertCount(1, $aPortal);
		$this->assertStringStartsWith(ElementContract::WARNING, $aPortal[0]);
		$this->assertStringContainsString('ObjectAccess::Current()', $aPortal[0]);
	}

	/** A subclass that leaves execute() to core runs core's code, which asks. */
	public function testASubclassOfACoreToolThatKeepsItsExecuteIsNotReported(): void
	{
		$this->assertSame([], $this->portalFindings(new PortalAwareCoreSubclassInheriting()));
	}

	public function testACoreElementIsNotAskedForIt(): void
	{
		$this->assertSame([], $this->portalFindings(new ClassList()));
	}

	/**
	 * @return array<int, string>
	 */
	private function portalFindings(object $oElement): array
	{
		return array_values(array_filter(
			ElementContract::Violations($oElement),
			static fn (string $sFinding): bool => str_contains($sFinding, 'PortalAware')
		));
	}
}
