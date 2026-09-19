<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Resources\Instructions as InstructionsResource;
use Altioo\iTop\Extension\MCP\Core\Resources\Version;
use Altioo\iTop\Extension\MCP\Core\Tools\CurrentUser as CurrentUserTool;
use Altioo\iTop\Extension\MCP\Core\Tools\Instructions as InstructionsTool;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The instructions, on the two surfaces that carry them for a client that was
 * never handed them.
 *
 * `initialize` is the only message the SDK puts Configuration::$instructions
 * in, and protocol revision `2026-07-28` has no `initialize` - so a session on
 * it is served without ever being told how this instance encodes a date or
 * what a refusal means. core_instructions and itop://core/instructions are
 * that block, fetchable.
 *
 * What is checked here is the half that needs no instance: that they are
 * served wherever the identity elements are served, and that the resource
 * claims the priority that says so. A session narrowed to the point where it
 * cannot ask how to use the server is a session that guesses instead, which is
 * what the whole block exists to prevent.
 *
 * The other half - that both surfaces serve what the handshake serves - needs
 * a configured iTop, because rendering the block reads the module's settings
 * to know which prompts this session is offered. It lives in the integration
 * suite, in the test of the same name.
 */
class CoreInstructionsTest extends TestCase
{
	protected function tearDown(): void
	{
		AccessPolicy::Forget();
		parent::tearDown();
	}

	/**
	 * @dataProvider surfaces
	 */
	public function testNeitherSurfaceInventsAPolicyItWasNotGiven(callable $fnRead, string $sExpected): void
	{
		AccessPolicy::Forget();

		$this->expectException($sExpected);
		$fnRead();
	}

	/**
	 * Served wherever the identity is served. Asserted against those elements
	 * rather than against the literal 'server', so that a toolset rename moves
	 * all four together instead of stranding this one.
	 */
	public function testItIsAsAvailableAsTheIdentityElements(): void
	{
		$this->assertSame((new CurrentUserTool())->getToolset(), (new InstructionsTool())->getToolset(), 'the tool is not in the toolset core_current_user is in');
		$this->assertSame((new Version())->getToolset(), (new InstructionsResource())->getToolset(), 'the resource is not in the toolset itop://core/version is in');

		foreach ([new InstructionsTool(), new InstructionsResource()] as $oElement) {
			$this->assertSame([], $oElement->requiredProfiles(), get_class($oElement).' demands a profile, so a session can be narrowed out of asking how to use the server');
			$this->assertTrue($oElement->isAvailable(), get_class($oElement).' is not always available');
		}

		$this->assertTrue((new InstructionsTool())->getAnnotations()?->readOnlyHint, 'the tool is not read-only, so a read-only session is not served it');
	}

	/**
	 * Priority 1 is the highest the schema allows - "most important for
	 * operating the server" - which is the same grade itop://core/version
	 * carries and the strongest thing a resource can say about itself.
	 */
	public function testTheResourceSaysItMattersMost(): void
	{
		$oAnnotations = (new InstructionsResource())->getAnnotations();

		$this->assertNotNull($oAnnotations, 'the resource carries no annotations at all');
		$this->assertSame(1.0, (float)$oAnnotations->priority, 'the resource does not claim the highest priority');
		$this->assertSame([Role::Assistant], $oAnnotations->audience, 'the resource is not addressed to the assistant');
		$this->assertSame(
			(new Version())->getAnnotations()?->priority,
			$oAnnotations->priority,
			'the two things a session should read first no longer claim the same priority'
		);
	}

	/** @return array<string, array{0: callable, 1: string}> */
	public function surfaces(): array
	{
		return [
			'the tool'     => [static fn() => InstructionsTool::execute(), ToolCallException::class],
			'the resource' => [static fn() => (new InstructionsResource())->read(), ResourceReadException::class],
		];
	}
}
