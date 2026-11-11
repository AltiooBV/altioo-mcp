<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the audit row says a read returned: class and id, in a bounded line.
 *
 * The column answers "was this person's ticket sent to a model?" and must
 * never become a second copy of what the ticket says. The format and the caps
 * are asserted here without iTop; the integration suite runs each read tool
 * and asserts its objects arrive (ReadObjectsAuditTest).
 */
class ReadSummaryTest extends TestCase
{
	protected function setUp(): void
	{
		ReadSummary::Forget();
	}

	protected function tearDown(): void
	{
		ReadSummary::Forget();
	}

	public function testNothingReadIsAnEmptyLine(): void
	{
		$this->assertSame('', ReadSummary::Compose());
	}

	public function testClassesAreGroupedAndIdsKeepTheOrderTheyWereRead(): void
	{
		ReadSummary::Note('UserRequest', 15);
		ReadSummary::Note('Person', 7);
		ReadSummary::Note('UserRequest', 12);

		$this->assertSame('UserRequest: 15, 12; Person: 7', ReadSummary::Compose());
	}

	public function testAnObjectReadTwiceIsListedOnce(): void
	{
		ReadSummary::Note('Person', 7);
		ReadSummary::Note('Person', 7);

		$this->assertSame('Person: 7', ReadSummary::Compose());
	}

	/**
	 * A dry run's new object has no key yet, and nobody read it.
	 */
	public function testAKeyBelowOneIsIgnored(): void
	{
		ReadSummary::Note('Server', 0);
		ReadSummary::Note('Server', -1);
		ReadSummary::Note('', 5);

		$this->assertSame('', ReadSummary::Compose());
	}

	public function testALongListIsCutAndTheRemainderCounted(): void
	{
		for ($i = 1; $i <= ReadSummary::MAX_PER_CLASS + 41; $i++) {
			ReadSummary::Note('UserRequest', $i);
		}
		ReadSummary::Note('Person', 7);

		$sLine = ReadSummary::Compose();
		$this->assertStringStartsWith('UserRequest: 1, 2, 3, ', $sLine);
		$this->assertStringContainsString(', 50 (+41 more); Person: 7', $sLine);
		$this->assertStringNotContainsString('51,', $sLine);
	}

	public function testManyClassesAreCutAndTheRemainderCounted(): void
	{
		for ($i = 1; $i <= ReadSummary::MAX_CLASSES + 2; $i++) {
			ReadSummary::Note('Class'.$i, $i);
		}
		ReadSummary::Note('Class'.(ReadSummary::MAX_CLASSES + 1), 99);

		$sLine = ReadSummary::Compose();
		$this->assertStringNotContainsString('Class'.(ReadSummary::MAX_CLASSES + 1).':', $sLine);
		$this->assertStringEndsWith('; +3 more in other classes', $sLine);
	}

	public function testForgetEmptiesIt(): void
	{
		ReadSummary::Note('Person', 7);
		ReadSummary::Forget();

		$this->assertSame('', ReadSummary::Compose());
	}

	/**
	 * Nothing but an identity can reach the column: the only way in takes a
	 * class name and an integer, so there is no parameter a value, a friendly
	 * name or a field could travel through.
	 */
	public function testTheOnlyWayInTakesAClassAndAnId(): void
	{
		$aParams = (new ReflectionMethod(ReadSummary::class, 'Note'))->getParameters();

		$this->assertCount(2, $aParams);
		$this->assertSame('string', (string)$aParams[0]->getType());
		$this->assertSame('int', (string)$aParams[1]->getType());
	}

	/**
	 * Each place that turns an object into an answer says so to the audit row.
	 * Adding a read path without it is how the column would go quietly short,
	 * so the list is pinned here; the integration suite proves each one fires.
	 */
	public function testEveryReadPathNotesWhatItReturns(): void
	{
		$sRoot = dirname(__DIR__, 3).'/src/';
		foreach ([
			'Helper/ObjectSerializer.php',
			'Core/Tools/ObjectGetRelated.php',
			'Helper/DocumentAccess.php',
			'Helper/ObjectHistory.php',
			'Service/PortalAccess.php',
		] as $sFile) {
			$this->assertStringContainsString('ReadSummary::Note(', (string)file_get_contents($sRoot.$sFile), $sFile.' returns objects and does not tell the audit row');
		}
	}
}
