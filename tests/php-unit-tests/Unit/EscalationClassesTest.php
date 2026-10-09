<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Classes an operator adds to the escalation barrier.
 *
 * The built-in barrier names what iTop ships. A class an extension adds that
 * stores work something else carries out later - a scheduled mass update
 * whose rows say "SELECT PersonalToken, set scope" - was an ordinary write
 * here, while the cron that runs it checks nobody's rights.
 * mcp_privilege_escalation_classes is where the operator, who knows what is
 * installed, says so.
 *
 * PHP's own Exception and RuntimeException stand in for a listed class and
 * its subclass: descent is what is being tested, and those exist everywhere.
 */
class EscalationClassesTest extends TestCase
{
	public function testAListedClassIsRefusedWithoutTheEscalationSetting(): void
	{
		$sRefusal = AccessGrants::RefusalGiven(false, 'MassUpdateJob', null, [], false, ['MassUpdateJob']);

		$this->assertSame(sprintf(AccessGrants::OPERATOR_ESCALATION_REFUSAL, 'MassUpdateJob'), $sRefusal);
		$this->assertStringContainsString('mcp_privilege_escalation_classes', (string)$sRefusal, 'the refusal does not say where the class was listed');
		$this->assertStringContainsString('mcp_allow_privilege_escalation', (string)$sRefusal, 'the refusal does not name the setting that lifts it');
	}

	/**
	 * iTop resolves class names case-insensitively in OQL, so the barrier
	 * does too, and an operator's casing is not a way past it.
	 */
	public function testTheNameIsMatchedWhateverItsCase(): void
	{
		$this->assertNotNull(AccessGrants::RefusalGiven(false, 'MASSUPDATEJOB', null, [], false, ['MassUpdateJob']));
		$this->assertTrue(AccessGrants::IsOperatorListed('massupdatejob', ['MassUpdateJob']));
	}

	/**
	 * Listing an extension's base class covers what it adds below it, as the
	 * built-in roots do.
	 */
	public function testASubclassOfAListedClassIsRefused(): void
	{
		$this->assertTrue(AccessGrants::IsOperatorListed('RuntimeException', ['Exception']));
		$this->assertFalse(AccessGrants::IsOperatorListed('Exception', ['RuntimeException']), 'a parent is not covered by listing its child');
	}

	public function testAClassNotListedIsLeftAlone(): void
	{
		$this->assertFalse(AccessGrants::IsOperatorListed('Organization', ['MassUpdateJob']));
		$this->assertFalse(AccessGrants::IsOperatorListed('Organization', []));
		$this->assertNull(AccessGrants::RefusalGiven(false, 'Organization', null, [], false, ['MassUpdateJob']));
	}

	public function testTheEscalationSettingLiftsIt(): void
	{
		$this->assertNull(AccessGrants::RefusalGiven(false, 'MassUpdateJob', null, [], true, ['MassUpdateJob']));
	}

	/**
	 * A malformed setting reads as every class, and the escalation setting is
	 * still the one thing that lifts it.
	 */
	public function testAMalformedListRefusesEveryClass(): void
	{
		$this->assertTrue(AccessGrants::IsOperatorListed('Organization', null));
		$this->assertNotNull(AccessGrants::RefusalGiven(false, 'Organization', null, [], false, null));
		$this->assertNull(AccessGrants::RefusalGiven(false, 'Organization', null, [], true, null));
	}

	/**
	 * Ahead of the built-in families: a synchronisation source is allowed over
	 * a class the caller may write, and an operator who listed it has said
	 * that is not enough.
	 */
	public function testTheListIsAskedBeforeTheBuiltInGrading(): void
	{
		$this->assertSame(
			sprintf(AccessGrants::OPERATOR_ESCALATION_REFUSAL, 'SynchroDataSource'),
			AccessGrants::RefusalGiven(true, 'SynchroDataSource', null, ['scope_class' => 'Server'], false, ['SynchroDataSource'])
		);
	}

	/**
	 * And behind the audit trail, which no setting lifts: listing a recording
	 * class must not turn its refusal into one the escalation setting opens.
	 */
	public function testTheAuditTrailStillComesFirst(): void
	{
		$this->assertSame(
			sprintf(AccessGrants::RECORDING_REFUSAL, 'Event'),
			AccessGrants::RefusalGiven(false, 'Event', null, [], false, ['Event'])
		);
		$this->assertSame(
			sprintf(AccessGrants::RECORDING_REFUSAL, 'Event'),
			AccessGrants::RefusalGiven(false, 'Event', null, [], true, ['Event'])
		);
	}

	/**
	 * @return array<string, array{0: mixed, 1: array<int, string>|null}>
	 */
	public static function settingValues(): array
	{
		return [
			'empty'            => [[], []],
			'names'            => [['MassUpdateJob', 'AcmeScript'], ['MassUpdateJob', 'AcmeScript']],
			'padded'           => [[' MassUpdateJob '], ['MassUpdateJob']],
			'a bare string'    => ['MassUpdateJob', null],
			'a boolean'        => [true, null],
			'null'             => [null, null],
			'a number inside'  => [['MassUpdateJob', 3], null],
			'an empty name'    => [['MassUpdateJob', ''], null],
		];
	}

	/**
	 * The setting only ever adds refusals, so a value that cannot be read as
	 * meant is read as the one that refuses most - every class - and logged.
	 *
	 * @dataProvider settingValues
	 *
	 * @param array<int, string>|null $aExpected
	 */
	public function testTheSettingIsReadSoThatAMistakeRefusesMore(mixed $mValue, ?array $aExpected): void
	{
		$this->assertSame($aExpected, MCPHelper::EscalationClassesFrom($mValue));
	}

	/**
	 * The two places that read the configuration hand it on. Asked of the
	 * source because neither runs without an iTop.
	 */
	public function testTheWritePathAndTheSchemaBothAskTheSetting(): void
	{
		$this->assertStringContainsString('MCPHelper::GetEscalationClasses()', $this->body(AccessGrants::class, 'RefusalFor'));
		$this->assertStringContainsString(
			'AccessGrants::IsOperatorListed($sClass,MCPHelper::GetEscalationClasses())',
			$this->body(DatamodelReader::class, 'narrowedByTheBarrier')
		);
	}

	private function body(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines  = file((string)$oMethod->getFileName());
		$sBody   = implode('', array_slice(
			(array)$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
