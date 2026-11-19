<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
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
}
