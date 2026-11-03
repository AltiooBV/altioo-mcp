<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A supplied value that the write discards is reported, not swallowed.
 *
 * The failure this locks down was live: asked to set a UserRequest's priority
 * - an attribute UserRequest::ComputeValues() derives from urgency and impact -
 * a dry run answered `{"valid": true, "changes": {}}`, and answered it
 * identically for a priority of 2, which is a real value, and a priority of 9,
 * which is not one of the four the enum allows.
 *
 * One cause, two symptoms. CheckToWrite() runs DoComputeValues() before it
 * checks anything, ComputeValues() Set()s the attribute back to the value
 * already stored, DBObject::ListChangedValues() compares it strictly against
 * the original and drops it from the delta - and DoCheckToWrite() validates
 * the delta and nothing else. So the supplied value was neither written nor
 * looked at, and a dry run whose whole purpose is to say what a write would do
 * said nothing at all.
 *
 * Verified in the iTop 3.2.2 source: core/dbobject.class.php
 * (CheckToWrite/DoCheckToWrite/ListChangedValues) and
 * datamodels/2.x/itop-request-mgmt-itil, UserRequest::ComputeValues().
 *
 * These are shape assertions, the way WriteToolContractTest checks the same
 * family: reproducing the behaviour needs a compiled datamodel and a database,
 * while what actually goes wrong is a snapshot taken on the wrong side of
 * CheckToWrite(). That is a shape, and it is the one that rots silently.
 */
class DerivedAttributeReportingContractTest extends TestCase
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
	 * A change the caller never asked for says so.
	 *
	 * `changes` answers "what this write sets", and on a creation that is
	 * every attribute - including ones nobody mentioned. A lnkContactToTicket
	 * created with a contact and a ticket comes back reporting role_code too,
	 * and a caller reading that cannot tell the default it was given from the
	 * value it sent.
	 *
	 * `overridden` already draws the neighbouring line - asked for, not kept -
	 * so the case with no name was: not asked for, applied anyway.
	 */
	public function testAChangeTheCallerNeverAskedForIsNamed(): void
	{
		$this->assertSame(
			['role_code'],
			WritePlan::Defaulted(
				['contact_id' => 1, 'ticket_id' => 3, 'role_code' => 'manual'],
				['contact_id', 'ticket_id']
			),
			'a defaulted attribute is indistinguishable from one the caller set'
		);

		$this->assertSame(
			[],
			WritePlan::Defaulted(['title' => 'x'], ['title']),
			'a write whose every change was asked for reports none'
		);

		foreach (['ObjectCreate', 'ObjectUpdate', 'ObjectApplyStimulus'] as $sTool) {
			$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool;
			$aProperties = (new $sClass())->getOutputSchema()['properties'];
			$this->assertArrayHasKey('defaulted', $aProperties, "{$sTool} does not report it");
			$this->assertContains('defaulted', (new $sClass())->getOutputSchema()['required'], "{$sTool} reports it only sometimes");
		}
	}

	/**
	 * Every tool that takes caller-supplied attribute values and writes them,
	 * with the method that does it: the single-object tools do it in execute(),
	 * ObjectBulkCreate in the per-row helper execute() delegates to.
	 *
	 * Listed rather than discovered from the registry, because what is being
	 * asserted is a property of the write path inside a named method and there
	 * is no annotation that points at it. A write tool added later and not
	 * added here is the gap; {@see testTheListIsTheWholeOfIt()} closes it.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	private function toolsTakingFields(): array
	{
		return [
			'core_object_create'         => [ObjectCreate::class, 'execute'],
			'core_object_update'         => [ObjectUpdate::class, 'execute'],
			'core_object_apply_stimulus' => [ObjectApplyStimulus::class, 'execute'],
			'core_object_bulk_create'    => [ObjectBulkCreate::class, 'createOne'],
			'core_object_bulk_update'    => [ObjectBulkUpdate::class, 'execute'],
		];
	}

	/**
	 * A bulk tool describes one outcome per object, so its promise about
	 * `overridden` sits on the entry rather than on the report.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>}
	 */
	private function outcomeShape(string $sClass): array
	{
		$aSchema = (new $sClass())->getOutputSchema();

		if (array_key_exists('objects', $aSchema['properties'])) {
			$aItems = $aSchema['properties']['objects']['items'];

			return [$aItems['properties'], $aItems['required']];
		}

		return [$aSchema['properties'], $aSchema['required']];
	}

	/**
	 * Guards the list above: a writing tool that takes `fields` and is not
	 * named there would be exempt from every assertion in this file without
	 * anything failing.
	 */
	public function testTheListIsTheWholeOfIt(): void
	{
		$aListed = [];
		foreach ($this->toolsTakingFields() as [$sClass, $sMethod]) {
			$aListed[] = $sClass;
		}

		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aProperties = $oTool->getInputSchema()['properties'] ?? [];
			// 'fields' on the single-object tools and on bulk update, which
			// applies one map to every row; 'objects' on bulk create, which
			// carries one map per row. Both are caller-supplied attribute
			// values reaching Set().
			if (!array_key_exists('fields', $aProperties) && !array_key_exists('objects', $aProperties)) {
				continue;
			}

			$this->assertContains(
				get_class($oTool),
				$aListed,
				"{$sName} takes caller-supplied fields and is not covered here, so nothing checks that it reports a value the write would discard"
			);
		}
	}

	public function testEveryToolTakingFieldsDeclaresWhatItWouldDiscard(): void
	{
		foreach ($this->toolsTakingFields() as $sName => [$sClass, $sMethod]) {
			[$aProperties, $aRequired] = $this->outcomeShape($sClass);

			$this->assertArrayHasKey(
				'overridden',
				$aProperties,
				"{$sName} does not declare `overridden`, so a caller cannot tell a supplied value that was applied from one the write threw away"
			);
			$this->assertContains(
				'overridden',
				$aRequired,
				"{$sName} may omit `overridden`, which makes it two response shapes wearing one schema - the thing WritePlan::OutcomeSchema() exists to prevent"
			);
		}
	}

	public function testTheDeclarationNamesTheCauseAndTheWayOut(): void
	{
		$sDescription = WritePlan::OverriddenSchemaProperty()['description'];

		// A caller that reads only this one string still has to be able to act
		// on it, because on a bare tools/list it is the only string it gets.
		$this->assertStringContainsString('requested', $sDescription);
		$this->assertStringContainsString('effective', $sDescription);
		$this->assertStringContainsString('derived', $sDescription);
	}

	/**
	 * The snapshot has to be taken while the object still holds the request.
	 *
	 * Take it after CheckToWrite() and it records the override, compares equal
	 * to itself, and reports nothing - which is the bug, reintroduced with the
	 * reporting code still in place and still looking correct.
	 */
	public function testTheRequestIsCapturedBeforeTheCheckAndComparedAfterIt(): void
	{
		foreach ($this->toolsTakingFields() as $sName => [$sClass, $sMethod]) {
			$sBody = $this->methodBody($sClass, $sMethod);

			$iRequested  = strpos($sBody, 'WritePlan::Requested');
			$iCheck      = strpos($sBody, 'WritePlan::Check(');
			$iOverridden = strpos($sBody, 'WritePlan::Overridden');

			$this->assertIsInt($iRequested, "{$sName} no longer records what the caller asked for");
			$this->assertIsInt($iCheck, "{$sName} no longer runs iTop's pre-write check");
			$this->assertIsInt($iOverridden, "{$sName} no longer reports what the write would discard");

			$this->assertLessThan(
				$iCheck,
				$iRequested,
				"{$sName} captures the supplied values after CheckToWrite(), which is after DoComputeValues() has already overwritten them - the comparison then always finds them equal"
			);
			$this->assertGreaterThan(
				$iCheck,
				$iOverridden,
				"{$sName} compares before CheckToWrite() has had a chance to override anything, so it can never find one"
			);
		}
	}

	public function testEveryToolTakingFieldsValidatesWhatItWouldDiscard(): void
	{
		foreach ($this->toolsTakingFields() as $sName => [$sClass, $sMethod]) {
			$sBody = $this->methodBody($sClass, $sMethod);

			$this->assertStringContainsString(
				'WritePlan::CheckRequested',
				$sBody,
				"{$sName} leaves a discarded value unchecked, so an impossible value comes back valid: DoCheckToWrite() only ever validates the attributes still in the delta"
			);
		}
	}

	/**
	 * CheckValue() defaults to reading the attribute off the object, which by
	 * then holds the override. Asking it that way would check the value iTop
	 * computed - which is valid by construction - and never the one the caller
	 * sent.
	 */
	public function testTheDiscardedValueIsCheckedRatherThanTheComputedOne(): void
	{
		$sBody = $this->methodBody(WritePlan::class, 'CheckRequested');

		$this->assertStringContainsString('CheckValue(', $sBody);
		$this->assertMatchesRegularExpression(
			'/CheckValue\(\s*\$sAttCode\s*,/',
			$sBody,
			'CheckRequested() must pass the supplied value explicitly; the one-argument form reads the attribute back off the object, which no longer holds it'
		);
	}

	/**
	 * Same rule as WritePlan::Changes(): writing an attribute and reading it
	 * are separate rights, so a plan may name an attribute whose value the
	 * caller may not see.
	 */
	public function testBothSidesOfTheReportApplyTheReadRight(): void
	{
		foreach (['Requested', 'Overridden'] as $sMethod) {
			$sBody = $this->methodBody(WritePlan::class, $sMethod);

			$iGate  = strpos($sBody, 'MayReadAttribute');
			$iValue = strpos($sBody, 'ObjectSerializer::Value');

			$this->assertIsInt($iGate, "WritePlan::{$sMethod}() no longer checks the read right before rendering a value");
			$this->assertIsInt($iValue, "WritePlan::{$sMethod}() no longer renders values");
			$this->assertLessThan($iValue, $iGate, "WritePlan::{$sMethod}() renders before it checks the read right");
			$this->assertStringContainsString('ObjectSerializer::MASK', $sBody, "WritePlan::{$sMethod}() must mask an unreadable value rather than drop it");
		}
	}

	/**
	 * The comparison decides whether anything is reported at all, and it has
	 * to be as strict as the one in DBObject::ListChangedValues() that dropped
	 * the attribute in the first place.
	 */
	public function testTheComparisonIsStrict(): void
	{
		$sBody = $this->methodBody(WritePlan::class, 'Differs');

		$this->assertStringContainsString('!==', $sBody);
		$this->assertStringNotContainsString('!=$', $sBody, 'a loose comparison reads "2" and 2 as the same value, and an enum override as no override');
	}

	/**
	 * The body of a method, comments stripped, so that a doc comment describing
	 * a check cannot stand in for the check.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
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
