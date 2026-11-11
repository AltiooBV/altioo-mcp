<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectGrades;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a read says can be done with the object it just returned.
 *
 * The class-level block says whether a gate opens at all, and answers
 * 'depends' wherever the addon wants to be shown the object first. A read has
 * the object, so it is the one place that question can be settled - and the
 * lifecycle graph has the same shape of gap: core_class_schema reports every
 * transition of every state, and only the object knows which state it is in.
 *
 * Source scans where the answer needs UserRights or MetaModel, which a unit
 * suite that boots no iTop cannot call.
 */
class ObjectActionsContractTest extends TestCase
{
	/**
	 * Only the gates that can differ per object are answered per object.
	 * Reading is settled by the time an object is in a result, and creating is
	 * asked of a class rather than of an object that does not exist yet.
	 */
	public function testTheObjectGatesAreASubsetOfTheClassGates(): void
	{
		$this->assertSame(
			[],
			array_diff(GateGrades::OBJECT_RIGHTS_KEYS, GateGrades::RightsKeys()),
			'an object gate is named that no rights block carries'
		);
		$this->assertNotContains('read', GateGrades::OBJECT_RIGHTS_KEYS);
		$this->assertNotContains('create', GateGrades::OBJECT_RIGHTS_KEYS);
	}

	/**
	 * 'yes' and 'no' were answered without reference to any object, so asking
	 * again per row would be a query bought for nothing. 'depends' is the only
	 * one worth resolving, and resolving it is the point.
	 */
	public function testOnlyADependsCostsAQuery(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGrades::class);

		$this->assertStringContainsString("!=='depends'", $oSource->Code('RightsOn'), 'a settled class grade is asked again per object');
		$this->assertTrue($oSource->HasCallTo('IsActionAllowed', 'RightsOn'));
	}

	/**
	 * core_object_apply_stimulus checks the modify gate and the stimulus gate,
	 * so a transition reported as available has to clear both - otherwise the
	 * read offers a call the write refuses.
	 */
	public function testAStimulusIsOfferedOnlyIfBothGatesAllowIt(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGrades::class);

		$this->assertTrue($oSource->HasCallTo('stricterGrade', 'StimuliOn'), 'the two gates are not combined');
		$this->assertTrue($oSource->HasCallTo('stimulusGrade', 'StimuliOn'));
		$this->assertTrue($oSource->HasString('modify', 'StimuliOn'));
		$this->assertTrue(
			$oSource->HasCallTo('IsStimulusAllowed', 'stimulusGrade'),
			'the stimulus gate itself is never asked'
		);
	}

	/**
	 * A refused transition says which gate refused it.
	 *
	 * Every entry in `available` is a transition the datamodel declares out of
	 * the current state, so 'no' is always a rights answer - but "nobody may
	 * drive this transition by hand" and "this account may not modify this
	 * object" are different facts, and a caller told only 'no' reads the first
	 * as the second and reports the object as stuck. Both grades are already
	 * computed; reporting them costs nothing and neither is a second query.
	 */
	public function testARefusedStimulusSaysWhichGateRefusedIt(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGrades::class);

		$this->assertTrue($oSource->HasString('gates', 'StimuliOn'), 'the entry does not report its gates');
		$this->assertMatchesRegularExpression(
			"/'gates'=>\['modify'=>[^,]+,'stimulus'=>/",
			$oSource->Code('StimuliOn'),
			'both gates are reported, under the names the grades are read from'
		);
	}

	/**
	 * A class with no lifecycle answers null, not an empty list: "no
	 * transitions from here" and "this class has no states" are different
	 * claims, and the second one read as the first says a server is stuck.
	 */
	public function testAClassWithNoLifecycleIsNotReportedAsStuck(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGrades::class);

		$this->assertTrue($oSource->HasCallTo('HasLifecycle', 'StimuliOn'));
		$this->assertStringContainsString('returnnull;', $oSource->Code('StimuliOn'));
	}

	/** One object, so no flag; a page, so a flag and a ceiling. */
	public function testOneObjectIsAnsweredWithoutBeingAsked(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGet::class);

		$this->assertTrue($oSource->HasCallTo('RightsOn', 'execute'));
		$this->assertTrue($oSource->HasCallTo('StimuliOn', 'execute'));
	}

	public function testAPageHasToAskAndIsBounded(): void
	{
		foreach ([ObjectSearchByOQL::class, ObjectSearchByClass::class] as $sTool) {
			$aSchema = (new $sTool())->getInputSchema();

			$this->assertArrayHasKey('actions', $aSchema['properties'], $sTool.' cannot be asked what may be done');
			$this->assertFalse($aSchema['properties']['actions']['default'], $sTool.' answers it for every page by default');
			$this->assertStringContainsString(
				'$actions',
				SourceCalls::OfClass($sTool)->Code('execute'),
				$sTool.' accepts the argument and never passes it on'
			);
		}

		$this->assertStringContainsString(
			'actions',
			SourceCalls::OfClass(\Altioo\iTop\Extension\MCP\Api\AbstractObjectSearch::class)->Code('refuseUnattributablePage'),
			'a page of any size may ask what can be done with every row'
		);
	}

	/**
	 * The body of one method, comments stripped - otherwise a call named only
	 * in a doc comment would satisfy the search.
	 */
}
