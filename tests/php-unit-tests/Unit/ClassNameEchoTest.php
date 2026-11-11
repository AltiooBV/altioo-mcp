<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\ResourceTemplates\ClassDetail;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalDescription;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A class refusal quotes a class name back, and nothing that is not one.
 *
 * `core_portal_describe` given `UserRequest' OR 1=1 --` answered "The portal
 * offers no class 'UserRequest' OR 1=1 --'", and the same text went into the
 * audit row's message. iTop escapes that row in the console, so it was not a
 * hole; it was the caller's text, verbatim, in a place every later reader has
 * to remember to escape. A typo is still quoted, because the quote is how a
 * client fixes it.
 */
class ClassNameEchoTest extends TestCase
{
	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function values(): array
	{
		return [
			'a class'                    => ['UserRequest', true],
			'a link class'               => ['lnkContactToTicket', true],
			'a leading underscore'       => ['_Private', true],
			'a typo is still a name'     => ['UserReqest', true],
			'the injection'              => ["UserRequest' OR 1=1 --", false],
			'markup'                     => ['<img src=x onerror=alert(1)>', false],
			'a space'                    => ['User Request', false],
			'a leading digit'            => ['1Class', false],
			'a trailing newline'         => ["UserRequest\n", false],
			'non-ASCII'                  => ['Ticketé', false],
			'empty'                      => ['', false],
		];
	}

	/**
	 * @dataProvider values
	 */
	public function testOnlyAClassNameShapeIsRecognised(string $sValue, bool $bExpected): void
	{
		$this->assertSame($bExpected, MCPHelper::LooksLikeClassName($sValue));
	}

	public function testTheRefusalQuotesAClassName(): void
	{
		$this->assertStringContainsString("Unknown class 'UserReqest'", MCPHelper::UnreadableClassRefusal('UserReqest'));
	}

	public function testTheRefusalDoesNotQuoteAnythingElse(): void
	{
		$sInjection = "UserRequest' OR 1=1 --";
		$sRefusal = MCPHelper::UnreadableClassRefusal($sInjection);

		$this->assertStringNotContainsString('OR 1=1', $sRefusal, 'the caller\'s text came back');
		$this->assertStringContainsString('not a class name', $sRefusal);
		$this->assertSame(
			$sRefusal,
			MCPHelper::UnreadableClassRefusal('<b>x</b>'),
			'one answer for every value that is no class name, so none of it is reflected'
		);
	}

	/**
	 * describe refuses inside the portal's request, which a unit test cannot
	 * build, so this reads the method instead.
	 */
	public function testThePortalDescriptionChecksTheShapeBeforeQuoting(): void
	{
		$sBody = $this->methodBody(PortalDescription::class, 'Of');

		$iCheck = strpos($sBody, 'MCPHelper::LooksLikeClassName($sClass)');
		$iQuote = strpos($sBody, "offers no class '%s'");
		$this->assertNotFalse($iCheck, 'describe quotes the class without checking its shape');
		$this->assertNotFalse($iQuote);
		$this->assertLessThan($iQuote, $iCheck, 'the shape is checked after the class is already quoted');
	}

	/**
	 * The class resource wrote its own "Unknown class '%s'." and so quoted
	 * anything; it refuses through the helper like every tool.
	 */
	public function testTheClassResourceRefusesThroughTheHelper(): void
	{
		$sBody = $this->methodBody(ClassDetail::class, 'read');

		$this->assertStringContainsString('MCPHelper::UnreadableClassRefusal($sClass)', $sBody);
		$this->assertStringNotContainsString('Unknown class', $sBody, 'a refusal of its own wording is back');
	}

	/**
	 * The body of one method, comments stripped - otherwise a phrase appearing
	 * only in a doc comment would satisfy the search.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
