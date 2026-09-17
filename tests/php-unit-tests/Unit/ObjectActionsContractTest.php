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
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
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
			array_diff(DatamodelReader::OBJECT_RIGHTS_KEYS, DatamodelReader::RightsKeys()),
			'an object gate is named that no rights block carries'
		);
		$this->assertNotContains('read', DatamodelReader::OBJECT_RIGHTS_KEYS);
		$this->assertNotContains('create', DatamodelReader::OBJECT_RIGHTS_KEYS);
	}

	/**
	 * 'yes' and 'no' were answered without reference to any object, so asking
	 * again per row would be a query bought for nothing. 'depends' is the only
	 * one worth resolving, and resolving it is the point.
	 */
	public function testOnlyADependsCostsAQuery(): void
	{
		$sBody = $this->bodyOf(ObjectSerializer::class, 'RightsOn');

		$this->assertStringContainsString("!== 'depends'", $sBody, 'a settled class grade is asked again per object');
		$this->assertStringContainsString('IsActionAllowed', $sBody);
	}

	/**
	 * core_object_apply_stimulus checks the modify gate and the stimulus gate,
	 * so a transition reported as available has to clear both - otherwise the
	 * read offers a call the write refuses.
	 */
	public function testAStimulusIsOfferedOnlyIfBothGatesAllowIt(): void
	{
		$sBody = $this->bodyOf(ObjectSerializer::class, 'StimuliOn');

		$this->assertStringContainsString('stricterGrade', $sBody, 'the two gates are not combined');
		$this->assertStringContainsString('stimulusGrade', $sBody);
		$this->assertStringContainsString("'modify'", $sBody);
		$this->assertStringContainsString(
			'IsStimulusAllowed',
			$this->bodyOf(ObjectSerializer::class, 'stimulusGrade'),
			'the stimulus gate itself is never asked'
		);
	}

	/**
	 * A class with no lifecycle answers null, not an empty list: "no
	 * transitions from here" and "this class has no states" are different
	 * claims, and the second one read as the first says a server is stuck.
	 */
	public function testAClassWithNoLifecycleIsNotReportedAsStuck(): void
	{
		$sBody = $this->bodyOf(ObjectSerializer::class, 'StimuliOn');

		$this->assertStringContainsString('HasLifecycle', $sBody);
		$this->assertStringContainsString('return null;', $sBody);
	}

	/** One object, so no flag; a page, so a flag and a ceiling. */
	public function testOneObjectIsAnsweredWithoutBeingAsked(): void
	{
		$sBody = $this->bodyOf(ObjectGet::class, 'execute');

		$this->assertStringContainsString('RightsOn', $sBody);
		$this->assertStringContainsString('StimuliOn', $sBody);
	}

	public function testAPageHasToAskAndIsBounded(): void
	{
		foreach ([ObjectSearchByOQL::class, ObjectSearchByClass::class] as $sTool) {
			$aSchema = (new $sTool())->getInputSchema();

			$this->assertArrayHasKey('actions', $aSchema['properties'], $sTool.' cannot be asked what may be done');
			$this->assertFalse($aSchema['properties']['actions']['default'], $sTool.' answers it for every page by default');
			$this->assertStringContainsString(
				'$actions',
				$this->bodyOf($sTool, 'execute'),
				$sTool.' accepts the argument and never passes it on'
			);
		}

		$this->assertStringContainsString(
			'actions',
			$this->bodyOf(\Altioo\iTop\Extension\MCP\Abstract\AbstractObjectSearch::class, 'refuseUnattributablePage'),
			'a page of any size may ask what can be done with every row'
		);
	}

	/**
	 * The body of one method, comments stripped - otherwise a call named only
	 * in a doc comment would satisfy the search.
	 */
	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
