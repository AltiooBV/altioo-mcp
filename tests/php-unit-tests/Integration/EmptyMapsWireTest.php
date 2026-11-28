<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Review of 2026-11-23, S6, and its retest of 2026-11-28: a map with nothing in it left as `[]`, which a client reads
 * as another type than the object the same key holds the rest of the time. Read off the wire, decoded so that `{}` and
 * `[]` stay apart.
 *
 * @group integration
 */
class EmptyMapsWireTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}
	}

	public function testASearchWithNoFilterEchoesAnEmptyObject(): void
	{
		foreach ([['class' => 'Organization'], ['class' => 'Organization', 'filters' => new \stdClass()], ['class' => 'Organization', 'filters' => []]] as $aArguments) {
			$this->assertEquals(new \stdClass(), $this->payload('core_object_search_by_class', $aArguments)->filters);
		}
		$this->assertEquals((object)['name' => 'nobody'], $this->payload('core_object_search_by_class', ['class' => 'Organization', 'filters' => ['name' => 'nobody']])->filters);
	}

	public function testASchemaNarrowedToNothingInABlockSaysSoWithAnObject(): void
	{
		// A derived attribute alone leaves nothing to write, and a writable one nothing derived.
		$oSchema = $this->payload('core_class_schema', ['class' => 'UserRequest', 'attributes' => 'friendlyname']);
		$this->assertEquals(new \stdClass(), $oSchema->attributes);
		$this->assertObjectHasProperty('friendlyname', $oSchema->derived);

		$oSchema = $this->payload('core_class_schema', ['class' => 'UserRequest', 'attributes' => 'title']);
		$this->assertEquals(new \stdClass(), $oSchema->derived);
		$this->assertObjectHasProperty('title', $oSchema->attributes);
	}

	public function testAClosedStateHasAnEmptyObjectOfTransitionsAndAClassWithoutALifecycleAnEmptyLifecycle(): void
	{
		$oStates = $this->payload('core_class_schema', ['class' => 'UserRequest', 'include' => 'lifecycle'])->lifecycle->states;
		$this->assertEquals(new \stdClass(), $oStates->closed->transitions);
		$this->assertInstanceOf(\stdClass::class, $oStates->new->transitions);

		$this->assertEquals(new \stdClass(), $this->payload('core_class_schema', ['class' => 'Organization', 'include' => 'lifecycle'])->lifecycle);
	}

	/** Retest of 2026-11-28: `attributes: "tto_deadline"` answered an empty block, with no word of the attribute meant. */
	public function testAnUnknownAttributeCodeOfTheSchemaIsRefusedWithTheOneMeant(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'tto_escalation_deadline')) {
			$this->markTestSkipped('Needs UserRequest.tto_escalation_deadline.');
		}
		$oResult = $this->call('core_class_schema', ['class' => 'UserRequest', 'attributes' => 'title, tto_deadline']);

		$this->assertTrue($oResult->isError ?? false);
		$this->assertSame(
			"Unknown attribute 'tto_deadline' on class 'UserRequest'. Did you mean 'tto_escalation_deadline'? Call core_class_schema for the attribute codes of the class.",
			$oResult->content[0]->text
		);
		// The id and the class are no attributes, and were never a mistake to name.
		$this->assertObjectHasProperty('title', $this->payload('core_class_schema', ['class' => 'UserRequest', 'attributes' => 'id,class,title'])->attributes);
	}

	/**
	 * @param array<string, mixed> $aArguments
	 */
	private function payload(string $sTool, array $aArguments): \stdClass
	{
		$oResult = $this->call($sTool, $aArguments);
		$sText = (string)($oResult->content[0]->text ?? '');
		$this->assertFalse($oResult->isError ?? false, "{$sTool} answered an error: {$sText}");
		// The payload is the text, after the untrusted notice where there is one.
		$oPayload = json_decode((string)($oResult->content[count($oResult->content) - 1]->text ?? ''));
		$this->assertInstanceOf(\stdClass::class, $oPayload, $sText);

		return $oPayload;
	}
}
