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
 * default for every write, not only for the destructive one. The rule holds
 * for every write tool, and the test discovers them from the registry rather
 * than listing them, so a write tool added later is covered by the same rule
 * the day it is registered.
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
	 * The two-step has to be legible from the tool itself, not only from the
	 * instructions: an instruction block is advisory, some clients drop it,
	 * and a schema default alone is not something a model reasons about out
	 * loud. What the tool carries is what a client cannot drop.
	 *
	 * Description *or* the simulate property, because the prose no longer
	 * repeats what the property says - the shared protocol moved into
	 * ServerInstructions, which is narrowed per caller and sent once instead
	 * of once per tool. The guarantee is unchanged; the place it is kept is
	 * the cheaper of the two.
	 */
	public function testEveryWritingToolExplainsTheTwoCalls(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$sAtCallTime = (string)$oTool->getDescription()
				.json_encode($oTool->getInputSchema()['properties']['simulate'] ?? []);

			$this->assertStringContainsString('simulate=false', $sAtCallTime, "{$sName} does not say how to go through with it");
			$this->assertStringContainsString(
				'default',
				$sAtCallTime,
				"{$sName} does not say the dry run is what a first call does"
			);
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

	/**
	 * A dry run reports what a caller may not read as masked, never in clear.
	 *
	 * The masking of sensitive attributes lives in ObjectSerializer::Value(),
	 * but the read *right* is applied by Serialize() - and a write plan does
	 * not go through Serialize(). Writing an attribute and reading it are
	 * separate rights in iTop, and iTop fills in more attributes than the
	 * caller named, because DoComputeValues() and the lifecycle set their own
	 * from data the caller may have no right to. So the gate has to be applied
	 * here explicitly, and it was not.
	 *
	 * Checked as a shape rather than a behaviour, the way
	 * AttributeRightsContractTest checks the same family: reproducing it needs
	 * a rights addon that grades attributes per object and a live database,
	 * while what actually goes wrong is Value() being called without the gate
	 * in front of it. That is a shape.
	 */
	public function testTheWritePlanAppliesTheReadRightBeforeRenderingAValue(): void
	{
		$sBody = $this->methodBody(WritePlan::class, 'Changes');

		$iGate = strpos($sBody, 'MayReadAttribute');
		$iValue = strpos($sBody, 'ObjectSerializer::Value');

		$this->assertIsInt($iGate, 'WritePlan::Changes() no longer checks the read right, so a dry run can render an attribute the caller may write but not read');
		$this->assertIsInt($iValue, 'WritePlan::Changes() no longer renders values');
		$this->assertLessThan($iValue, $iGate, 'the read right must be checked before the value is rendered');
		$this->assertStringContainsString(
			'ObjectSerializer::MASK',
			$sBody,
			'an unreadable attribute must be reported masked, not dropped: a dry run that omits part of what the write does is worse than one that says the value is hidden'
		);
	}

	/**
	 * The body of a method, comments stripped, so that a doc comment describing
	 * a check cannot stand in for the check.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new \ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());
		$sSource = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sSource) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
