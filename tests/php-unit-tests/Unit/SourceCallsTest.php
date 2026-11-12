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

	public function Failing(): void
	{
		try {
			Renamed::Work();
		} catch (\Throwable $e) {
			Renamed::Discard();
			// Renamed::Late()
			return;
		}
		Renamed::After();
	}

	public function Catching(): void
	{
		try {
			Renamed::Work();
		} catch (\LogicException | RuntimeException $e) {
			Renamed::Strings('warning');
		} catch (Exception $e) {
		}
	}

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

	public function testAStringContainingATextIsFoundInCodeAndNotInAComment(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasStringContaining('RefusalFor(', 'Guarded'), 'a literal that holds the text');
		$this->assertTrue($oSource->HasStringContaining('ChangeLogReservation::IsReserved', 'OnlyInAString'), 'one piece of a longer message');
		$this->assertFalse($oSource->HasStringContaining('RefusalFor', 'OnlyInAComment'), 'a comment is not a string');
		$this->assertFalse($oSource->HasStringContaining('no such text', 'Guarded'));
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

	public function testASequenceNeedNotStartAtTheFirstOccurrence(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->CallsInSequence(['Acme\\Rights\\Other::First', 'strlen', '->fifth'], 'Ordered'));
		$this->assertFalse($oSource->CallsInSequence(['->fifth', 'strlen'], 'Ordered'));
		$this->assertFalse($oSource->CallsInSequence(['strlen', 'strlen'], 'Ordered'), 'one call is not two');
		$this->assertFalse($oSource->CallsInSequence(['Acme\\Rights\\Other::First', 'Acme\\Rights\\Other::Absent'], 'Ordered'));
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

	public function testACallAndAConstantAreOrderedTogether(): void
	{
		$oSource = $this->source();

		$this->assertSame(
			['UR_ACTION_READ', 'Acme\Rights\AccessGrants::SELF_REFUSAL', 'Acme\Rights\Other::MODE'],
			$oSource->Uses('Constants')
		);
		$this->assertTrue($oSource->UsesInOrder(['Acme\Rights\ChangeLogReservation::IsReserved', '->Hides'], 'Guarded'));
		$this->assertTrue($oSource->UsesInOrder(['UR_ACTION_READ', 'Acme\Rights\Other::MODE'], 'Constants'));
		$this->assertFalse($oSource->UsesInOrder(['Acme\Rights\Other::MODE', 'UR_ACTION_READ'], 'Constants'));
	}

	public function testTheMethodsThatMakeACallAreListed(): void
	{
		$oSource = $this->source();

		$this->assertSame(['Guarded', 'WithClosure'], $oSource->MethodsCalling('Acme\\Rights\\AccessGrants::RefusalFor'));
		$this->assertSame([], $oSource->MethodsCalling('Acme\\Rights\\Nothing::Absent'));
		$this->assertSame(['Ordered'], $oSource->MethodsCalling('Acme\\Tools\\Writer::Second'));
	}

	public function testAnyCallToANamedMethodIsFoundWhateverItIsCalledOn(): void
	{
		$oSource = $this->source();

		$this->assertTrue($oSource->HasCallTo('Second', 'Ordered'), 'self:: resolves to the class, the name is what is asked');
		$this->assertTrue($oSource->HasCallTo('fifth', 'Ordered'));
		$this->assertFalse($oSource->HasCallTo('Hides', 'OnlyInAComment'));
		$this->assertFalse($oSource->HasCallTo('Hide', 'Guarded'), 'a name is matched whole');
	}

	public function testTheErrorPathIsWhatTheCatchBlocksDo(): void
	{
		$oSource = $this->source();

		$this->assertSame(['Acme\Rights\Other::Discard'], $oSource->UsesInCatch('Failing'), 'the try body, the comment and what follows the block are not the error path');
		$this->assertSame([], $oSource->UsesInCatch('Guarded'));
		$this->assertTrue($oSource->HasCallOn('Acme\Rights\Other', 'Failing'));
		$this->assertFalse($oSource->HasCallOn('Acme\Rights\Another', 'Failing'));
	}

	public function testTheClassesCaughtAreResolvedLikeAnyOther(): void
	{
		$oSource = $this->source();

		$this->assertSame(['Throwable'], $oSource->CatchTypes('Failing'));
		$this->assertSame(
			['LogicException', 'Acme\\Tools\\RuntimeException', 'Acme\\Tools\\Exception'],
			$oSource->CatchTypes('Catching'),
			'a name with no import and no leading backslash is the namespace\'s own, which is not what a catch of the global class means'
		);
		$this->assertSame(['warning'], $oSource->StringsInCatch('Catching'));
		$this->assertStringContainsString('Renamed::Strings(', $oSource->CodeInCatch('Catching'));
		$this->assertStringNotContainsString('Work', $oSource->CodeInCatch('Catching'));
	}

	public function testAClosureInsideAMethodIsPartOfIt(): void
	{
		$this->assertTrue($this->source()->HasCall('Acme\Rights\AccessGrants::RefusalFor', 'WithClosure'));
	}

	public function testSelfIsResolvedWhenTheWholeFileIsAsked(): void
	{
		$this->assertContains('Acme\\Tools\\Writer::Second', $this->source()->Calls());
		$this->assertContains('Acme\\Tools\\Base::Fourth', $this->source()->Calls());
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
