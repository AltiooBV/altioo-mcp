<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Resources\Instructions as InstructionsResource;
use Altioo\iTop\Extension\MCP\Core\Tools\Instructions as InstructionsTool;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The fetchable instructions say what the handshake says.
 *
 * `initialize` is the only message the SDK carries
 * Configuration::$instructions in, and protocol revision `2026-07-28` has no
 * `initialize` - so core_instructions and itop://core/instructions exist to be
 * the copy a session on that revision can ask for. The whole value of that
 * copy is that it is the same text: a client following the fetched block and
 * one following the handshake block must not be able to reach different
 * conclusions about how to encode a date or what a refusal means.
 *
 * Here rather than in the unit suite because rendering the block reads the
 * module's configuration - which prompts this session is served depends on
 * mcp_disabled_tools and on the registry - and that needs a configured
 * instance rather than a policy object.
 *
 * @group integration
 */
class CoreInstructionsTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPExtensionCollector::CollectAll();
	}

	protected function tearDown(): void
	{
		AccessPolicy::Forget();
		parent::tearDown();
	}

	public function testBothSurfacesServeWhatInitializeServes(): void
	{
		foreach ($this->policies() as $sLabel => $oPolicy) {
			AccessPolicy::Forget();
			AccessPolicy::Remember($oPolicy);

			$sHandshake = MCPService::InstructionsFor($oPolicy);

			$this->assertNotSame('', $sHandshake, $sLabel.': the block is empty, so this would pass on nothing');
			$this->assertSame($sHandshake, InstructionsTool::execute(), $sLabel.': the tool answers something the handshake does not');
			$this->assertSame($sHandshake, (new InstructionsResource())->read(), $sLabel.': the resource answers something the handshake does not');
		}
	}

	/**
	 * The block is rendered for the caller asking: it names the prompts this
	 * session is served and grades what it may write. Serving one caller's to
	 * another would describe a server they are not being offered.
	 */
	public function testTheBlockFollowsTheCallerRatherThanTheInstance(): void
	{
		AccessPolicy::Remember(AccessPolicy::Unrestricted());
		$sUnrestricted = InstructionsTool::execute();

		AccessPolicy::Forget();
		AccessPolicy::Remember(AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []));
		$sReadOnly = InstructionsTool::execute();

		$this->assertNotSame($sUnrestricted, $sReadOnly, 'the same text is served whatever the caller may do');
	}

	/** @return array<string, AccessPolicy> */
	private function policies(): array
	{
		return [
			'unrestricted' => AccessPolicy::Unrestricted(),
			'read-only'    => AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []),
			'server only'  => AccessPolicy::Of([], ['server']),
		];
	}
}
