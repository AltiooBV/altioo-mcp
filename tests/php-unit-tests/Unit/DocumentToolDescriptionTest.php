<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C18: core_object_get_document said "this is how that uri is read" and took no uri. It takes one now
 * (batch 3), held to the one shape this server reads and to the same checks as the three arguments.
 */
class DocumentToolDescriptionTest extends TestCase
{
	protected function tearDown(): void
	{
		PortalMode::Forget();
	}

	/** @return array{0: string, 1: int, 2: string} */
	private static function named(string $class = '', int $id = 0, string $att = '', string $uri = ''): array
	{
		$oMethod = new \ReflectionMethod(ObjectGetDocument::class, 'named');
		$oMethod->setAccessible(true);

		return $oMethod->invoke(null, $class, $id, $att, $uri);
	}

	public function testTheConsoleToolTakesAUriAndSaysSo(): void
	{
		$oTool = new ObjectGetDocument();
		$sDescription = (string)$oTool->getDescription();
		$aSchema = $oTool->getInputSchema();

		$this->assertArrayHasKey('uri', $aSchema['properties']);
		$this->assertStringNotContainsString('this is how that uri is read', $sDescription);
		$this->assertStringNotContainsString('there is no uri argument', $sDescription);
		$this->assertStringContainsString('pass that uri, or its class, id and att_code', $sDescription);
		$this->assertSame([], $aSchema['required'], 'a uri says all three, so none is required: the tool refuses a call that names nothing');
	}

	public function testThePortalDoesNotAdvertiseAUriItDoesNotServe(): void
	{
		PortalMode::Remember('itop-portal');

		$this->assertArrayNotHasKey('uri', (new ObjectGetDocument())->getInputSchema()['properties']);
	}

	/** Review 2026-11-23, S4: in the portal a file can only be an attachment's contents, so the id alone names it. */
	public function testInThePortalTheIdAloneNamesTheFile(): void
	{
		PortalMode::Remember('itop-portal');
		$aSchema = (new ObjectGetDocument())->getInputSchema();

		$this->assertSame(['id'], $aSchema['required']);
		$this->assertSame(['Attachment', 7, 'contents'], self::named('', 7, ''));
		$this->assertSame(['Attachment', 7, 'contents'], self::named('Attachment', 7, 'contents'), 'what a client that sends all three sends still works');
		$this->assertSame(['Person', 7, 'picture'], self::named('Person', 7, 'picture'), 'a class that is given is the class asked: the portal then refuses it as it always did');

		$this->expectExceptionMessageMatches('/Name the attachment/');
		self::named('', 0, '');
	}

	public function testTheConsoleStillRequiresThemToBeNamed(): void
	{
		$this->expectExceptionMessageMatches('/Name the document/');
		self::named('', 7, '');
	}

	public function testAUriNamesTheSameDocumentAsItsThreeParts(): void
	{
		$this->assertSame(['Attachment', 5, 'contents'], self::named('', 0, '', 'itop://core/document/Attachment/5/contents'));
		$this->assertSame(['Attachment', 5, 'contents'], self::named('', 0, '', '  itop://core/document/Attachment/5/contents '));
		$this->assertSame(['Attachment', 5, 'contents'], self::named('Attachment', 5, 'contents'));
		$this->assertSame(['Attachment', 5, 'contents'], self::named('Attachment', 5, 'contents', 'itop://core/document/Attachment/5/contents'), 'both, and they agree');
	}

	/** @return array<string, array{0: string}> */
	public static function foreignUris(): array
	{
		return [
			'another host'            => ['itop://other/document/Attachment/5/contents'],
			'another scheme'          => ['https://core/document/Attachment/5/contents'],
			'http, same path'         => ['http://example.com/itop://core/document/Attachment/5/contents'],
			'a file'                  => ['file:///etc/passwd'],
			'the classes resource'    => ['itop://core/classes'],
			'another resource path'   => ['itop://core/class/Attachment'],
			'a query'                 => ['itop://core/document/Attachment/5/contents?x=1'],
			'a fragment'              => ['itop://core/document/Attachment/5/contents#x'],
			'an extra segment'        => ['itop://core/document/Attachment/5/contents/more'],
			'a trailing slash'        => ['itop://core/document/Attachment/5/contents/'],
			'an encoded dot-dot'      => ['itop://core/document/Attachment/%2e%2e/5/contents'],
			'a path traversal'        => ['itop://core/document/../classes'],
			'a user info host'        => ['itop://core@evil/document/Attachment/5/contents'],
			'a zero id'               => ['itop://core/document/Attachment/0/contents'],
			'a negative id'           => ['itop://core/document/Attachment/-1/contents'],
			'a leading-zero id'       => ['itop://core/document/Attachment/05/contents'],
			'a class with a dash'     => ['itop://core/document/Attach-ment/5/contents'],
			'an uppercase scheme'     => ['ITOP://core/document/Attachment/5/contents'],
			'an uppercase host'       => ['itop://CORE/document/Attachment/5/contents'],
			'a newline'               => ["itop://core/document/Attachment/5/contents\nx"],
		];
	}

	/** @dataProvider foreignUris */
	public function testAUriThatIsNotThisServersIsRefusedWithoutEchoingIt(string $sUri): void
	{
		try {
			self::named('', 0, '', $sUri);
			$this->fail('accepted: '.json_encode($sUri));
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('not a document uri of this server', $e->getMessage());
			$this->assertStringNotContainsString(trim($sUri), $e->getMessage(), 'the refusal quotes what it was sent');
		}
	}

	public function testAUriAndArgumentsThatDisagreeAreRefused(): void
	{
		foreach ([['Person', 5, 'contents'], ['Attachment', 6, 'contents'], ['Attachment', 5, 'picture']] as [$sClass, $iId, $sAtt]) {
			try {
				self::named($sClass, $iId, $sAtt, 'itop://core/document/Attachment/5/contents');
				$this->fail('accepted a uri beside arguments that name something else');
			} catch (ToolCallException $e) {
				$this->assertStringContainsString('name different documents', $e->getMessage());
			}
		}
	}

	public function testACallThatNamesNothingIsRefused(): void
	{
		foreach ([self::named(...), ] as $f) {
			foreach ([['', 0, ''], ['Attachment', 0, 'contents'], ['Attachment', 5, ''], ['', 5, 'contents']] as [$sClass, $iId, $sAtt]) {
				try {
					$f($sClass, $iId, $sAtt);
					$this->fail('accepted an incomplete call');
				} catch (ToolCallException $e) {
					$this->assertStringContainsString('Name the document', $e->getMessage());
				}
			}
		}
	}

	/** One path after the naming: a uri reaches no document its three parts would not. */
	public function testTheNamedCallTakesTheSameChecksAsAnyOther(): void
	{
		$oSource = SourceCalls::OfClass(ObjectGetDocument::class);

		$this->assertTrue($oSource->HasCall(\Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation::class.'::IsReserved', 'execute'));
		$this->assertTrue($oSource->HasCall(\Altioo\iTop\Extension\MCP\Service\Access\DocumentFetch::class.'::Fetch', 'execute'));
		$sSource = (string)file_get_contents((new \ReflectionClass(ObjectGetDocument::class))->getFileName());
		$this->assertMatchesRegularExpression('/\[\$class, \$id, \$att_code\] = self::named\(/', $sSource, 'execute does not normalise first');
	}
}
