<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
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
	/**
	 * The tools that take caller-supplied attribute values and write them.
	 *
	 * @return array<string, class-string>
	 */
	private function toolsTakingFields(): array
	{
		return [
			'core_object_create'         => ObjectCreate::class,
			'core_object_update'         => ObjectUpdate::class,
			'core_object_apply_stimulus' => ObjectApplyStimulus::class,
		];
	}

	public function testEveryToolTakingFieldsDeclaresWhatItWouldDiscard(): void
	{
		foreach ($this->toolsTakingFields() as $sName => $sClass) {
			$aSchema = (new $sClass())->getOutputSchema();

			$this->assertArrayHasKey(
				'overridden',
				$aSchema['properties'],
				"{$sName} does not declare `overridden`, so a caller cannot tell a supplied value that was applied from one the write threw away"
			);
			$this->assertContains(
				'overridden',
				$aSchema['required'],
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
		foreach ($this->toolsTakingFields() as $sName => $sClass) {
			$sBody = $this->methodBody($sClass, 'execute');

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
		foreach ($this->toolsTakingFields() as $sName => $sClass) {
			$sBody = $this->methodBody($sClass, 'execute');

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
