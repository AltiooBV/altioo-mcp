<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The dry-run semantics of the one destructive core tool.
 *
 * `simulate` defaults to true, and it is declared in the input schema - which
 * the SDK reads to decide what a client may send. A parameter missing from
 * that schema is a parameter stuck at its default: were `simulate` left out,
 * the tool could not delete anything at all.
 *
 * Reading the schema and the signature needs no iTop, which is what makes this
 * a unit test rather than an integration one.
 */
class ObjectDeleteContractTest extends TestCase
{
	public function testSimulateIsExposedToTheClient(): void
	{
		$aSchema = (new ObjectDelete())->getInputSchema();

		$this->assertArrayHasKey('simulate', $aSchema['properties']);
		$this->assertSame('boolean', $aSchema['properties']['simulate']['type']);
	}

	/**
	 * A refused deletion names the way that is open.
	 *
	 * A profile that may not delete is the normal case, not the exception:
	 * service desks retire tickets and decommission CIs through the lifecycle,
	 * and deletion belongs to administrators. The refusal said only that the
	 * door was shut, so a caller had to already know the datamodel to find the
	 * one that is open - and an agent asked to clean something up stopped
	 * there.
	 *
	 * Only transitions this caller may actually apply: offering one that would
	 * itself be refused replaces a dead end with another, and StimuliOn()
	 * already grades each by the modify right on the object and by
	 * IsStimulusAllowed().
	 *
	 * Silent when there is nothing to say, and it cannot raise: a refusal must
	 * not fail while explaining itself.
	 */
	public function testARefusedDeletionPointsAtTheLifecycle(): void
	{
		$sBody = (string) file_get_contents(
			(new \ReflectionClass(\Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete::class))->getFileName()
		);

		$this->assertStringContainsString('retirementHint', $sBody, 'a refused deletion offers nothing else');
		$this->assertStringContainsString('StimuliOn', $sBody, 'the transitions are not read from the object');
		$this->assertStringContainsString("!== 'no'", $sBody, 'a transition this caller may not apply would be offered');
		$this->assertStringContainsString('core_object_apply_stimulus', $sBody, 'the hint names no tool to use');
		$this->assertMatchesRegularExpression(
			'/catch \\(\\\\Throwable[^}]*return \x27\x27;/s',
			$sBody,
			'explaining a refusal can raise over the refusal itself'
		);
	}

	/**
	 * An object the account cannot see is refused, with both ways out named.
	 *
	 * The case is a stale id: an account that hides obsolete objects - the
	 * default - acting on an id from before the object became obsolete. It
	 * cannot find the object, cannot check what it now is, and the write lands
	 * on something its own view says is gone.
	 *
	 * Refused and not forbidden, which is the whole design. Obsolescence is a
	 * display filter in iTop and not a right: the console opens an obsolete
	 * object by URL and edits it, and it has to, because modifying the object
	 * is the only way to stop it being obsolete. A guard with no way past it
	 * would make un-obsoleting impossible for every account nobody has
	 * configured, so the refusal names obsolete_ok and core_set_obsolete_data
	 * rather than ending the conversation.
	 *
	 * Single-object only. A bulk call is handed its ids explicitly and reports
	 * per row, so a hidden-obsolete row there belongs in that row's entry
	 * rather than in a refusal that stops the other ninety-nine.
	 */
	public function testAnObsoleteObjectThisAccountHidesIsRefusedWithAWayOut(): void
	{
		$sGuard = (string) file_get_contents(
			(new \ReflectionClass(\Altioo\iTop\Extension\MCP\Helper\WritePlan::class))->getFileName()
		);

		$this->assertStringContainsString('function RefuseHiddenObsolete', $sGuard);
		$this->assertStringContainsString('obsolete_ok=true', $sGuard, 'the refusal does not say how to proceed');
		$this->assertStringContainsString('core_set_obsolete_data', $sGuard, 'the other way out is not named');
		$this->assertStringContainsString('ShowObsoleteData()', $sGuard, 'the guard fires on accounts that can see them too');
		$this->assertStringContainsString('return;', $sGuard, 'a guard that cannot answer must fail open, not refuse');

		foreach ([\Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete::class, \Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate::class] as $sTool) {
			$oTool = new $sTool();
			$this->assertArrayHasKey('obsolete_ok', $oTool->getInputSchema()['properties'], "{$sTool} offers no way past the guard");
			$this->assertFalse($oTool->getInputSchema()['properties']['obsolete_ok']['default'], "{$sTool} lets it through by default");
		}

		foreach ([\Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete::class, \Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate::class] as $sBulk) {
			$this->assertArrayNotHasKey(
				'obsolete_ok',
				(new $sBulk())->getInputSchema()['properties'],
				"{$sBulk} carries a single-object guard"
			);
		}
	}

	/**
	 * Deleting must be the deliberate second call, never what happens when the
	 * model omits an argument.
	 */
	public function testTheDefaultIsADryRunInBothTheSchemaAndTheSignature(): void
	{
		$aSchema = (new ObjectDelete())->getInputSchema();
		$this->assertTrue($aSchema['properties']['simulate']['default']);
		$this->assertNotContains('simulate', $aSchema['required']);

		$aParameters = (new ReflectionMethod(ObjectDelete::class, 'execute'))->getParameters();
		$aSimulate = array_values(array_filter(
			$aParameters,
			static fn (\ReflectionParameter $oParameter): bool => $oParameter->getName() === 'simulate'
		));

		$this->assertCount(1, $aSimulate);
		$this->assertTrue($aSimulate[0]->getDefaultValue());
	}

	/**
	 * The deletion plan is this tool's own half of the protocol - the shared
	 * two-step lives in the simulate property and in the instructions now, but
	 * "the dry run tells you what else goes with it" is true of no other tool
	 * and has to be readable here.
	 */
	public function testTheDescriptionSaysWhatTheDryRunIsWorthHere(): void
	{
		$oTool = new ObjectDelete();
		$sDescription = (string)$oTool->getDescription();

		$this->assertStringContainsString('deletion plan', $sDescription);
		$this->assertStringContainsString(
			'simulate=false',
			$sDescription.json_encode($oTool->getInputSchema()['properties']['simulate']),
			'nothing at call time says how to go through with it'
		);
	}

	/**
	 * The tool is annotated destructive; a client that hides destructive tools
	 * behind a confirmation relies on that flag.
	 */
	public function testTheToolIsAnnotatedDestructive(): void
	{
		$aAnnotations = (new ObjectDelete())->getAnnotations()->jsonSerialize();

		$this->assertTrue($aAnnotations['destructiveHint']);
		$this->assertFalse($aAnnotations['readOnlyHint']);
	}
}
