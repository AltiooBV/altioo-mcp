<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Descriptions are instructions, and they are checked like instructions.
 *
 * What a tool says is the only guidance a model gets about what to call next.
 * A description naming a resource URI that nothing serves, or a tool that does
 * not exist, does not fail anywhere: the model follows it, the call comes back
 * "unknown tool", and the model works around it by guessing attribute codes -
 * which is the failure this whole surface exists to prevent.
 *
 * Six descriptions shipped pointing at itop://iTop/..., a namespace that was
 * renamed to core, and one pointed at "itop_object_search", a tool that never
 * existed under that name.
 *
 * Registration only reads metadata, so this needs no iTop.
 */
class ElementDescriptionContractTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	public function testEveryResourceUriMentionedIsOneThatIsServed(): void
	{
		$aServed = array_merge(
			array_keys(MCPRegistry::GetResources()),
			array_keys(MCPRegistry::GetResourceTemplates())
		);

		$aDangling = [];
		foreach ($this->modelFacingText() as $sWhere => $sText) {
			foreach ($this->urisIn($sText) as $sUri) {
				if (!in_array($sUri, $aServed, true)) {
					$aDangling[] = "{$sWhere}: {$sUri}";
				}
			}
		}

		$this->assertSame([], $aDangling, "Resource URIs mentioned but not served:\n".implode("\n", $aDangling));
	}

	public function testEveryToolMentionedIsOneThatIsRegistered(): void
	{
		$aRegistered = array_merge(
			array_keys(MCPRegistry::GetTools()),
			array_keys(MCPRegistry::GetPrompts())
		);

		$aDangling = [];
		foreach ($this->modelFacingText() as $sWhere => $sText) {
			foreach ($this->coreIdentifiersIn($sText) as $sName) {
				if (!in_array($sName, $aRegistered, true)) {
					$aDangling[] = "{$sWhere}: {$sName}";
				}
			}
		}

		$this->assertSame([], $aDangling, "Tools mentioned but not registered:\n".implode("\n", $aDangling));
	}

	/**
	 * Every string a client may put in front of a model, keyed by where it
	 * came from: tool and resource descriptions, and the description of every
	 * property of every input schema.
	 *
	 * @return array<string, string>
	 */
	private function modelFacingText(): array
	{
		$aText = [];

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aText[$sName.' description'] = (string)$oTool->getDescription();

			foreach ($oTool->getInputSchema()['properties'] ?? [] as $sProperty => $aProperty) {
				$aText[$sName.' property '.$sProperty] = (string)($aProperty['description'] ?? '');
			}
		}

		foreach (MCPRegistry::GetResources() as $sUri => $oResource) {
			$aText[$sUri.' description'] = (string)$oResource->getDescription();
		}

		foreach (MCPRegistry::GetResourceTemplates() as $sUri => $oTemplate) {
			$aText[$sUri.' description'] = (string)$oTemplate->getDescription();
		}

		foreach (MCPRegistry::GetPrompts() as $sName => $oPrompt) {
			$aText[$sName.' description'] = (string)$oPrompt->getDescription();
		}

		return $aText;
	}

	/** @return array<int, string> */
	private function urisIn(string $sText): array
	{
		if (!preg_match_all('#itop://[A-Za-z0-9_{}/\-]+#', $sText, $aMatches)) {
			return [];
		}

		return array_unique($aMatches[0]);
	}

	/**
	 * Identifiers in the reserved namespace only. A pack's own tools are named
	 * in its own descriptions, and this module cannot know them.
	 *
	 * @return array<int, string>
	 */
	private function coreIdentifiersIn(string $sText): array
	{
		if (!preg_match_all('/\bcore_[A-Za-z0-9_]+/', $sText, $aMatches)) {
			return [];
		}

		return array_unique($aMatches[0]);
	}
}
