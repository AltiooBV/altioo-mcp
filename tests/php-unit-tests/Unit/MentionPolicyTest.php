<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Write\MentionPolicy;
use Altioo\iTop\Extension\MCP\Core\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a case-log entry written through this endpoint may @mention.
 *
 * A red-team pass fired 27 notification mails from one core_object_update by
 * writing the mention markup by hand - any class, any id, as many as fit.
 * These pin the three rules that now hold it to what the console would let
 * the same user write, plus a per-call cap, and pin that every write path
 * asks. The iTop lookups are passed in, so the rules run without an instance.
 */
class MentionPolicyTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	protected function setUp(): void
	{
		RequestScope::Close();
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
	}

	public function testAMentionTheConsoleWouldAllowIsAccepted(): void
	{
		$this->assertNull(self::evaluate(['Person' => ['3']]));
	}

	public function testASubclassOfAnAllowedClassIsAccepted(): void
	{
		$this->assertNull(self::evaluate(['Person' => ['3']], ['Contact']));
	}

	public function testAClassOutsideTheAllowedOnesIsRefused(): void
	{
		$sRefusal = self::evaluate(['UserLocal' => ['1']]);

		$this->assertIsString($sRefusal);
		$this->assertStringContainsString('UserLocal', $sRefusal);
		$this->assertStringContainsString('mentions.allowed_classes', $sRefusal);
	}

	public function testAnObjectTheCallerCannotSeeIsRefused(): void
	{
		$sRefusal = self::evaluate(['Person' => ['3', '99']], ['Person'], 5, ['Person::3']);

		$this->assertIsString($sRefusal);
		$this->assertStringContainsString('Person::99', $sRefusal);
		$this->assertStringContainsString('cannot see', $sRefusal);
	}

	/**
	 * The report's 27 mails, under the default limit.
	 */
	public function testMoreThanTheLimitIsRefused(): void
	{
		$sRefusal = self::evaluate(['Person' => array_map('strval', range(1, 27))]);

		$this->assertIsString($sRefusal);
		$this->assertStringContainsString('27', $sRefusal);
		$this->assertStringContainsString('mcp_max_mentions', $sRefusal);
	}

	/**
	 * Per call, not per value: a bulk update of many tickets would otherwise
	 * multiply the limit by the number of objects.
	 */
	public function testTheLimitCountsAcrossTheWholeCall(): void
	{
		$this->assertNull(self::evaluate(['Person' => ['1', '2', '3']]));
		$this->assertNull(self::evaluate(['Person' => ['4', '5']]));
		$this->assertIsString(self::evaluate(['Person' => ['6']]));
	}

	public function testMentioningTheSameObjectAgainCostsNothing(): void
	{
		$this->assertNull(self::evaluate(['Person' => ['1', '2', '3', '4', '5']]));
		$this->assertNull(self::evaluate(['Person' => ['5']]));
	}

	public function testARefusedValueDoesNotUseUpTheLimit(): void
	{
		$this->assertIsString(self::evaluate(['Person' => array_map('strval', range(1, 6))]));
		$this->assertNull(self::evaluate(['Person' => ['1', '2', '3', '4', '5']]));
	}

	public function testZeroTurnsMentionsOff(): void
	{
		$sRefusal = self::evaluate(['Person' => ['3']], ['Person'], 0);

		$this->assertIsString($sRefusal);
		$this->assertStringContainsString('turned off', $sRefusal);
	}

	/**
	 * Every place that turns a caller's value into an attribute value asks
	 * the policy first. A new write path that forgets to is the gap this
	 * closes, reopened silently.
	 */
	public function testEveryWritePathAsksThePolicy(): void
	{
		$aFound = [];
		$oFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));
		foreach ($oFiles as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			// Comments stripped: a docblock that names MakeValue() is not a
			// write path.
			$oSource = SourceCalls::OfFile($oFile->getPathname());
			foreach ($oSource->MethodsCalling('RestUtils::MakeValue') as $sMethod) {
				$aFound[] = $oFile->getFilename();
				$this->assertTrue(
					$oSource->CallsInOrder([MentionPolicy::class.'::RefusalFor', 'RestUtils::MakeValue'], $sMethod),
					$oFile->getFilename().' turns a caller value into an attribute value without asking MentionPolicy first'
				);
			}
		}

		$this->assertGreaterThanOrEqual(2, count($aFound), 'expected the single-object write paths (WriteValues) and the bulk ones');
	}

	/**
	 * A writer is told how to mention and what this instance allows, before
	 * a write is refused for it - with the instance's own class and limit.
	 */
	public function testAWriterIsToldHowToMentionAndTheLimits(): void
	{
		$sText = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write']), null, null, [], 3, ['Person', 'Team']);

		$this->assertStringContainsString('data-object-class="Person" data-object-key="12"', $sText);
		$this->assertStringContainsString('Person, Team objects you can see', $sText);
		$this->assertStringContainsString('at most 3 distinct objects in one call', $sText);
	}

	/**
	 * The example must be one iTop's parser actually finds. The pattern is
	 * copied from utils::GetMentionedObjectsFromText() (iTop 3.2), which is
	 * not loadable here; it wants the class before the key.
	 */
	public function testTheTaughtMarkupIsWhatItopParses(): void
	{
		$sText = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write']), null, null, [], 5, ['Person']);

		$this->assertSame(1, preg_match('/<a\s*([^>]*)data-object-class="([^"]*)"\s.*data-object-key="([^"]*)"/Ui', html_entity_decode($sText), $aMatch));
		$this->assertSame('Person', $aMatch[2]);
		$this->assertSame('12', $aMatch[3]);
	}

	public function testZeroOrNoClassSaysMentionsAreOff(): void
	{
		foreach ([[0, ['Person']], [5, []]] as [$iMax, $aClasses]) {
			$sText = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write']), null, null, [], $iMax, $aClasses);
			$this->assertStringContainsString('@mentions are turned off here', $sText);
			$this->assertStringNotContainsString('data-object-class', $sText);
		}
	}

	public function testAReaderIsToldNothingAboutMentioning(): void
	{
		$sText = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-read']), null, null, [], 5, ['Person']);

		$this->assertStringNotContainsString('mention', $sText);
	}

	/**
	 * @param array<string, array<int, string>> $aMentioned
	 * @param array<int, string>                $aAllowed
	 * @param array<int, string>|null           $aVisible Class::id the caller can see; null means all.
	 */
	private static function evaluate(array $aMentioned, array $aAllowed = ['Person'], int $iMax = 5, ?array $aVisible = null): ?string
	{
		$aHierarchy = ['Person' => ['Person', 'Contact'], 'Contact' => ['Contact'], 'UserLocal' => ['UserLocal', 'User']];

		return MentionPolicy::Evaluate(
			'public_log',
			$aMentioned,
			$aAllowed,
			$iMax,
			static fn (string $sParent, string $sChild): bool => in_array($sParent, $aHierarchy[$sChild] ?? [], true),
			static fn (string $sClass, string $sId): bool => $aVisible === null || in_array($sClass.'::'.$sId, $aVisible, true)
		);
	}
}
