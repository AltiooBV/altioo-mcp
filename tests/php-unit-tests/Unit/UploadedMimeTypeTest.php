<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\DocumentAccess;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What an uploaded file is stored as.
 *
 * The declared media type is the one part of an upload iTop later acts on:
 * the stored type is what comes back as Content-Type when somebody downloads
 * the attachment from the console. A caller that sends markup and labels it
 * image/png has stored a document a browser renders as markup, under the
 * instance's own origin.
 *
 * Core does send "Content-Security-Policy: sandbox;" on document downloads,
 * which defuses exactly that - and it is a config key an operator can turn
 * off. These hold the property that does not depend on somebody else's file.
 */
class UploadedMimeTypeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		if (!function_exists('finfo_open')) {
			$this->markTestSkipped('ext/fileinfo decides these, and it is not installed here.');
		}
	}

	private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

	/**
	 * The case this exists for: markup wearing an image's label.
	 */
	public function testMarkupDeclaredAsAnImageIsStoredAsMarkup(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType(
			'<html><body><script>alert(1)</script></body></html>',
			'image/png'
		);

		$this->assertNotSame('image/png', $sStored, 'the caller\'s label was taken on trust');
		$this->assertStringContainsString('html', $sStored);
		$this->assertNotNull($sNote, 'the caller is not told the declared type was not used');
		$this->assertStringContainsString('image/png', $sNote);
	}

	/**
	 * SVG is the other executable image format, and the one people forget.
	 */
	public function testSvgDeclaredAsAPngIsStoredAsSvg(): void
	{
		[$sStored] = DocumentAccess::VerifiedMimeType(
			'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
			'image/png'
		);

		$this->assertNotSame('image/png', $sStored);
	}

	public function testAnHonestDeclarationIsKeptAndSaysNothing(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType(self::PNG, 'image/png');

		$this->assertSame('image/png', $sStored);
		$this->assertNull($sNote, 'a response gained a note when nothing was wrong');
	}

	/**
	 * Declaring nothing is not an error, and does not mean octet-stream: the
	 * file says what it is.
	 */
	public function testNoDeclarationReadsTheTypeOffTheFile(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType(self::PNG, null);

		$this->assertSame('image/png', $sStored);
		$this->assertNull($sNote);
	}

	public function testParametersOnTheDeclaredTypeAreIgnored(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType('plain text, nothing more', 'text/plain; charset=utf-8');

		$this->assertSame('text/plain', $sStored);
		$this->assertNull($sNote, 'a charset parameter was read as a disagreement');
	}

	public function testTheComparisonIsCaseInsensitive(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType(self::PNG, 'IMAGE/PNG');

		$this->assertSame('image/png', $sStored);
		$this->assertNull($sNote);
	}

	/**
	 * libmagic has no signature for most inert text formats and answers
	 * text/plain for them. Without the carve-out every such upload would come
	 * back relabelled, which is the annoyance that gets a check like this
	 * turned off.
	 *
	 * The payloads differ per case on purpose: this libmagic *does* know CSV,
	 * so comma-shaped bytes sniff as text/csv and would make every other row
	 * of this provider fail for a reason that has nothing to do with the rule
	 * being tested.
	 *
	 * @dataProvider inertTextProvider
	 */
	public function testAnInertTextRefinementIsKept(string $sDeclared, string $sPayload): void
	{
		$this->assertSame(
			'text/plain',
			$this->sniff($sPayload),
			'this payload no longer sniffs as plain text, so it tests something else'
		);

		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType($sPayload, $sDeclared);

		$this->assertSame($sDeclared, $sStored);
		$this->assertNull($sNote);
	}

	/**
	 * A format libmagic does recognise needs no carve-out: the declaration and
	 * the sniff agree on their own.
	 */
	public function testARecognisedTextFormatAgreesWithoutTheCarveOut(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType("one,two,three\nfour,five,six\n", 'text/csv');

		$this->assertSame('text/csv', $sStored);
		$this->assertNull($sNote);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function inertTextProvider(): array
	{
		return [
			'tab-separated'  => ['text/tab-separated-values', "one\ttwo\nthree\tfour\n"],
			'markdown'       => ['text/markdown', "A heading\n\nAnd a paragraph beneath it.\n"],
			'yaml'           => ['text/yaml', "greeting: hello\nrecipient: world\n"],
			'calendar'       => ['text/calendar', "An appointment, written out in words.\n"],
		];
	}

	/** What libmagic makes of these bytes, read the same way the helper does. */
	private function sniff(string $sData): string
	{
		$oFinfo = finfo_open(FILEINFO_MIME_TYPE);

		return strtolower(trim(explode(';', (string)finfo_buffer($oFinfo, $sData), 2)[0]));
	}

	/**
	 * The carve-out must not become a way in. Everything a browser would
	 * execute has a signature of its own, so it never sniffs as text/plain and
	 * never reaches the allow-list.
	 */
	public function testTheTextCarveOutCannotLaunderMarkup(): void
	{
		[$sStored] = DocumentAccess::VerifiedMimeType(
			'<html><body><script>alert(1)</script></body></html>',
			'text/csv'
		);

		$this->assertNotSame('text/csv', $sStored, 'markup was stored under an inert label');
	}

	/**
	 * The reverse direction is harmless and must still not be honoured: plain
	 * text declared as HTML is stored as plain text.
	 */
	public function testPlainTextDeclaredAsHtmlIsStoredAsPlainText(): void
	{
		[$sStored, $sNote] = DocumentAccess::VerifiedMimeType('just some words', 'text/html');

		$this->assertSame('text/plain', $sStored);
		$this->assertNotNull($sNote);
	}

	/**
	 * A type this module would otherwise have to keep a list of. The rule is
	 * "the bytes decide", not "the bytes decide among types we thought of".
	 */
	public function testAnUnknownDeclaredTypeIsStillCheckedAgainstTheBytes(): void
	{
		[$sStored] = DocumentAccess::VerifiedMimeType(self::PNG, 'application/vnd.acme.invoice+xml');

		$this->assertSame('image/png', $sStored);
	}
}
