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
 * `simulate` defaults to true and was, for a while, absent from the input
 * schema - which the SDK reads to decide what a client may send. A parameter a
 * client cannot set is a parameter stuck at its default: the tool could not
 * delete anything at all.
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

	public function testTheDescriptionTellsTheModelHowTheTwoCallsWork(): void
	{
		$sDescription = (new ObjectDelete())->getDescription();

		$this->assertStringContainsString('simulate=true', $sDescription);
		$this->assertStringContainsString('simulate=false', $sDescription);
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
