<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Controller\CallLog;
use Altioo\iTop\Extension\MCP\Core\ServerInstructions;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Read\CurrentUserReader;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Write\DryRun;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * A write an advisory credential rehearsed says so: in the audit row (what ran, not what was asked),
 * in the answer, in core_current_user and in the instructions.
 *
 * The audit column existed so a review can tell a rehearsal from a write; recording the requested
 * value made a forced rehearsal read as a write that happened.
 */
class AdvisoryRehearsalTest extends TestCase
{
	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	/** What the audit row would carry for a tools/call the tool answered under $oPolicy. */
	private function grade(?AccessPolicy $oPolicy, string $sArguments, bool $bToolAsked): string
	{
		RequestScope::Close();
		if ($oPolicy !== null) {
			AccessPolicy::Remember($oPolicy);
		}
		if ($bToolAsked) {
			DryRun::Simulated(false);
		}
		$sParams = '{"name":"core_object_update","arguments":'.$sArguments.'}';
		$sRequested = (new ReflectionMethod(CallLog::class, 'simulateGrade'))->invoke(null, $sParams);

		return (new ReflectionMethod(CallLog::class, 'effectiveSimulate'))->invoke(null, $sRequested);
	}

	public function testAdvisoryWithSimulateFalseIsLoggedAsARehearsal(): void
	{
		$oAdvisory = AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']);
		$this->assertSame('yes', $this->grade($oAdvisory, '{"simulate":false}', true));
		$this->assertSame('yes', $this->grade($oAdvisory, '{"simulate":"false"}', true), 'the string "false" is still a request to write');
	}

	public function testAnOrdinaryWriterWithSimulateFalseIsLoggedAsAWrite(): void
	{
		$this->assertSame('no', $this->grade(AccessPolicy::FromScopes(['MCP-write']), '{"simulate":false}', true));
		$this->assertSame('yes', $this->grade(AccessPolicy::FromScopes(['MCP-write']), '{"simulate":true}', true));
	}

	public function testAReadStaysNotApplicableEvenUnderAnAdvisoryCredential(): void
	{
		$this->assertSame('n/a', $this->grade(AccessPolicy::FromScopes(['MCP-read', 'MCP-advisory']), '{"class":"Person"}', false));
	}

	public function testACallThatNeverReachedTheRehearsalKeepsWhatItAsked(): void
	{
		// Refused before the write tool decided anything: nothing was forced, so nothing is claimed.
		$this->assertSame('no', $this->grade(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']), '{"simulate":false}', false));
	}

	public function testTheFlagDoesNotOutliveTheRequest(): void
	{
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		DryRun::Simulated(false);
		$this->assertTrue(DryRun::ForcedByCredential());
		RequestScope::Close();
		$this->assertFalse(DryRun::ForcedByCredential());
	}

	public function testAWriteThatRehearsedByChoiceIsNotMarkedForced(): void
	{
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		DryRun::Simulated(true);
		$this->assertFalse(DryRun::ForcedByCredential(), 'a caller that rehearsed anyway was told it was forced');
		$this->assertFalse($this->answer(['simulated' => true])['forced_by_credential']);
	}

	/** @param array<string, mixed> $aData */
	private function answer(array $aData): array
	{
		return ToolOutput::Decode(ToolOutput::StructuredWrite($aData)->structuredContent);
	}

	public function testAForcedAnswerSaysWhyAndChangesNoExistingKey(): void
	{
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		DryRun::Simulated(false);

		$aIn = ['class' => 'UserRequest', 'id' => 3, 'simulated' => true, 'valid' => true];
		$aOut = $this->answer($aIn);
		$this->assertSame($aIn, array_intersect_key($aOut, $aIn));
		$this->assertTrue($aOut['forced_by_credential']);
		$this->assertStringNotContainsStringIgnoringCase('advisory', $this->portalReason(), 'the portal wording names a scope');
		$this->assertSame(['class' => 'x'], $this->answer(['class' => 'x']), 'an answer that is no write\'s was touched');
		$this->assertStringContainsString('Nothing was written', $aOut['forced_reason']);
	}

	private function portalReason(): string
	{
		PortalMode::Remember('brick');
		try {
			return DryRun::ForcedReason();
		} finally {
			PortalMode::Forget();
		}
	}

	public function testARealWriteIsNeverAnnotated(): void
	{
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write']));
		DryRun::Simulated(false);
		$aOut = $this->answer(['simulated' => false]);
		$this->assertFalse($aOut['forced_by_credential']);
		$this->assertSame('', $aOut['forced_reason']);
	}

	public function testTheCurrentUserSaysWhetherWritesAreRehearsals(): void
	{
		$oBlock = new ReflectionMethod(CurrentUserReader::class, 'AdvisoryBlock');

		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write']));
		$this->assertSame(['advisory' => false], $oBlock->invoke(null));

		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		$aAdvisory = $oBlock->invoke(null);
		$this->assertTrue($aAdvisory['advisory']);
		$this->assertStringContainsString('rehearsal', $aAdvisory['advisory_means']);

		PortalMode::Remember('brick');
		$this->assertStringNotContainsStringIgnoringCase('scope', $oBlock->invoke(null)['advisory_means']);
		PortalMode::Forget();
	}

	public function testOnlyAnAdvisoryCredentialIsToldItsWritesRehearse(): void
	{
		$this->assertStringContainsString('forced_by_credential', ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory'])));
		$this->assertStringNotContainsString('forced_by_credential', ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write'])));
		$this->assertStringNotContainsString('advisory', ServerInstructions::PortalText('brick', AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory'])));
		$this->assertStringContainsString('only propose changes', ServerInstructions::PortalText('brick', AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory'])));
	}

	/**
	 * A pack answering through Structured() - with a closed schema and a `simulated` key, under an
	 * advisory credential that forced it - gets exactly what 2.0.0 gave it: it opts in by calling
	 * StructuredWrite() and declaring the keys, never by being upgraded.
	 */
	public function testAPackAnswerThroughStructuredIsByteIdenticalTo200(): void
	{
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		DryRun::Simulated(false);
		$this->assertTrue(DryRun::ForcedByCredential());

		$aPack = ['ticket' => 'R-1', 'simulated' => true];
		$oResult = ToolOutput::Structured($aPack);

		$this->assertSame($aPack, $oResult->structuredContent);
		$this->assertSame('{"ticket":"R-1","simulated":true}', $oResult->content[0]->text);
	}
}
