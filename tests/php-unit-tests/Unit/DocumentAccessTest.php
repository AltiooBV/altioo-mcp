<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\DocumentAccess;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Bytes leave by one door, and a read is not that door.
 *
 * The rule this pins is the one the whole design rests on: a file never
 * arrives in a result that fans out. Reading an object reports what the
 * document is and where to ask for it; asking for it by URI is a separate,
 * deliberate call. A change that quietly put content back into a read would
 * pass every other test in this suite.
 */
class DocumentAccessTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	public function testTheUriIsTheOneTheTemplateServes(): void
	{
		$aTemplates = MCPRegistry::GetResourceTemplates();

		$this->assertArrayHasKey(
			'itop://core/document/{class}/{id}/{att_code}',
			$aTemplates,
			'the URI reported by a read has no template to resolve it'
		);

		// The one built for a real object has to match the template it is read
		// through, variable for variable, or every uri in every read is a dead
		// link that looks like a live one.
		$this->assertSame(
			'itop://core/document/Attachment/42/contents',
			DocumentAccess::Uri('Attachment', 42, 'contents')
		);
	}

	/**
	 * A creation that has not happened yet has nothing to address: the dry run
	 * of core_object_create reports the document it would store, and a URI
	 * built on id 0 would resolve to nothing at all.
	 */
	public function testAnObjectWithNoKeyIsDescribedWithoutAUri(): void
	{
		$aWithKey = $this->describe(7);
		$aWithout = $this->describe(0);

		$this->assertArrayHasKey('uri', $aWithKey);
		$this->assertArrayNotHasKey('uri', $aWithout);
	}

	public function testWhatAReadReportsAboutADocumentIsNeverItsContent(): void
	{
		$aDescription = $this->describe(7);

		$this->assertSame(['filename', 'mimetype', 'size', 'uri'], array_keys($aDescription));
		$this->assertSame(11, $aDescription['size'], 'the size is reported, and the size is all');

		// Not "no key called blob": no value anywhere in the structure that
		// could be the file. The bytes here are "hello world", which base64 is
		// aGVsbG8gd29ybGQ=, and neither form may appear.
		$sRendered = json_encode($aDescription);
		$this->assertStringNotContainsString('hello world', $sRendered);
		$this->assertStringNotContainsString(base64_encode('hello world'), $sRendered);
	}

	/**
	 * The reading tools are the ones that fan out - every attribute of an
	 * object by default, fifty objects in a search - and none of them may be
	 * the one that returns a file.
	 */
	public function testNoReadingToolTakesADocumentApartExceptTheOneThatIsFor(): void
	{
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aHints = $oTool->getAnnotations()->jsonSerialize();
			if (empty($aHints['readOnlyHint']) || $sName === 'core_object_get_document') {
				continue;
			}

			$this->assertNotSame(
				'documents',
				$oTool->getToolset(),
				"{$sName} reads and serves documents; bytes belong in core_object_get_document alone"
			);
		}
	}

	/**
	 * The ceiling and the door are one toolset and one setting, so an operator
	 * who does not want files leaving this instance has one thing to turn off.
	 */
	public function testTheDocumentSurfaceIsOneToolsetAnOperatorCanTurnOff(): void
	{
		$aDocumentTools = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			if ($oTool->getToolset() === 'documents') {
				$aDocumentTools[] = $sName;
			}
		}
		sort($aDocumentTools);

		$this->assertSame(['core_object_attach', 'core_object_get_document'], $aDocumentTools);
		$this->assertSame(
			'documents',
			MCPRegistry::GetResourceTemplates()['itop://core/document/{class}/{id}/{att_code}']->getToolset()
		);
	}

	public function testTheCeilingIsDeclaredInBytesAndIsNotUnlimited(): void
	{
		$this->assertGreaterThan(0, DocumentAccess::DEFAULT_MAX_BYTES);
		$this->assertSame('mcp_max_document_bytes', DocumentAccess::MODULE_SETTING_MAX_BYTES);
	}

	/**
	 * ormDocument is iTop's, and the unit suite runs without iTop. The double
	 * carries the three accessors Describe() uses and nothing else, which is
	 * exactly the surface being asserted.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(int $iId): array
	{
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

		$oDocument = new \ormDocument('hello world', 'text/plain', 'notes.txt');

		return DocumentAccess::Describe($oDocument, 'Attachment', $iId, 'contents');
	}
}
