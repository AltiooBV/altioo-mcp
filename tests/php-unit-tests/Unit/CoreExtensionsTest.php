<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The wiring between CoreExtensions and the registry.
 *
 * Registration only instantiates the tool/resource classes and reads their
 * metadata, so it runs without a live iTop - which makes it a cheap guard
 * against a class being renamed, moved, or dropped from the provider.
 */
class CoreExtensionsTest extends TestCase
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

	public function testIsAServiceProvider(): void
	{
		$this->assertContains(iMCPServiceProvider::class, class_implements(CoreExtensions::class));
	}

	public function testRegistersTheExpectedTools(): void
	{
		// Names carry the namespace: what a client calls is core_ObjectGet, not
		// ObjectGet, so a pack cannot collide with a core tool by accident.
		CoreExtensions::RegisterServiceProvider();

		$this->assertSame(
			[
				'core_ClassList',
				'core_ClassSchema',
				'core_ObjectApplyStimulus',
				'core_ObjectCreate',
				'core_ObjectDelete',
				'core_ObjectGet',
				'core_ObjectGetRelated',
				'core_ObjectSearchByClass',
				'core_ObjectSearchByOQL',
				'core_ObjectUpdate',
			],
			$this->sortedKeys(MCPRegistry::GetTools())
		);
	}

	public function testRegistersTheExpectedResources(): void
	{
		CoreExtensions::RegisterServiceProvider();

		$this->assertSame(
			[
				'itop://core/classes',
				'itop://core/current-user',
				'itop://core/version',
			],
			$this->sortedKeys(MCPRegistry::GetResources())
		);
	}

	public function testRegistersTheExpectedResourceTemplates(): void
	{
		CoreExtensions::RegisterServiceProvider();

		$this->assertSame(['itop://core/class/{class}'], $this->sortedKeys(MCPRegistry::GetResourceTemplates()));
	}

	public function testRegistersTheExpectedPrompts(): void
	{
		CoreExtensions::RegisterServiceProvider();

		$this->assertSame(['core_MyOpenTickets'], $this->sortedKeys(MCPRegistry::GetPrompts()));
	}

	public function testEverythingRegisteredHasTheRightBaseType(): void
	{
		CoreExtensions::RegisterServiceProvider();

		foreach (MCPRegistry::GetTools() as $oTool) {
			$this->assertInstanceOf(AbstractMCPTool::class, $oTool);
		}
		foreach (MCPRegistry::GetResources() as $oResource) {
			$this->assertInstanceOf(AbstractMCPResource::class, $oResource);
		}
		foreach (MCPRegistry::GetResourceTemplates() as $oTemplate) {
			$this->assertInstanceOf(AbstractMCPResourceTemplate::class, $oTemplate);
		}
		foreach (MCPRegistry::GetPrompts() as $oPrompt) {
			$this->assertInstanceOf(AbstractMCPPrompt::class, $oPrompt);
		}
	}

	/**
	 * MCPService::registerTools() reads getDescription() and getInputSchema()
	 * off every registered tool while building the server, so a null there
	 * surfaces as a broken tools/list rather than as an obvious error.
	 */
	public function testEveryToolAdvertisesADescriptionAndInputSchema(): void
	{
		CoreExtensions::RegisterServiceProvider();

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$this->assertNotNull($oTool->getDescription(), "tool {$sName} has no description");
			$this->assertNotEmpty($oTool->getDescription(), "tool {$sName} has an empty description");
			$this->assertIsArray($oTool->getInputSchema(), "tool {$sName} has no input schema");
			$this->assertSame('object', $oTool->getInputSchema()['type'] ?? null, "tool {$sName} input schema is not an object");
		}
	}

	public function testEveryResourceAdvertisesATitleAndDescription(): void
	{
		CoreExtensions::RegisterServiceProvider();

		foreach (MCPRegistry::GetResources() as $sUri => $oResource) {
			$this->assertNotEmpty($oResource->getTitle(), "resource {$sUri} has no title");
			$this->assertNotEmpty($oResource->getDescription(), "resource {$sUri} has no description");
		}
	}

	/**
	 * Registration is keyed, so running the provider twice - which CollectAll()
	 * actually does - must not grow the registry.
	 */
	public function testRegisteringTwiceIsIdempotent(): void
	{
		CoreExtensions::RegisterServiceProvider();
		$iTools = count(MCPRegistry::GetTools());
		$iResources = count(MCPRegistry::GetResources());

		CoreExtensions::RegisterServiceProvider();

		$this->assertCount($iTools, MCPRegistry::GetTools());
		$this->assertCount($iResources, MCPRegistry::GetResources());
	}

	/**
	 * @param array<string, mixed> $a
	 * @return array<int, string>
	 */
	private function sortedKeys(array $a): array
	{
		$aKeys = array_keys($a);
		sort($aKeys);

		return $aKeys;
	}
}
