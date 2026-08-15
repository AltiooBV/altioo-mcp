<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

class MCPExtensionCollectorTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->resetCollector();
		MCPRegistry::Clear();
		FixtureServiceProvider::$iCallCount = 0;
	}

	protected function tearDown(): void
	{
		$this->resetCollector();
		MCPRegistry::Clear();
		parent::tearDown();
	}

	private function resetCollector(): void
	{
		$oProperty = (new ReflectionClass(MCPExtensionCollector::class))->getProperty('aExtensionClasses');
		$oProperty->setValue(null, []);
	}

	/** @return array<string, string> */
	private function registeredClasses(): array
	{
		$oProperty = (new ReflectionClass(MCPExtensionCollector::class))->getProperty('aExtensionClasses');

		return $oProperty->getValue();
	}

	public function testAcceptsAServiceProvider(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);

		$this->assertContains(FixtureServiceProvider::class, $this->registeredClasses());
	}

	public function testRejectsAClassThatDoesNotImplementTheContract(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage(iMCPServiceProvider::class);

		MCPExtensionCollector::RegisterServiceProvider(stdClass::class);
	}

	/**
	 * Registration is keyed by class name, so register.php being executed more
	 * than once (setup, then request) must not queue the provider twice.
	 */
	public function testRegisteringTwiceKeepsASingleEntry(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);

		$this->assertCount(1, $this->registeredClasses());
	}

	public function testCollectAllInvokesTheProvider(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);
		MCPExtensionCollector::CollectAll();

		$this->assertGreaterThanOrEqual(1, FixtureServiceProvider::$iCallCount);
		$this->assertArrayHasKey('FixtureTool', MCPRegistry::GetTools());
	}

	/**
	 * CollectAll() walks get_declared_classes() *and* its own list, so a
	 * provider that is both declared and explicitly registered is invoked
	 * twice. That is wasteful but harmless only because the registry is keyed:
	 * this pins the "no duplicates reach the registry" half of that bargain.
	 */
	public function testCollectAllLeavesNoDuplicatesInTheRegistry(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);
		MCPExtensionCollector::CollectAll();
		MCPExtensionCollector::CollectAll();

		$this->assertCount(
			1,
			array_filter(
				array_keys(MCPRegistry::GetTools()),
				static fn (string $sName): bool => $sName === 'FixtureTool'
			)
		);
	}
}
