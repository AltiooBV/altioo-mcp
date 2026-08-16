<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Nothing in the core surface writes on a first call.
 *
 * An MCP client is driven by a model acting on instructions that may have come
 * from outside the organisation, so "create it, then tell me" is the wrong
 * default for every write, not only for the destructive one. The rule
 * core_object_delete followed alone now holds for all of them, and the test
 * discovers the tools from the registry rather than listing them, so a write
 * tool added later is covered by the same rule the day it is registered.
 */
class WriteToolContractTest extends TestCase
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

	public function testEveryWritingToolOffersADryRun(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$aProperties = $oTool->getInputSchema()['properties'];

			$this->assertArrayHasKey('simulate', $aProperties, "{$sName} cannot be asked what it would do");
			$this->assertSame('boolean', $aProperties['simulate']['type'], "{$sName}: simulate is not a boolean");
		}
	}

	public function testEveryWritingToolDefaultsToDoingNothing(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$aSchema = $oTool->getInputSchema();

			$this->assertTrue(
				$aSchema['properties']['simulate']['default'],
				"{$sName} writes when the model omits simulate"
			);
			$this->assertNotContains('simulate', $aSchema['required'], "{$sName} forces the caller to decide");
		}
	}

	/**
	 * A default in the schema that the signature does not share is a default
	 * in name only: the SDK binds by name, and a parameter the client did not
	 * send takes the value PHP has, not the value the schema advertises.
	 */
	public function testTheDefaultInTheSchemaIsTheDefaultInTheSignature(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$aSimulate = array_values(array_filter(
				(new ReflectionMethod($oTool, 'execute'))->getParameters(),
				static fn (\ReflectionParameter $oParameter): bool => $oParameter->getName() === 'simulate'
			));

			$this->assertCount(1, $aSimulate, "{$sName}::execute() takes no simulate");
			$this->assertTrue($aSimulate[0]->isDefaultValueAvailable(), "{$sName}: simulate is mandatory in PHP");
			$this->assertSame(
				WritePlan::SIMULATE_BY_DEFAULT,
				$aSimulate[0]->getDefaultValue(),
				"{$sName} advertises a dry run and applies something else"
			);
		}
	}

	/**
	 * The two-step is only followed if the description says so; the schema
	 * default alone is not something a model reasons about out loud.
	 */
	public function testEveryWritingToolExplainsTheTwoCalls(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$sDescription = (string)$oTool->getDescription();

			$this->assertStringContainsString('simulate=true', $sDescription, "{$sName} does not describe the dry run");
			$this->assertStringContainsString('simulate=false', $sDescription, "{$sName} does not say how to go through with it");
		}
	}

	/**
	 * Every write can say why it was made.
	 *
	 * The channel is filled in from the request whether anyone asks for it or
	 * not, so a tool without this parameter still lands in the history
	 * attributed. What it cannot do is carry the reason the user gave, and the
	 * reason is the half of the row that the audit trail does not already hold
	 * elsewhere.
	 */
	public function testEveryWritingToolCanRecordWhy(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$aProperties = $oTool->getInputSchema()['properties'];

			$this->assertArrayHasKey('comment', $aProperties, "{$sName} cannot say why it wrote");
			$this->assertSame('string', $aProperties['comment']['type'], "{$sName}: comment is not a string");
		}
	}

	/**
	 * Optional in the schema and optional in the signature. A model that has
	 * nothing to say must be able to leave it out - and the SDK binds by name,
	 * so a parameter the client did not send takes the value PHP has.
	 */
	public function testTheReasonIsOptionalOnBothSides(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$this->assertNotContains('comment', $oTool->getInputSchema()['required'], "{$sName} forces a reason to be invented");

			$aComment = array_values(array_filter(
				(new ReflectionMethod($oTool, 'execute'))->getParameters(),
				static fn (\ReflectionParameter $oParameter): bool => $oParameter->getName() === 'comment'
			));

			$this->assertCount(1, $aComment, "{$sName}::execute() takes no comment");
			$this->assertTrue($aComment[0]->isDefaultValueAvailable(), "{$sName}: comment is mandatory in PHP");
			$this->assertNull($aComment[0]->getDefaultValue(), "{$sName} defaults to a reason nobody gave");
		}
	}
}
