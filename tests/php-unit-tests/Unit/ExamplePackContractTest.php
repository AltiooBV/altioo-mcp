<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Rights\WriteCap;
use Altioo\iTop\Extension\MCP\Service\Write\DryRun;
use Altioo\iTop\Extension\MCP\Service\Write\WriteValues;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * The example pack is the model every pack copies, so what it does without is what packs ship without (review 2026-11-18, PA-1, PA-2,
 * PA-4): its write tool committed under an MCP-advisory credential because it never asked DryRun::Simulated(), skipped the mention cap,
 * the lifecycle locks, the formula rule and the change's explanation because it validated by hand, and its read tool answered
 * `total: N, objects: []` for a class the operator excluded because it never asked ExclusionPolicy. The pack's own tests need iTop's
 * classes; this reads the sources, which is what a copy inherits.
 */
class ExamplePackContractTest extends TestCase
{
	private const WRITE = __DIR__.'/../../../doc/example-pack/src/Tools/TicketAddLogEntry.php';
	private const READ = __DIR__.'/../../../doc/example-pack/src/Tools/TicketSearchMine.php';
	private const PRECANNED = __DIR__.'/../../../doc/example-pack/src/Tools/PrecannedReplyRender.php';

	public function testTheWriteToolAsksTheSameThingsACoreWriteToolAsks(): void
	{
		$oSource = SourceCalls::OfFile(self::WRITE);

		foreach ([
			DryRun::class.'::Simulated' => 'the advisory scope is enforced by this call alone (PA-1)',
			WriteValues::class.'::Validated' => 'the one validator: exclusions, the attribute right, the lifecycle locks, mentions and formulas (PA-2)',
			WriteCap::class.'::RefusalFor' => 'the write cap (ADR 0019)',
			ChangeTracking::class.'::Explain' => 'the explanation on the change and the audit row (PA-2)',
			ExclusionPolicy::class.'::Serves' => 'the excluded class (ADR 0012)',
			DryRun::class.'::Check' => 'iTop\'s own checks, so a dry run reports what a real call would refuse',
		] as $sSpec => $sWhy) {
			$this->assertTrue($oSource->HasCall($sSpec, 'execute'), "the example write tool never calls {$sSpec}: {$sWhy}");
		}
	}

	public function testTheWriteToolDecidesWhetherItWritesFromTheAnswerNotTheArgument(): void
	{
		$sCode = SourceCalls::OfFile(self::WRITE)->Code('execute');

		$this->assertStringContainsString('$simulate=DryRun::Simulated($simulate);', $sCode);
		$this->assertLessThan(
			(int)strpos($sCode, 'WriteValues::Validated'),
			(int)strpos($sCode, 'DryRun::Simulated'),
			'Simulated() comes first, before anything reads $simulate'
		);
		$this->assertTrue(
			SourceCalls::OfFile(self::WRITE)->CallsInOrder([WriteCap::class.'::RefusalFor', ChangeTracking::class.'::Explain', '->DBUpdate'], 'execute'),
			'the cap is asked, the change explained, and only then is the object written'
		);
		$this->assertStringNotContainsString('RestUtils::MakeValue', $sCode, 'the pack converts values by hand again: that is a second validator');
		$this->assertStringContainsString('ToolOutput::StructuredWrite(', $sCode, 'a forced rehearsal must say so (forced_by_credential)');
	}

	public function testTheReadToolAsksWhatACoreSearchAsks(): void
	{
		$oSource = SourceCalls::OfFile(self::READ);

		$this->assertTrue($oSource->HasCall(ExclusionPolicy::class.'::Serves', 'execute'), 'an excluded class is answered as one that does not exist (ADR 0012)');
		$this->assertTrue($oSource->HasCall(ExclusionSearches::class.'::Narrowed', 'execute'), 'the rows of an excluded subclass are not in the set, nor in the total');
		$this->assertTrue($oSource->HasCall(ExclusionPolicy::class.'::ExcludesAttribute', 'execute'), 'a filter on an excluded attribute is a read of it');
		$this->assertTrue(
			$oSource->CallsInOrder([ExclusionPolicy::class.'::Serves', 'DBObjectSearch::FromOQL'], 'execute'),
			'the class is asked about before the query is built'
		);
	}

	/**
	 * A predefined reply's placeholders are reads, through as many keys as the reply's author wrote, and iTop's
	 * MetaModel::ApplyParams() follows them asking neither the operator's exclusions nor an attribute right. So the
	 * pack's tool fills them in itself, and every step of a path is asked what a direct read of it is asked (ADR 0012,
	 * 0014, 0023): the class served and readable, the object readable, the attribute not excluded, readable and no
	 * secret. Its behaviour against an excluded attribute is the pack's integration test; this keeps the questions
	 * in its source.
	 */
	public function testThePrecannedReplyToolAsksEveryStepOfAPlaceholderPath(): void
	{
		$oSource = SourceCalls::OfFile(self::PRECANNED);

		foreach ([
			ExclusionPolicy::class.'::ExcludesAttribute' => 'an excluded attribute is not filled in (ADR 0012)',
			ObjectSerializer::class.'::MayReadAttribute' => 'an attribute the account may not read is not filled in',
			ObjectSerializer::class.'::IsSensitive' => 'a secret is not filled in, as core_object_get masks it',
			'UserRights::IsActionAllowed' => 'a link set reaching a class the account may not read is not filled in (ADR 0023)',
		] as $sSpec => $sWhy) {
			$this->assertTrue($oSource->HasCall($sSpec, 'mayRead'), "mayRead() never calls {$sSpec}: {$sWhy}");
		}
		foreach ([
			ExclusionPolicy::class.'::Serves' => 'a key to an excluded class is not followed',
			'UserRights::IsActionAllowed' => 'a key to a class the account may not read is not followed',
			'->Readable' => 'the object a key points at is read only if the account may read it',
		] as $sSpec => $sWhy) {
			$this->assertTrue($oSource->HasCall($sSpec, 'resolve'), "resolve() never calls {$sSpec}: {$sWhy}");
		}
		$this->assertTrue($oSource->HasCall('->Readable', 'applicableReply'), 'a reply FilterApplicableReplies() let through is still asked through Readable()');
		$this->assertTrue($oSource->HasCall(ExclusionPolicy::class.'::Serves', 'execute'), 'the ticket class is asked about');
		$this->assertFalse($oSource->HasCall('MetaModel::ApplyParams'), 'ApplyParams() follows every path unasked; the tool must resolve placeholders itself');
		$this->assertFalse($oSource->HasCall('->DBUpdate'), 'the tool writes nothing: posting the reply is core_object_update (ADR 0006)');
	}

	/**
	 * iTop takes the first node of a datamodel file as the delta root (ModelFactory::LoadModule(),
	 * `$oDocument->childNodes->item(0)`), so a comment above `<itop_design>` is loaded instead of it and the file is
	 * skipped without a word. The example pack's file had one: its toolset scope was never declared on an instance, a
	 * token carrying it was refused at login ("Scope not authorized"), and its titles were never translated.
	 *
	 * @dataProvider datamodelFileProvider
	 */
	public function testADatamodelFileStartsWithItsRootElement(string $sFile): void
	{
		$oDocument = new \DOMDocument();
		$this->assertTrue($oDocument->load($sFile), "{$sFile} is not well-formed XML");
		$oFirst = $oDocument->childNodes->item(0);
		$this->assertInstanceOf(\DOMElement::class, $oFirst, "{$sFile}: something comes before <itop_design>, so iTop skips the whole file; move it inside the root element");
		$this->assertSame('itop_design', $oFirst->nodeName);
	}

	/** @return array<string, array{0: string}> */
	public static function datamodelFileProvider(): array
	{
		$sRoot = dirname(__DIR__, 3);
		$aFiles = array_merge(glob($sRoot.'/datamodel*.xml') ?: [], glob($sRoot.'/doc/example-pack/datamodel*.xml') ?: []);
		$aCases = [];
		foreach ($aFiles as $sFile) {
			$aCases[substr($sFile, strlen($sRoot) + 1)] = [$sFile];
		}

		return $aCases;
	}
}
