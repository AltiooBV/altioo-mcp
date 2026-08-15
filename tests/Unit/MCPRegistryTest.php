<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Test\Support\FixturePrompt;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResource;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureResourceTemplate;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use PHPUnit\Framework\TestCase;

class MCPRegistryTest extends TestCase
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

	public function testStartsEmpty(): void
	{
		$this->assertSame([], MCPRegistry::GetTools());
		$this->assertSame([], MCPRegistry::GetResources());
		$this->assertSame([], MCPRegistry::GetResourceTemplates());
		$this->assertSame([], MCPRegistry::GetPrompts());
	}

	public function testToolsAreKeyedByName(): void
	{
		$oTool = new FixtureTool();
		MCPRegistry::RegisterTool($oTool);

		$this->assertArrayHasKey('FixtureTool', MCPRegistry::GetTools());
		$this->assertSame($oTool, MCPRegistry::GetTools()['FixtureTool']);
	}

	/**
	 * Keying by name means a later registration silently wins. Extensions rely
	 * on that to override a core tool, so it must not turn into a duplicate.
	 */
	public function testRegisteringTheSameNameReplacesRatherThanDuplicates(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		$oSecond = new FixtureTool();
		MCPRegistry::RegisterTool($oSecond);

		$this->assertCount(1, MCPRegistry::GetTools());
		$this->assertSame($oSecond, MCPRegistry::GetTools()['FixtureTool']);
	}

	public function testResourcesAreKeyedByUri(): void
	{
		$oResource = new FixtureResource();
		MCPRegistry::RegisterResource($oResource);

		$this->assertArrayHasKey('itop://test/fixture', MCPRegistry::GetResources());
		$this->assertSame($oResource, MCPRegistry::GetResources()['itop://test/fixture']);
	}

	public function testResourceTemplatesAreKeyedByUriTemplate(): void
	{
		MCPRegistry::RegisterResourceTemplate(new FixtureResourceTemplate());

		$this->assertArrayHasKey('itop://test/fixture/{id}', MCPRegistry::GetResourceTemplates());
	}

	public function testPromptsAreKeyedByName(): void
	{
		MCPRegistry::RegisterPrompt(new FixturePrompt());

		$this->assertArrayHasKey('FixturePrompt', MCPRegistry::GetPrompts());
	}

	public function testCollectionsAreIndependent(): void
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

	public function testClearEmptiesEveryCollection(): void
	{
		MCPRegistry::RegisterTool(new FixtureTool());
		MCPRegistry::RegisterResource(new FixtureResource());
		MCPRegistry::RegisterResourceTemplate(new FixtureResourceTemplate());
		MCPRegistry::RegisterPrompt(new FixturePrompt());

		MCPRegistry::Clear();

		$this->assertSame([], MCPRegistry::GetTools());
		$this->assertSame([], MCPRegistry::GetResources());
		$this->assertSame([], MCPRegistry::GetResourceTemplates());
		$this->assertSame([], MCPRegistry::GetPrompts());
	}
}
