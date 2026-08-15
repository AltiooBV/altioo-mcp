<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Who declares an output schema, and what declaring one obliges.
 *
 * A tool that declares one has to send structuredContent with every result:
 * the schema is a promise a client is entitled to validate against, and the
 * SDK builds that half from an array return - which is the path ToolOutput
 * exists to avoid, because it also pretty-prints a second copy into the text
 * content. ToolOutput::Structured() builds both halves itself, and the two
 * belong together: a schema without it advertises a shape that never arrives.
 *
 * The split is deliberate. Writes answer with a handful of scalars whose shape
 * never varies, so a schema describes them and the second copy costs a few
 * hundred bytes. A read answers with objects whose attributes depend on the
 * class and on output_fields, so no fixed schema could describe them and the
 * duplication would be the largest responses this server sends.
 */
class OutputSchemaContractTest extends TestCase
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

	/**
	 * @return array<int, array{0: string, 1: object}>
	 */
	private function writingTools(): array
	{
		$aTools = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aHints = $oTool->getAnnotations()->jsonSerialize();
			$sCapability = AccessPolicy::CapabilityOf(
				array_key_exists('readOnlyHint', $aHints) ? (bool)$aHints['readOnlyHint'] : null,
				array_key_exists('destructiveHint', $aHints) ? (bool)$aHints['destructiveHint'] : null
			);

			if ($sCapability !== AccessPolicy::CAPABILITY_READ) {
				$aTools[] = [$sName, $oTool];
			}
		}

		return $aTools;
	}

	public function testThereAreWritingToolsToCheck(): void
	{
		// Guards the test itself: a discovery bug that finds nothing would
		// otherwise make every assertion below vacuously true.
		$this->assertGreaterThanOrEqual(7, count($this->writingTools()));
	}

	public function testEveryWritingToolDeclaresItsResultShape(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$this->assertIsArray(
				$oTool->getOutputSchema(),
				"{$sName} writes but does not say what it answers with"
			);
		}
	}

	/**
	 * Mcp\Schema\Tool rejects anything else outright, at build time, for every
	 * request - so the whole endpoint goes down rather than the one tool.
	 */
	public function testEveryDeclaredSchemaIsAnObjectSchema(): void
	{
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aSchema = $oTool->getOutputSchema();
			if ($aSchema === null) {
				continue;
			}

			$this->assertSame('object', $aSchema['type'] ?? null, "{$sName}: output schema is not of type object");
			$this->assertIsArray($aSchema['properties'] ?? null, "{$sName}: output schema declares no properties");
		}
	}

	/**
	 * Every name under required has to be a property, or a client validating
	 * the result fails it on a field the server never meant to promise.
	 */
	public function testRequiredNamesAreDeclaredProperties(): void
	{
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aSchema = $oTool->getOutputSchema();
			if ($aSchema === null) {
				continue;
			}

			foreach ($aSchema['required'] ?? [] as $sRequired) {
				$this->assertArrayHasKey(
					$sRequired,
					$aSchema['properties'],
					"{$sName}: '{$sRequired}' is required but not declared"
				);
			}
		}
	}

	/**
	 * The reading tools keep to text: see the class docblock for why the same
	 * choice would be the wrong one there.
	 */
	public function testTheReadingToolsDeclareNoOutputSchema(): void
	{
		$aWriting = array_column($this->writingTools(), 0);

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			if (in_array($sName, $aWriting, true)) {
				continue;
			}

			$this->assertNull(
				$oTool->getOutputSchema(),
				"{$sName} reads: a declared schema obliges structuredContent, which doubles the payload of the largest responses"
			);
		}
	}

	public function testStructuredCarriesBothHalvesOfTheResult(): void
	{
		$aData = ['class' => 'UserRequest', 'id' => 42, 'simulated' => true];

		$oResult = ToolOutput::Structured($aData);

		$this->assertInstanceOf(CallToolResult::class, $oResult);
		$this->assertSame($aData, $oResult->structuredContent, 'the half a client validates');
		$this->assertCount(1, $oResult->content);
		$this->assertInstanceOf(TextContent::class, $oResult->content[0], 'the half a client that ignores schemas reads');
		$this->assertSame($aData, json_decode($oResult->content[0]->text, true), 'the two halves disagree');
		$this->assertFalse($oResult->isError);
	}

	/**
	 * Compact, like Json(): the point of building the text half by hand is not
	 * to get the SDK's pretty-printed one.
	 */
	public function testTheTextHalfIsNotPrettyPrinted(): void
	{
		$oResult = ToolOutput::Structured(['a' => ['b' => 1]]);

		$this->assertStringNotContainsString("\n", $oResult->content[0]->text);
	}
}
