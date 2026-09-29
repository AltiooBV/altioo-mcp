<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Every scope the code interprets is one a token can actually hold.
 *
 * AccessPolicy reads scopes off the token as strings, so a scope it knows
 * and the datamodel does not declare is still parsed, still tested, still
 * documented - and never reaches it: scope is an enum set, and iTop drops a
 * value it does not declare at Set() time, without an error. MCP-advisory
 * shipped that way. The token saved as MCP-write alone and wrote for real,
 * which is the one outcome that scope exists to rule out.
 */
class TokenScopeDeclarationTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	private const TOKEN_CLASSES = ['PersonalToken', 'UserToken'];

	private const LANGUAGES = ['en-us', 'fr-fr'];

	/**
	 * @return array<string, array{string}>
	 */
	public static function interpretedScopes(): array
	{
		$aScopes = [MCPContext::SCOPE_MCP, MCPContext::SCOPE_ADVISORY];
		foreach (AccessPolicy::CAPABILITIES as $sCapability) {
			$aScopes[] = MCPContext::SCOPE_MCP.'-'.$sCapability;
		}

		$aCases = [];
		foreach ($aScopes as $sScope) {
			$aCases[$sScope] = [$sScope];
		}

		return $aCases;
	}

	/**
	 * @dataProvider interpretedScopes
	 */
	public function testAnInterpretedScopeIsDeclaredOnEveryTokenClass(string $sScope): void
	{
		foreach (self::TOKEN_CLASSES as $sClass) {
			$this->assertContains(
				$sScope,
				self::declaredScopes($sClass),
				sprintf('%s is read by AccessPolicy but %s.scope does not declare it, so iTop drops it from the token without a word.', $sScope, $sClass)
			);
		}
	}

	public function testBothTokenClassesDeclareTheSameScopes(): void
	{
		$this->assertSame(self::declaredScopes('PersonalToken'), self::declaredScopes('UserToken'));
	}

	public function testEveryDeclaredScopeIsLabelledInEveryLanguage(): void
	{
		foreach (self::LANGUAGES as $sLanguage) {
			$sDictionary = (string) file_get_contents(self::ROOT.'/datamodel.altioo-mcp.dict.'.$sLanguage.'.xml');
			foreach (self::TOKEN_CLASSES as $sClass) {
				foreach (self::declaredScopes($sClass) as $sScope) {
					foreach (['', '+'] as $sSuffix) {
						$this->assertStringContainsString(
							sprintf('id="Class:%s/Attribute:scope/Value:%s%s"', $sClass, $sScope, $sSuffix),
							$sDictionary,
							sprintf('%s.scope value %s has no %s label in %s', $sClass, $sScope, $sSuffix === '' ? 'short' : 'long', $sLanguage)
						);
					}
				}
			}
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function declaredScopes(string $sClass): array
	{
		$oXml = new SimpleXMLElement((string) file_get_contents(self::ROOT.'/datamodel.altioo-mcp.xml'));

		$aScopes = [];
		foreach ($oXml->xpath(sprintf('/itop_design/classes/class[@id="%s"]/fields/field[@id="scope"]/values/value/code', $sClass)) ?: [] as $oCode) {
			$aScopes[] = (string) $oCode;
		}
		sort($aScopes);

		return $aScopes;
	}
}
