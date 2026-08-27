<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
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

	/**
	 * One tool, one shape - whatever `simulate` was.
	 *
	 * A tool answering with one set of keys on a dry run and another on a real
	 * write would have to declare the union as its schema, with `required`
	 * narrowed to the intersection. A schema like that describes neither
	 * response: nothing validating it can catch a create that came back with no
	 * id, and the consumer that actually matters reads it as prose and cannot
	 * tell which fields to expect when.
	 *
	 * Requiring every declared property is the structural form of the rule.
	 * A field whose value varies is fine and is the point; a field that comes
	 * and goes is a second interface hiding inside the first.
	 */
	public function testEveryDeclaredPropertyIsRequired(): void
	{
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aSchema = $oTool->getOutputSchema();
			if ($aSchema === null) {
				continue;
			}

			$this->assertSame(
				[],
				array_values(array_diff(array_keys($aSchema['properties']), $aSchema['required'] ?? [])),
				"{$sName}: these properties are declared but not required, so the response shape depends on something the schema does not say"
			);
		}
	}

	/**
	 * The same rule one level down, where a bulk tool describes its per-object
	 * entries. A batch can half succeed, so the caller reading those entries is
	 * precisely the one who cannot afford to guess which keys are there.
	 */
	public function testEveryDeclaredPropertyOfABulkEntryIsRequired(): void
	{
		$iChecked = 0;

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aItems = $oTool->getOutputSchema()['properties']['objects']['items'] ?? null;
			if (!is_array($aItems) || !isset($aItems['properties'])) {
				continue;
			}
			++$iChecked;

			$this->assertSame(
				[],
				array_values(array_diff(array_keys($aItems['properties']), $aItems['required'] ?? [])),
				"{$sName}: a per-object entry declares properties it does not always report"
			);
		}

		$this->assertGreaterThanOrEqual(3, $iChecked, 'the bulk tools were not found, so nothing above was checked');
	}

	/**
	 * The prose form of the same defect, and the one that reaches the model:
	 * a description saying a field is "present only" under some condition is a
	 * schema admitting it describes more than one shape.
	 */
	public function testNoPropertyDescribesItselfAsConditional(): void
	{
		$aTells = ['present only', 'present on a dry run', 'absent from', 'omitted when'];

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aSchema = $oTool->getOutputSchema();
			if ($aSchema === null) {
				continue;
			}

			foreach ($this->describedProperties($aSchema) as $sProperty => $sDescription) {
				foreach ($aTells as $sTell) {
					$this->assertStringNotContainsStringIgnoringCase(
						$sTell,
						$sDescription,
						"{$sName}.{$sProperty}: a property that is sometimes there is a second response shape. Report it always, with a null or empty value when it has nothing to say."
					);
				}
			}
		}
	}

	/**
	 * Property name => description, one level into the per-object entries too.
	 *
	 * @param array<string, mixed> $aSchema
	 *
	 * @return array<string, string>
	 */
	private function describedProperties(array $aSchema): array
	{
		$aDescriptions = [];

		foreach ($aSchema['properties'] ?? [] as $sName => $aProperty) {
			if (is_array($aProperty) && is_string($aProperty['description'] ?? null)) {
				$aDescriptions[$sName] = $aProperty['description'];
			}
		}

		foreach ($aSchema['properties']['objects']['items']['properties'] ?? [] as $sName => $aProperty) {
			if (is_array($aProperty) && is_string($aProperty['description'] ?? null)) {
				$aDescriptions['objects[].'.$sName] = $aProperty['description'];
			}
		}

		return $aDescriptions;
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
