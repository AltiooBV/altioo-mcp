<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\ChangeSummary;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the audit row says a call changed.
 *
 * The composing half is pure and is checked here; reading the operations back
 * out of `CMDBChangeOp` is iTop's and belongs to the integration suite. That
 * split is the same one {@see ChangeTrackingTest} makes, and for the same
 * reason: the shape of this text is the feature, and it is what survives after
 * a retention purge has taken the change rows it was built from.
 */
class ChangeSummaryTest extends TestCase
{
	/** @return array<int, array{class: string, key: int, verb: string, attcode?: string|null}> */
	private static function update(string $sClass, int $iKey, string ...$aAttributes): array
	{
		$aOperations = [];
		foreach ($aAttributes as $sAttribute) {
			$aOperations[] = [
				'class'   => $sClass,
				'key'     => $iKey,
				'verb'    => ChangeSummary::VERB_UPDATED,
				'attcode' => $sAttribute,
			];
		}

		return $aOperations;
	}

	public function testNothingChangedIsTheEmptyString(): void
	{
		$this->assertSame('', ChangeSummary::Compose([]));
	}

	public function testACreatedObjectIsNamedByClassAndKey(): void
	{
		$this->assertSame(
			'UserRequest::5231 created',
			ChangeSummary::Compose([
				['class' => 'UserRequest', 'key' => 5231, 'verb' => ChangeSummary::VERB_CREATED],
			])
		);
	}

	public function testADeletedObjectSaysSo(): void
	{
		$this->assertSame(
			'Server::45 deleted',
			ChangeSummary::Compose([
				['class' => 'Server', 'key' => 45, 'verb' => ChangeSummary::VERB_DELETED],
			])
		);
	}

	/**
	 * iTop writes one operation row per *attribute*, so the naive summary of a
	 * six-field update is the same object six times - which misreports the
	 * size of the change to the one person who is reading this to find out
	 * how big it was.
	 */
	public function testOneObjectUpdatedManyTimesIsOneLine(): void
	{
		$this->assertSame(
			'Server::45 updated (name, status, org_id)',
			ChangeSummary::Compose(self::update('Server', 45, 'name', 'status', 'org_id'))
		);
	}

	public function testTheSameAttributeTwiceIsListedOnce(): void
	{
		$this->assertSame(
			'Server::45 updated (name)',
			ChangeSummary::Compose(self::update('Server', 45, 'name', 'name'))
		);
	}

	/**
	 * An object created by this request was not also "updated" by the
	 * attributes it was created with. Creation is a statement about the
	 * object; an attribute change is a statement about one of its fields.
	 */
	public function testCreationOutranksTheAttributesThatCameWithIt(): void
	{
		$aOperations = array_merge(
			self::update('UserRequest', 7, 'title', 'description'),
			[['class' => 'UserRequest', 'key' => 7, 'verb' => ChangeSummary::VERB_CREATED]]
		);

		$this->assertSame('UserRequest::7 created', ChangeSummary::Compose($aOperations));
	}

	public function testDeletionOutranksAnUpdateOnTheSameObject(): void
	{
		$aOperations = array_merge(
			self::update('Server', 9, 'status'),
			[['class' => 'Server', 'key' => 9, 'verb' => ChangeSummary::VERB_DELETED]]
		);

		$this->assertSame('Server::9 deleted', ChangeSummary::Compose($aOperations));
	}

	/** Two objects, two lines, in the order the operations happened. */
	public function testObjectsKeepTheOrderTheyWereTouchedIn(): void
	{
		$this->assertSame(
			"Server::2 created\nUserRequest::1 updated (title)",
			ChangeSummary::Compose(array_merge(
				[['class' => 'Server', 'key' => 2, 'verb' => ChangeSummary::VERB_CREATED]],
				self::update('UserRequest', 1, 'title')
			))
		);
	}

	/**
	 * A bulk call can touch thousands. The tail is counted rather than cut, so
	 * that a reader can size what they are not being shown.
	 */
	public function testBeyondTheObjectCapTheRemainderIsCounted(): void
	{
		$aOperations = [];
		for ($i = 1; $i <= ChangeSummary::MAX_OBJECTS + 3; $i++) {
			$aOperations[] = ['class' => 'Server', 'key' => $i, 'verb' => ChangeSummary::VERB_CREATED];
		}

		$aLines = explode("\n", ChangeSummary::Compose($aOperations));

		$this->assertCount(ChangeSummary::MAX_OBJECTS + 1, $aLines);
		$this->assertSame('... and 3 more objects', end($aLines));
	}

	public function testASingleRemainingObjectIsNotPluralised(): void
	{
		$aOperations = [];
		for ($i = 1; $i <= ChangeSummary::MAX_OBJECTS + 1; $i++) {
			$aOperations[] = ['class' => 'Server', 'key' => $i, 'verb' => ChangeSummary::VERB_CREATED];
		}

		$this->assertStringEndsWith('... and 1 more object', ChangeSummary::Compose($aOperations));
	}

	public function testBeyondTheAttributeCapTheRemainderIsCounted(): void
	{
		$aAttributes = [];
		for ($i = 1; $i <= ChangeSummary::MAX_ATTRIBUTES + 2; $i++) {
			$aAttributes[] = 'field'.$i;
		}

		$sLine = ChangeSummary::Compose(self::update('Server', 45, ...$aAttributes));

		$this->assertStringEndsWith('+2 more)', $sLine);
		$this->assertStringContainsString('field'.ChangeSummary::MAX_ATTRIBUTES.',', $sLine);
		$this->assertStringNotContainsString('field'.(ChangeSummary::MAX_ATTRIBUTES + 1).',', $sLine);
	}

	/**
	 * A row that cannot name what it touched contributes nothing a reader can
	 * act on, and a blank line in an audit summary reads like data that was
	 * lost rather than data that was never there.
	 *
	 * @dataProvider unusableOperationProvider
	 *
	 * @param array<string, mixed> $aOperation
	 */
	public function testAnOperationThatNamesNothingIsSkipped(array $aOperation): void
	{
		$this->assertSame('', ChangeSummary::Compose([$aOperation]));
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function unusableOperationProvider(): array
	{
		return [
			'no class'    => [['key' => 1, 'verb' => ChangeSummary::VERB_CREATED]],
			'empty class' => [['class' => '', 'key' => 1, 'verb' => ChangeSummary::VERB_CREATED]],
			'no verb'     => [['class' => 'Server', 'key' => 1]],
			'empty verb'  => [['class' => 'Server', 'key' => 1, 'verb' => '']],
		];
	}

	/**
	 * The unusable row is dropped and the usable one still reported - a
	 * summary that gave up on the whole change because one row was malformed
	 * would lose the part it could have told.
	 */
	public function testOneUnusableRowDoesNotCostTheRest(): void
	{
		$this->assertSame(
			'Server::1 created',
			ChangeSummary::Compose([
				['class' => '', 'key' => 9, 'verb' => ChangeSummary::VERB_CREATED],
				['class' => 'Server', 'key' => 1, 'verb' => ChangeSummary::VERB_CREATED],
			])
		);
	}

	/**
	 * Distinct objects of the same class are distinct lines. Grouping is by
	 * class *and* key, and a bug collapsing it to class alone would report a
	 * fifty-object bulk create as one.
	 */
	public function testTwoObjectsOfOneClassAreTwoLines(): void
	{
		$this->assertSame(
			"Server::1 created\nServer::2 created",
			ChangeSummary::Compose([
				['class' => 'Server', 'key' => 1, 'verb' => ChangeSummary::VERB_CREATED],
				['class' => 'Server', 'key' => 2, 'verb' => ChangeSummary::VERB_CREATED],
			])
		);
	}

	/** A created object carries no attribute list, even when rows supplied one. */
	public function testACreatedObjectListsNoAttributes(): void
	{
		$sLine = ChangeSummary::Compose(array_merge(
			[['class' => 'UserRequest', 'key' => 3, 'verb' => ChangeSummary::VERB_CREATED]],
			self::update('UserRequest', 3, 'title')
		));

		$this->assertStringNotContainsString('(', $sLine);
	}
}
