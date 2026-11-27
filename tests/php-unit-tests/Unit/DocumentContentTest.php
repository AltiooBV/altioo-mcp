<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Read\DocumentAccess;
use Altioo\iTop\Extension\MCP\Service\Read\UntrustedMarker;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\Capability\Formatter\ResourceResultFormatter;
use Mcp\Server\Capability\Formatter\ToolResultFormatter;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, retest L5: a file that is not an image came back from core_object_get_document as a text block
 * holding JSON, because resource contents are not a tool result item and the SDK encodes whatever is none. What each
 * container puts on the wire is asked here of the SDK's own formatters, which are what the two handlers go through;
 * DocumentWireTest asks the same of a server on an instance.
 */
class DocumentContentTest extends TestCase
{
	private const URI = 'itop://core/document/Attachment/5/contents';

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists('ormDocument', false)) {
			eval('class ormDocument {
				private $d; private $m; private $f;
				public function __construct($d = "", $m = "", $f = "") { $this->d = $d; $this->m = $m; $this->f = $f; }
				public function GetData() { return $this->d; }
				public function GetMimeType() { return $this->m; }
				public function GetFileName() { return $this->f; }
				public function IsEmpty() { return $this->d === ""; }
			}');
		}
	}

	/** The tool's answer, as ObjectGetDocument builds it, formatted and encoded as the server does. */
	private static function toolWire(string $sData, string $sMimeType): array
	{
		$aContent = (new ToolResultFormatter())->format([
			new TextContent(UntrustedMarker::UNTRUSTED_DOCUMENT_NOTICE),
			DocumentAccess::ToolContentFor(new \ormDocument($sData, $sMimeType, 'f'), self::URI),
		]);

		return json_decode(json_encode($aContent, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}

	/** The resource's answer, likewise. */
	private static function resourceWire(string $sData, string $sMimeType): array
	{
		$aContents = (new ResourceResultFormatter())->format(
			DocumentAccess::ResourceContentsFor(new \ormDocument($sData, $sMimeType, 'f'), self::URI),
			self::URI,
		);

		return json_decode(json_encode($aContents, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
	}

	public function testAnImageIsAnImageFromTheToolAndABlobFromTheResource(): void
	{
		$sPng = "\x89PNG\r\n\x1a\n\x00\x00";

		$this->assertSame(
			['type' => 'image', 'data' => base64_encode($sPng), 'mimeType' => 'image/png'],
			self::toolWire($sPng, 'image/png')[1]
		);
		$this->assertSame(
			[['blob' => base64_encode($sPng), 'uri' => self::URI, 'mimeType' => 'image/png']],
			self::resourceWire($sPng, 'image/png')
		);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function textFiles(): array
	{
		return [
			'plain'                  => ['text/plain', "Line one\r\nLine two\tand é ✓\n"],
			'plain with a charset'   => ['text/plain; charset=utf-8', "a\n"],
			'upper case'             => ['Text/CSV', "a;b\n"],
			'html'                   => ['text/html', "<p>Hi</p>\n"],
			'json'                   => ['application/json', '{"a":1}'],
			'xml'                    => ['application/xml', '<a/>'],
			'a json suffix'          => ['application/problem+json', '{}'],
			'an xml suffix'          => ['application/atom+xml', '<feed/>'],
			'a terminal colour code' => ['text/plain', "\x1b[31mred\x1b[0m\f\n"],
		];
	}

	/** @dataProvider textFiles */
	public function testATextFileIsEmbeddedAsItsText(string $sMimeType, string $sData): void
	{
		$aExpected = ['text' => $sData, 'uri' => self::URI, 'mimeType' => $sMimeType];

		$aTool = self::toolWire($sData, $sMimeType);
		$this->assertSame(['type' => 'text', 'text' => UntrustedMarker::UNTRUSTED_DOCUMENT_NOTICE], $aTool[0], 'the notice comes first');
		$this->assertSame(['type' => 'resource', 'resource' => $aExpected], $aTool[1]);
		$this->assertSame([$aExpected], self::resourceWire($sData, $sMimeType));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function binaryFiles(): array
	{
		return [
			'a pdf'                          => ['application/pdf', "%PDF-1.7\n\x00\xff"],
			'unknown bytes'                  => ['application/octet-stream', "\x00\x01\x02\xfe\xff"],
			'an office file'                 => ['application/vnd.ms-excel', "\xd0\xcf\x11\xe0"],
			'text by name, with a null byte' => ['text/plain', "abc\x00def"],
			'text by name, not UTF-8'        => ['text/plain', "caf\xe9\n"],
			'json by name, control bytes'    => ['application/json', "\x01\x02\x03"],
			'a zip called xml'               => ['application/xml', "PK\x03\x04\x14\x00"],
			'readable, of a binary type'     => ['application/pdf', 'only letters'],
		];
	}

	/** @dataProvider binaryFiles */
	public function testAnyOtherFileIsEmbeddedAsABlob(string $sMimeType, string $sData): void
	{
		$aExpected = ['blob' => base64_encode($sData), 'uri' => self::URI, 'mimeType' => $sMimeType];

		$aTool = self::toolWire($sData, $sMimeType);
		$this->assertSame(['type' => 'text', 'text' => UntrustedMarker::UNTRUSTED_DOCUMENT_NOTICE], $aTool[0]);
		$this->assertSame(['type' => 'resource', 'resource' => $aExpected], $aTool[1]);
		$this->assertSame([$aExpected], self::resourceWire($sData, $sMimeType));
	}

	public function testNoFileIsSentAsATextBlockOfJson(): void
	{
		foreach ([['application/pdf', "\x00"], ['text/plain', 'x'], ['image/png', 'x']] as [$sMimeType, $sData]) {
			$aTool = self::toolWire($sData, $sMimeType);
			$this->assertCount(2, $aTool, 'the notice and the file');
			$this->assertNotSame('text', $aTool[1]['type'], "{$sMimeType} came back as a text block");
		}
	}

	public function testAFileWithNoTypeIsAnOctetStream(): void
	{
		$this->assertSame(DocumentAccess::FALLBACK_MIME_TYPE, self::resourceWire('x', '')[0]['mimeType']);
	}

	/** A control character is written as up to six in JSON, so only tab, line feed, form feed, return and escape make a text. */
	public function testTheOtherControlBytesAreNotText(): void
	{
		for ($i = 0; $i < 32; $i++) {
			$bText = isset(self::resourceWire('a'.chr($i), 'text/plain')[0]['text']);
			$this->assertSame(in_array($i, [9, 10, 12, 13, 27], true), $bText, sprintf('byte 0x%02x', $i));
		}
	}
}
