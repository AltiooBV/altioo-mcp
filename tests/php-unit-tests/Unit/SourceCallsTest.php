<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The reader the contract tests stand on, held to the rule it exists for: a
 * call counts only when it is code, and only when it is on the class asked for.
 */
class SourceCallsTest extends TestCase
{
	private const FIXTURE = <<<'PHP'
<?php
namespace Acme\Tools;

use Acme\Rights\AccessGrants;
use Acme\Rights\{ChangeLogReservation, Other as Renamed};
use function Acme\Rights\helper;

/** AccessGrants::RefusalFor( is the whole point of this class. */
abstract class Writer extends Base
{
	public function Guarded(string $sClass): mixed
	{
		// AccessGrants::RefusalFor($sClass) used to be here
		$sText = 'AccessGrants::RefusalFor(';
		$sAgain = "->Hides(";
		if (ChangeLogReservation::IsReserved($sClass)) {
			return null;
		}
		$oAccess->Hides($sClass);
		return AccessGrants::RefusalFor($sClass);
	}

	public function OnlyInAComment(): void
	{
		/* AccessGrants::RefusalFor($x); $o->Hides($x); */
		# ChangeLogReservation::IsReserved($x)
		// self::Guarded()
	}

	public function OnlyInAString(): string
	{
		return "AccessGrants::RefusalFor(\$x) and {$sY}" . 'ChangeLogReservation::IsReserved(';
	}

	public function SameNameOtherClass(): mixed
	{
		return \Elsewhere\AccessGrants::RefusalFor('x') ?? Foreign\AccessGrants::RefusalFor('y');
	}

	public function SameNameInThisNamespace(): mixed
	{
		return Renamed::RefusalFor('x');
	}

	public function Ordered(): void
	{
		Renamed::First();
		\strlen('x');
		self::Second();
		static::Third();
		parent::Fourth();
		$this->fifth();
		$oThing?->sixth();
	}

	public function Constants(): void
	{
		$a = [UR_ACTION_READ, AccessGrants::SELF_REFUSAL, AccessGrants::class, Renamed::MODE];
		$b = 'UR_ACTION_MODIFY';
	}

	abstract protected function Declared(): void;

	public function WithClosure(): callable
	{
		return function () {
			return AccessGrants::RefusalFor('x');
		};
	}
}
PHP;

	private function source(): SourceCalls
	{
		return SourceCalls::OfCode(self::FIXTURE);
	}

	public function testItReadsTheImportsAndTheNamespace(): void
	{
		$oSource = $this->source();

		$this->assertSame('Acme\Tools', $oSource->NamespaceName());
		$this->assertSame([
			'AccessGrants' => 'Acme\Rights\AccessGrants',
			'ChangeLogReservation' => 'Acme\Rights\ChangeLogReservation',
			'Renamed' => 'Acme\Rights\Other',
		], $oSource->Imports(), 'a function import is not a class import, and a group and an alias are resolved');
	}

	public function testItReadsTheImportsOfARealFile(): void
	{
		$oSource = SourceCalls::OfClass(SourceCalls::class);

		$this->assertSame('PhpToken', $oSource->Imports()['PhpToken']);
		$this->assertSame('Altioo\iTop\Extension\MCP\Test\Support', $oSource->NamespaceName());
	}

	public function testACallInCodeCounts(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'Guarded'));
		$this->assertTrue($oSource->HasCall('Acme\Rights\ChangeLogReservation::IsReserved', 'Guarded'));
		$this->assertTrue($oSource->HasCall('->Hides', 'Guarded'));
		$this->assertTrue($oSource->HasCall('acme\rights\accessgrants::refusalfor', 'Guarded'), 'names are compared without case');
		$this->assertTrue($oSource->HasCall('\Acme\Rights\AccessGrants::RefusalFor', 'Guarded'), 'a leading backslash in the spec changes nothing');
	}

	public function testACallInACommentDoesNotCount(): void
	{
		$oSource = $this->source();

		$this->assertSame([], $oSource->Calls('OnlyInAComment'), 'a block comment, a // and a # comment, and the docblock above the class');
		$this->assertFalse($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'OnlyInAComment'));
		$this->assertFalse($oSource->HasCall('->Hides', 'OnlyInAComment'));
		$this->assertFalse($oSource->HasCall('Acme\Tools\Writer::Guarded', 'OnlyInAComment'));
	}

	public function testACallInAStringDoesNotCount(): void
	{
		$oSource = $this->source();

		$this->assertSame([], $oSource->Calls('OnlyInAString'));
		$this->assertFalse($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'OnlyInAString'));
		$this->assertFalse($oSource->HasCall('Acme\Rights\ChangeLogReservation::IsReserved', 'OnlyInAString'));
		$this->assertTrue($oSource->HasString('AccessGrants::RefusalFor(', 'Guarded'), 'the string itself is still there to be asked about');
	}

	public function testACommentOrAStringInsideAMethodThatAlsoCallsDoesNotAddACall(): void
	{
		$aCalls = $this->source()->Calls('Guarded');

		$this->assertSame(
			['Acme\Rights\ChangeLogReservation::IsReserved', '->Hides', 'Acme\Rights\AccessGrants::RefusalFor'],
			$aCalls
		);
	}

	public function testASameNamedClassFromAnotherNamespaceDoesNotCount(): void
	{
		$oSource = $this->source();

		$this->assertSame(
			['Elsewhere\AccessGrants::RefusalFor', 'Acme\Tools\Foreign\AccessGrants::RefusalFor'],
			$oSource->Calls('SameNameOtherClass')
		);
		$this->assertFalse($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'SameNameOtherClass'));

		$this->assertSame(['Acme\Rights\Other::RefusalFor'], $oSource->Calls('SameNameInThisNamespace'), 'an alias is resolved, so Renamed is not AccessGrants');
		$this->assertFalse($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'SameNameInThisNamespace'));
	}

	public function testSelfStaticAndParentAreTheClassTheyStandFor(): void
	{
		$this->assertSame(
			[
				'Acme\Rights\Other::First',
				'strlen',
				'Acme\Tools\Writer::Second',
				'Acme\Tools\Writer::Third',
				'Acme\Tools\Base::Fourth',
				'->fifth',
				'->sixth',
			],
			$this->source()->Calls('Ordered')
		);
	}

	public function testTheOrderOfCallsIsAsked(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->CallsInOrder(['Acme\Rights\Other::First', 'strlen', '->fifth'], 'Ordered'));
		$this->assertFalse($oSource->CallsInOrder(['->fifth', 'Acme\Rights\Other::First'], 'Ordered'), 'the other way round is not the order');
		$this->assertFalse($oSource->CallsInOrder(['Acme\Rights\Other::First', 'Acme\Rights\Other::Absent'], 'Ordered'), 'a call that is not there fails');
		$this->assertFalse($oSource->CallsInOrder(['Acme\Rights\AccessGrants::RefusalFor', '->Hides'], 'Guarded'), 'the comment above the call is not an earlier one');
		$this->assertTrue($oSource->CallsInOrder(['Acme\Rights\ChangeLogReservation::IsReserved', '->Hides', 'Acme\Rights\AccessGrants::RefusalFor'], 'Guarded'));
	}

	public function testOrderIsAskedOfCallsGatheredFromSeveralPlaces(): void
	{
		$this->assertTrue(SourceCalls::InOrder(['a', 'B::c', 'd'], ['A', 'b::C']));
		$this->assertFalse(SourceCalls::InOrder(['a', 'a'], ['a', 'a']), 'one call cannot be both of two');
	}

	public function testConstantsAreReadFromCodeOnly(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasConstant('UR_ACTION_READ', 'Constants'));
		$this->assertTrue($oSource->HasConstant('Acme\Rights\AccessGrants::SELF_REFUSAL', 'Constants'));
		$this->assertTrue($oSource->HasConstant('Acme\Rights\Other::MODE', 'Constants'), 'an alias is resolved');
		$this->assertFalse($oSource->HasConstant('UR_ACTION_MODIFY', 'Constants'), 'a string that spells a constant is not one');
		$this->assertFalse($oSource->HasConstant('Acme\Rights\AccessGrants::class', 'Constants'), '::class is not a constant');
		$this->assertTrue($oSource->HasString('UR_ACTION_MODIFY', 'Constants'));
	}

	public function testAClosureInsideAMethodIsPartOfIt(): void
	{
		$this->assertTrue($this->source()->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'WithClosure'));
	}

	public function testTheWholeFileCanBeAsked(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasCall('Acme\Rights\AccessGrants::RefusalFor'));
		$this->assertFalse($oSource->HasCall('Acme\Rights\Nothing::Absent'));
	}

	public function testMethodsAreFoundByName(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasMethod('guarded'));
		$this->assertFalse($oSource->HasMethod('Declared'), 'an abstract method has no body to ask');
		$this->assertContains('Guarded', $oSource->MethodNames());
	}

	public function testAMethodThatIsNotThereIsAnErrorRatherThanAnAbsentCall(): void
	{
		$this->expectException(\LogicException::class);

		$this->source()->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'Renamed');
	}

	public function testCodeKeepsStringsAndDropsComments(): void
	{
		$sCode = $this->source()->Code('OnlyInAComment');

		$this->assertSame('', $sCode);
		$this->assertStringContainsString('->Hides($sClass);', $this->source()->Code('Guarded'));
	}

	public function testItReadsARealToolTheSameWay(): void
	{
		$oSource = SourceCalls::OfFile(dirname(__DIR__, 3).'/src/Core/Tools/ObjectUpdate.php');

		$this->assertTrue($oSource->HasCall('Altioo\iTop\Extension\MCP\Service\Rights\AccessGrants::RefusalFor', 'execute'));
	}
}
