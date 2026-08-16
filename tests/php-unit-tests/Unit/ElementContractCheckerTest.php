<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Testing\ElementContract;
use Altioo\iTop\Extension\MCP\Test\Support\BadlyNamedTool;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\UndescribedTool;
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
}
