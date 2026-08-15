<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Test\Support\AlsoClashingTool;
use Altioo\iTop\Extension\MCP\Test\Support\BadlyNamedTool;
use Altioo\iTop\Extension\MCP\Test\Support\BadlyNamespacedTool;
use Altioo\iTop\Extension\MCP\Test\Support\ClashingTool;
use Altioo\iTop\Extension\MCP\Test\Support\BadProfilesTool;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResourceTemplate;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\HandlerlessPrompt;
use Altioo\iTop\Extension\MCP\Test\Support\HandlerlessTool;
use Altioo\iTop\Extension\MCP\Test\Support\InconsistentSchemaTool;
use Altioo\iTop\Extension\MCP\Test\Support\OverridingTool;
use Altioo\iTop\Extension\MCP\Test\Support\PrivateHandlerTool;
use Altioo\iTop\Extension\MCP\Test\Support\SelfOverridingTool;
use Altioo\iTop\Extension\MCP\Test\Support\SquattingTool;
use Altioo\iTop\Extension\MCP\Test\Support\TemplatedResource;
use Altioo\iTop\Extension\MCP\Test\Support\UnboundArgumentTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnboundResourceTemplate;
use Altioo\iTop\Extension\MCP\Test\Support\UndescribedTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnsatisfiableTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnschematisedTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnvaryingResourceTemplate;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';
// Several classes in one file, none of them PSR-4 addressable.
require_once __DIR__.'/../Support/InvalidTools.php';

/**
 * What registration refuses to accept.
 *
 * Each of these defects is invisible until a client calls the element - and
 * then surfaces as an empty tools/list or an unrelated "missing argument"
 * from deep inside the SDK. Catching them at boot is the whole point of
 * validating here rather than declaring more abstract methods: an abstract can
 * force a method to exist, not to agree with the schema next to it.
 */
class MCPRegistryContractTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	/** @return array<string, array{0: object, 1: string}> */
	public static function rejectedToolProvider(): array
	{
		return [
			'name the MCP schema rejects' => [new BadlyNamedTool(), 'bad name!'],
			'no description' => [new UndescribedTool(), 'getDescription()'],
			'schema is not an object' => [new UnschematisedTool(), 'type "object"'],
			'requires an undeclared property' => [new InconsistentSchemaTool(), 'ghost'],
			'property bound to no parameter' => [new UnboundArgumentTool(), 'identifier'],
			'mandatory parameter never asked for' => [new UnsatisfiableTool(), 'class'],
			'no execute()' => [new HandlerlessTool(), 'no execute() method'],
			'execute() is not public' => [new PrivateHandlerTool(), 'must be public'],
			'requiredProfiles() is not a list of names' => [new BadProfilesTool(), 'requiredProfiles()'],
		];
	}

	/**
	 * @dataProvider rejectedToolProvider
	 */
	public function testRejectsATool(object $oTool, string $sExpectedInMessage): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/'.preg_quote($sExpectedInMessage, '/').'/');

		MCPRegistry::RegisterTool($oTool);
	}

	/**
	 * @dataProvider rejectedToolProvider
	 */
	public function testARejectedToolIsNotRegistered(object $oTool): void
	{
		try {
			MCPRegistry::RegisterTool($oTool);
		} catch (MCPRegistrationException $e) {
			// expected
		}

		$this->assertSame([], MCPRegistry::GetTools());
	}

	public function testRejectsAFixedResourceWithAVariableInItsUri(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/AbstractMCPResourceTemplate/');

		MCPRegistry::RegisterResource(new TemplatedResource());
	}

	public function testRejectsATemplateWithoutAVariable(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/AbstractMCPResource instead/');

		MCPRegistry::RegisterResourceTemplate(new UnvaryingResourceTemplate());
	}

	/**
	 * The SDK binds URI variables to read() by parameter name, so a mismatch
	 * makes every read of that template fail on a missing argument.
	 */
	public function testRejectsATemplateWhoseVariableMatchesNoParameter(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/ticket/');

		MCPRegistry::RegisterResourceTemplate(new UnboundResourceTemplate());
	}

	public function testRejectsAPromptWithoutAHandler(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/no get\(\) method/');

		MCPRegistry::RegisterPrompt(new HandlerlessPrompt());
	}

	public function testAcceptsTheFixtures(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterResource(new FixtureResource());
		MCPRegistry::RegisterResourceTemplate(new FixtureResourceTemplate());
		MCPRegistry::RegisterPrompt(new FixturePrompt());

		$this->assertCount(1, MCPRegistry::GetTools());
		$this->assertCount(1, MCPRegistry::GetResources());
		$this->assertCount(1, MCPRegistry::GetResourceTemplates());
		$this->assertCount(1, MCPRegistry::GetPrompts());
	}

	/**
	 * The case the namespacing exists for: two vendors, no knowledge of each
	 * other, one identifier. Awarding it to whoever loaded last would have the
	 * client call the tool it was shown and run the other vendor's code, so it
	 * is awarded to neither.
	 */
	public function testAnAccidentalClashWithdrawsTheIdentifier(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new ClashingTool());

		$this->assertArrayNotHasKey('test_FixtureTool', MCPRegistry::GetTools());
		$this->assertSame(
			[FixtureTool::class, ClashingTool::class],
			MCPRegistry::GetClashes()['tool: test_FixtureTool'] ?? null
		);
	}

	/**
	 * Withdrawal is per identifier: the rest of both packs is untouched.
	 */
	public function testAClashDoesNotAffectTheRestOfTheRegistry(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new ClashingTool());
		MCPRegistry::RegisterPrompt(new FixturePrompt());

		$this->assertSame([], MCPRegistry::GetTools());
		$this->assertArrayHasKey('test_FixturePrompt', MCPRegistry::GetPrompts());
	}

	/**
	 * A late arrival cannot resurrect a withdrawn identifier by registering
	 * after the two that lost it.
	 */
	public function testAThirdClaimantDoesNotWinAWithdrawnIdentifier(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new ClashingTool());
		MCPRegistry::RegisterTool(new AlsoClashingTool());

		$this->assertArrayNotHasKey('test_FixtureTool', MCPRegistry::GetTools());
		$this->assertContains(AlsoClashingTool::class, MCPRegistry::GetClashes()['tool: test_FixtureTool']);
	}

	/**
	 * Declaring the override is what separates a replacement from a clash.
	 */
	public function testADeclaredOverrideTakesTheIdentifier(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new OverridingTool());

		$this->assertInstanceOf(OverridingTool::class, MCPRegistry::GetTools()['test_FixtureTool']);
		$this->assertSame([], MCPRegistry::GetClashes());
		$this->assertSame(
			[FixtureTool::class, OverridingTool::class],
			MCPRegistry::GetOverrides()['tool: test_FixtureTool'] ?? null
		);
	}

	/**
	 * And it holds whichever module the setup loaded first - otherwise the
	 * outcome would still be decided by load order, just less visibly.
	 */
	public function testADeclaredOverrideWinsRegisteredEitherWayRound(): void
	{
		MCPRegistry::RegisterTool(new OverridingTool());
		MCPRegistry::RegisterTool(new FixtureTool());

		$this->assertInstanceOf(OverridingTool::class, MCPRegistry::GetTools()['test_FixtureTool']);
		$this->assertSame([], MCPRegistry::GetClashes());
	}

	/**
	 * Registering the same class twice is what CollectAll() does to a provider
	 * that is both discovered and explicitly declared: not a clash.
	 */
	public function testReRegisteringTheSameClassIsNeitherOverrideNorClash(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new FixtureTool());

		$this->assertArrayHasKey('test_FixtureTool', MCPRegistry::GetTools());
		$this->assertSame([], MCPRegistry::GetOverrides());
		$this->assertSame([], MCPRegistry::GetClashes());
	}

	/**
	 * 'core' is this module's, exactly as the core resource URI namespace is.
	 */
	public function testRejectsAnExtensionClaimingTheReservedNamespace(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/belongs to the base extension/');

		MCPRegistry::RegisterTool(new SquattingTool());
	}

	public function testRejectsANamespaceThatIsNotUsableInAnIdentifier(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/not a usable namespace/');

		MCPRegistry::RegisterTool(new BadlyNamespacedTool());
	}

	public function testRejectsAnElementThatOverridesItself(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessageMatches('/names this element itself/');

		MCPRegistry::RegisterTool(new SelfOverridingTool());
	}

	public function testClearForgetsOverridesAndClashes(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterTool(new OverridingTool());
		MCPRegistry::RegisterPrompt(new FixturePrompt());
		MCPRegistry::RegisterTool(new ClashingTool());

		MCPRegistry::Clear();

		$this->assertSame([], MCPRegistry::GetOverrides());
		$this->assertSame([], MCPRegistry::GetClashes());
	}
}
