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
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
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
		// Names carry the namespace: what a client calls is core_object_get, not
		// ObjectGet, so a pack cannot collide with a core tool by accident.
		CoreExtensions::RegisterServiceProvider();

		$this->assertSame(
			[
				'core_class_list',
				'core_class_schema',
				'core_object_apply_stimulus',
				'core_object_bulk_create',
				'core_object_bulk_delete',
				'core_object_bulk_update',
				'core_object_create',
				'core_object_delete',
				'core_object_get',
				'core_object_get_related',
				'core_object_search_by_class',
				'core_object_search_by_oql',
				'core_object_update',
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

		$this->assertSame(['core_my_open_tickets'], $this->sortedKeys(MCPRegistry::GetPrompts()));
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
	 * Every core tool says what it is, and is graded accordingly.
	 *
	 * The grade decides what a narrowed token sees, and an unannotated tool is
	 * graded delete - so a missing annotation here is a tool that vanishes for
	 * every scoped credential. Pinning the whole table also states the
	 * property the grades exist for: MCP-write serves the create, the update
	 * and the stimulus, and not the delete.
	 */
	public function testEveryCoreToolIsGradedAsIntended(): void
	{
		CoreExtensions::RegisterServiceProvider();

		$aExpected = [
			'core_class_list'             => AccessPolicy::CAPABILITY_READ,
			'core_class_schema'           => AccessPolicy::CAPABILITY_READ,
			'core_object_search_by_oql'   => AccessPolicy::CAPABILITY_READ,
			'core_object_search_by_class' => AccessPolicy::CAPABILITY_READ,
			'core_object_get'             => AccessPolicy::CAPABILITY_READ,
			'core_object_get_related'     => AccessPolicy::CAPABILITY_READ,
			'core_object_create'          => AccessPolicy::CAPABILITY_WRITE,
			'core_object_bulk_create'     => AccessPolicy::CAPABILITY_WRITE,
			'core_object_bulk_update'     => AccessPolicy::CAPABILITY_WRITE,
			'core_object_bulk_delete'     => AccessPolicy::CAPABILITY_DELETE,
			'core_object_update'          => AccessPolicy::CAPABILITY_WRITE,
			'core_object_apply_stimulus'  => AccessPolicy::CAPABILITY_WRITE,
			'core_object_delete'          => AccessPolicy::CAPABILITY_DELETE,
		];

		$aActual = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$oAnnotations = $oTool->getAnnotations();
			$this->assertNotNull($oAnnotations, "tool {$sName} declares no annotations, so it is graded delete");

			$aHints = $oAnnotations->jsonSerialize();
			$aActual[$sName] = AccessPolicy::CapabilityOf(
				array_key_exists('readOnlyHint', $aHints) ? (bool)$aHints['readOnlyHint'] : null,
				array_key_exists('destructiveHint', $aHints) ? (bool)$aHints['destructiveHint'] : null
			);
		}

		ksort($aExpected);
		ksort($aActual);
		$this->assertSame($aExpected, $aActual);
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
