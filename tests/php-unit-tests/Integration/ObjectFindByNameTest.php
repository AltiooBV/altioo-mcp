<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Exception\ToolCallException;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The global search tool, against a real datamodel.
 *
 * Everything this tool is cannot be tested without one. Its class list comes
 * from MetaModel::GetClasses('searchable'), its matching from
 * AddCondition_FullText() over whatever attributes a class declares searchable,
 * its de-duplication from the leaf rule, and its refusals from UserRights. A
 * unit test can check the input schema and the grading - and does - but it
 * cannot tell whether the tool finds anything.
 *
 * The objects are created here rather than assumed: an instance's sample data
 * is not something a test may rely on, and a search for a string this file
 * invented is the only search whose expected result is knowable.
 */
class ObjectFindByNameTest extends ItopDataTestCaseAlias
{
	/** A needle no datamodel and no sample data can contain by accident. */
	private string $sMarker;

	/** @var array<int, \DBObject> Created here, so torn down here. */
	private array $aCreated = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->sMarker = 'mcpfind'.bin2hex(random_bytes(4));
	}

	protected function tearDown(): void
	{
		foreach (array_reverse($this->aCreated) as $oObject) {
			try {
				$oObject->DBDelete();
			} catch (\Throwable $e) {
				// A test that cannot clean up is not a test that failed; the
				// marker is unique per run, so leftovers cannot affect another.
			}
		}

		$this->aCreated = [];

		parent::tearDown();
	}

	/**
	 * An Organization, which every iTop has: the class is declared by
	 * itop-structure, which this module depends on, so a test written against
	 * it runs on a CMDB-only instance as much as on a ticketing one.
	 */
	private function createOrganization(string $sName): \DBObject
	{
		$oOrg = MetaModel::NewObject('Organization');
		$oOrg->Set('name', $sName);
		$oOrg->DBInsert();

		$this->aCreated[] = $oOrg;

		return $oOrg;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function find(string $sText, string $sClass = '', int $iLimit = 20): array
	{
		return ToolOutput::Decode(ObjectFindByName::execute($sText, $sClass, $iLimit));
	}

	/**
	 * @param array<string, mixed> $aResult
	 *
	 * @return array<int, int>
	 */
	private function idsOf(array $aResult, string $sClass): array
	{
		$aIds = [];

		foreach ($aResult['objects'] as $aObject) {
			if ($aObject['class'] === $sClass) {
				$aIds[] = (int)$aObject['id'];
			}
		}

		return $aIds;
	}

	public function testFindsAnObjectByItsName(): void
	{
		$oOrg = $this->createOrganization('Acme '.$this->sMarker);

		$aResult = $this->find($this->sMarker);

		$this->assertContains(
			(int)$oOrg->GetKey(),
			$this->idsOf($aResult, 'Organization'),
			'A word in the name of an object should find that object.'
		);
	}

	/**
	 * The class is what makes the result usable: every other tool takes it as
	 * an argument, so a result that did not carry it would send the model back
	 * to guessing.
	 */
	public function testEveryResultCarriesItsClass(): void
	{
		$this->createOrganization('Acme '.$this->sMarker);

		$aResult = $this->find($this->sMarker);

		$this->assertNotEmpty($aResult['objects']);
		foreach ($aResult['objects'] as $aObject) {
			$this->assertArrayHasKey('class', $aObject);
			$this->assertTrue(MetaModel::IsValidClass($aObject['class']));
			$this->assertArrayHasKey('id', $aObject);
		}
	}

	/**
	 * The leaf rule. Organization is scanned, and so is every class it derives
	 * from that is itself searchable; without the get_class() test an object
	 * would be reported once per class in its ancestry.
	 */
	public function testAnObjectIsReportedOnce(): void
	{
		$oOrg = $this->createOrganization('Acme '.$this->sMarker);

		$aIds = $this->idsOf($this->find($this->sMarker), 'Organization');

		$this->assertSame(
			[(int)$oOrg->GetKey()],
			array_values(array_unique($aIds)),
			'One object, one row.'
		);
		$this->assertCount(1, $aIds, 'The same object was reported more than once.');
	}

	/** Several words are AND, so a needle pair that no single object carries finds nothing. */
	public function testSeveralWordsAreAnded(): void
	{
		$this->createOrganization('Acme '.$this->sMarker);

		$aResult = $this->find($this->sMarker.' '.$this->sMarker.'zzz');

		$this->assertSame([], $this->idsOf($aResult, 'Organization'));
	}

	public function testRestrictingToAClassSearchesOnlyThatClass(): void
	{
		$oOrg = $this->createOrganization('Acme '.$this->sMarker);

		$aResult = $this->find($this->sMarker, 'Organization');

		$this->assertContains((int)$oOrg->GetKey(), $this->idsOf($aResult, 'Organization'));
		foreach ($aResult['objects'] as $aObject) {
			$this->assertTrue(
				$aObject['class'] === 'Organization' || is_a($aObject['class'], 'Organization', true),
				'Restricting to a class should return that class and its subclasses only.'
			);
		}
	}

	public function testAnUnknownClassIsRefused(): void
	{
		$this->expectException(ToolCallException::class);

		$this->find($this->sMarker, 'NoSuchClassAnywhere');
	}

	/**
	 * iTop drops a needle below full_text_needle_min, and a search left with
	 * none is refused rather than answered with everything.
	 */
	public function testATooShortNeedleIsRefusedRatherThanMatchingEverything(): void
	{
		$iMin = (int)MetaModel::GetConfig()->Get('full_text_needle_min');
		if ($iMin <= 1) {
			$this->markTestSkipped('This instance sets no minimum needle length.');
		}

		$this->expectException(ToolCallException::class);

		$this->find(str_repeat('a', $iMin - 1));
	}

	public function testAnEmptySearchIsRefused(): void
	{
		$this->expectException(ToolCallException::class);

		$this->find('   ');
	}

	/** The limit caps the whole answer, not one class's share of it. */
	public function testTheLimitCapsTheResult(): void
	{
		$this->createOrganization('Acme one '.$this->sMarker);
		$this->createOrganization('Acme two '.$this->sMarker);
		$this->createOrganization('Acme three '.$this->sMarker);

		$aResult = $this->find($this->sMarker, '', 2);

		$this->assertLessThanOrEqual(2, count($aResult['objects']));
		$this->assertSame(count($aResult['objects']), $aResult['total']);
	}

	/**
	 * A caller told neither that it was capped nor that the scan ran out of
	 * time reads a short answer as a complete one.
	 */
	public function testTheAnswerSaysWhetherItIsComplete(): void
	{
		$this->createOrganization('Acme '.$this->sMarker);

		$aResult = $this->find($this->sMarker);

		foreach (['search', 'needles', 'total', 'limit', 'truncated', 'classes_searched', 'classes_total', 'objects'] as $sKey) {
			$this->assertArrayHasKey($sKey, $aResult);
		}

		$this->assertIsBool($aResult['truncated']);
		$this->assertLessThanOrEqual($aResult['classes_total'], $aResult['classes_searched']);
	}

	public function testAMatchOnNothingIsAnEmptyAnswerRatherThanAnError(): void
	{
		$aResult = $this->find('zzz'.$this->sMarker.'zzz');

		$this->assertSame([], $aResult['objects']);
		$this->assertSame(0, $aResult['total']);
	}

	/**
	 * A limit outside the declared range is refused rather than clamped: the
	 * schema publishes the range, and silently answering a different question
	 * from the one asked is worse than saying no.
	 */
	public function testAnOutOfRangeLimitIsRefused(): void
	{
		$this->expectException(ToolCallException::class);

		$this->find($this->sMarker, '', ObjectFindByName::MAX_LIMIT + 1);
	}
}
