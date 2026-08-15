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
use Altioo\iTop\Extension\MCP\Test\Support\BrokenServiceProvider;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

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

		$this->assertSame(1, FixtureServiceProvider::$iCallCount);
		$this->assertArrayHasKey('FixtureTool', MCPRegistry::GetTools());
	}

	/**
	 * Explicit registrations and discovered classes are merged before anything
	 * runs, so a provider that is both - which CoreExtensions is, since
	 * register.php declares it and discovery finds it - is still invoked once.
	 * Registration happens to be idempotent, but a provider may legitimately do
	 * setup work here, and running that twice a request is a trap.
	 */
	public function testAProviderRunsOncePerCollection(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);
		MCPExtensionCollector::CollectAll();

		$this->assertSame(1, FixtureServiceProvider::$iCallCount);
	}

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

	/**
	 * A tool pack that throws while registering - a contract violation, a
	 * missing class of its own - must cost the endpoint that pack, not every
	 * pack.
	 */
	public function testAFailingProviderIsSkippedAndTheOthersStillRun(): void
	{
		MCPExtensionCollector::RegisterServiceProvider(BrokenServiceProvider::class);
		MCPExtensionCollector::RegisterServiceProvider(FixtureServiceProvider::class);

		MCPExtensionCollector::CollectAll();

		$this->assertSame(1, FixtureServiceProvider::$iCallCount);
		$this->assertArrayHasKey('FixtureTool', MCPRegistry::GetTools());
	}
}
