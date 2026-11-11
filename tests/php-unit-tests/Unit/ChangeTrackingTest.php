<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What this endpoint says about a change, and where.
 *
 * The line in CMDBChange.userinfo is iTop's (doc/adr/0009: so that the personal
 * data anonymizer's exact match on the user's name holds), and 2.0 removed the
 * method that used to compose it. What is left is the reason a caller gives and
 * the tool's name, kept for the audit row. The change itself is checked on an
 * instance (ChangeAttributionTest).
 */
class ChangeTrackingTest extends TestCase
{
	/** The width of CMDBChange::userinfo, which is what everything is cut to. */
	private const COLUMN = 255;

	public function testTheCommentPropertyIsOptionalAndBounded(): void
	{
		$aProperty = ChangeTracking::CommentSchemaProperty('the change is being made');

		$this->assertSame('string', $aProperty['type']);
		$this->assertArrayNotHasKey('default', $aProperty, 'a default reason is a reason nobody gave');
		$this->assertSame(self::COLUMN, $aProperty['maxLength']);
		$this->assertStringContainsString('the change is being made', $aProperty['description']);
	}

	public function testNothingWritesTheLine(): void
	{
		$oSource = SourceCalls::OfClass(ChangeTracking::class);

		$this->assertTrue($oSource->HasCall('CMDBObject::SetTrackInfo', 'apply'), 'the line is left to iTop');
		$this->assertStringContainsString('SetTrackInfo(null)', $oSource->Code('apply'), 'the line is left to iTop');
		$this->assertFalse($oSource->HasCallTo('Compose', 'apply'));
		$this->assertTrue($oSource->HasConstant('Combodo\\iTop\\Core\\CMDBChange\\CMDBChangeOrigin::CUSTOM_EXTENSION', 'apply'), 'the origin stays the extension one');
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
