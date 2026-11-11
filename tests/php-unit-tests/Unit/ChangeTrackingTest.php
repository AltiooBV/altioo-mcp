<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What this endpoint says about a change, and where.
 *
 * Compose() is the line it used to write to CMDBChange.userinfo. Nothing calls
 * it any more (doc/adr/0009: the line is iTop's, so that the personal data
 * anonymizer's exact match on the user's name holds), and it is kept, unchanged
 * and deprecated, for a pack that does. The tests of its shape stay so that
 * keeping it does not mean changing it by accident; the ones after them pin the
 * new arrangement. The change itself is iTop's, and is checked on an instance
 * (ChangeAttributionTest).
 */
class ChangeTrackingTest extends TestCase
{
	/** The width of CMDBChange::userinfo, which is what everything is cut to. */
	private const COLUMN = 255;

	public function testThePortalIsNamedOnTheChange(): void
	{
		$this->assertSame(
			'Jane Doe (MCP: core_object_update, portal itop-portal)',
			ChangeTracking::Compose('Jane Doe', false, 'core_object_update', null, 'itop-portal')
		);
		$this->assertSame('Jane Doe (MCP: core_object_update)', ChangeTracking::Compose('Jane Doe', false, 'core_object_update', null, ''));
	}

	public function testTheUserIsNamedEvenWhenNobodyExplainedAnything(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, null, null);

		// SetTrackInfo() replaces the line iTop would have written, so a
		// composition that forgets the name takes it out of the history -
		// which is the failure this whole class exists to avoid.
		$this->assertStringContainsString('Jane Doe', $sInfo);
		$this->assertStringContainsString('MCP', $sInfo);
	}

	public function testTheToolThatMadeTheChangeIsNamed(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, 'core_object_update', null);

		$this->assertSame('Jane Doe (MCP: core_object_update)', $sInfo);
	}

	public function testTheReasonFollowsTheChannel(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, 'core_object_update', 'Caller confirmed the laptop came back');

		$this->assertSame('Jane Doe (MCP: core_object_update) - Caller confirmed the laptop came back', $sInfo);
	}

	/**
	 * Under impersonation CMDBObject::GetTrackInfo() prepends the user itself,
	 * as "A (on behalf of B)". Prefixing here as well would say it twice.
	 */
	public function testTheUserIsNotNamedTwiceUnderImpersonation(): void
	{
		$sInfo = ChangeTracking::Compose('Admin (on behalf of Jane Doe)', true, 'core_object_update', 'Escalated');

		$this->assertSame('(MCP: core_object_update) - Escalated', $sInfo);
		$this->assertStringNotContainsString('Jane Doe', $sInfo);
	}

	public function testAChangeMadeByAToolThatCannotBeNamedIsStillAttributed(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, null, 'Bulk cleanup');

		$this->assertSame('Jane Doe (MCP) - Bulk cleanup', $sInfo);
	}

	public function testTheLineFitsTheColumn(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, 'core_object_update', str_repeat('a', 4000));

		$this->assertLessThanOrEqual(self::COLUMN, mb_strlen($sInfo));
	}

	/**
	 * The comment is what gets cut, never the attribution: a reason that stops
	 * mid sentence still says more than a row that no longer says who.
	 */
	public function testWhatSurvivesTheCutIsWhoAndThrough(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, 'core_object_update', str_repeat('a', 4000));

		$this->assertStringStartsWith('Jane Doe (MCP: core_object_update) - ', $sInfo);
	}

	/**
	 * A user name long enough to fill the column on its own is pathological,
	 * but a string longer than the column is not stored - it is rejected or
	 * silently cut by MySQL, and the row is then wrong or missing.
	 */
	public function testAnAbsurdUserNameStillProducesAStorableLine(): void
	{
		$sInfo = ChangeTracking::Compose(str_repeat('N', 400), false, 'core_object_update', 'Reason');

		$this->assertLessThanOrEqual(self::COLUMN, mb_strlen($sInfo));
	}

	/**
	 * Counted in characters, because that is how MySQL counts a VARCHAR: a cut
	 * made in bytes would either waste half the column or overflow it,
	 * depending on which side of the arithmetic the accents landed.
	 */
	public function testTheCutCountsCharactersAndNotBytes(): void
	{
		$sInfo = ChangeTracking::Compose('Jane Doe', false, 'core_object_update', str_repeat('é', 400));

		$this->assertLessThanOrEqual(self::COLUMN, mb_strlen($sInfo));
		$this->assertSame(1, preg_match('//u', $sInfo), 'the line was cut through a codepoint');
	}

	public function testTheCommentPropertyIsOptionalAndBounded(): void
	{
		$aProperty = ChangeTracking::CommentSchemaProperty('the change is being made');

		$this->assertSame('string', $aProperty['type']);
		$this->assertArrayNotHasKey('default', $aProperty, 'a default reason is a reason nobody gave');
		$this->assertSame(self::COLUMN, $aProperty['maxLength']);
		$this->assertStringContainsString('the change is being made', $aProperty['description']);
	}

	public function testNothingComposesTheLineAnyMore(): void
	{
		$sSource = (string)file_get_contents((string)(new \ReflectionClass(ChangeTracking::class))->getFileName());
		$iApply = (int)strpos($sSource, 'private static function apply()');
		$sApply = substr($sSource, $iApply, (int)strpos($sSource, 'private static function toolNameOf', $iApply) - $iApply);

		$this->assertStringContainsString('CMDBObject::SetTrackInfo(null)', $sApply, 'the line is left to iTop');
		$this->assertStringNotContainsString('Compose(', $sApply);
		$this->assertStringContainsString('CUSTOM_EXTENSION', $sApply, 'the origin stays the extension one');
	}

	public function testTheReasonIsKeptForTheAuditRowAsOneLine(): void
	{
		ChangeTracking::Forget();
		$this->assertNull(ChangeTracking::Comment());

		$aObserved = [];
		try {
			ChangeTracking::Explain("Caller confirmed\r\nthe laptop came back");
		} catch (\Throwable $e) {
			// apply() needs iTop's CMDBObject; the reason is recorded before it runs.
		}
		$aObserved['comment'] = ChangeTracking::Comment();

		$this->assertSame('Caller confirmed the laptop came back', $aObserved['comment']);
		ChangeTracking::Forget();
	}

	public function testTheReasonIsCutToTheAuditColumn(): void
	{
		ChangeTracking::Forget();
		try {
			ChangeTracking::Explain(str_repeat('é', 400));
		} catch (\Throwable $e) {
		}

		$this->assertSame(self::COLUMN, mb_strlen((string)ChangeTracking::Comment()));
		ChangeTracking::Forget();
	}
}
