<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use Combodo\iTop\AuthentToken\Hook\TokenLoginExtension;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the endpoint believes about the credential it was shown.
 *
 * AccessPolicyTest covers the consumer of a scope list - what a policy built
 * from one serves - and never the producer. This is the producer, it sits on
 * the authentication path (MCPController, MCPService), and it had no test.
 *
 * Two of the three methods are asked their question when something has already
 * gone wrong: an iTop that is not there, an authent-token that is not
 * installed, a credential that will not decrypt a second time. Those are the
 * paths worth pinning, because each of them is one line away from answering
 * "no restriction" instead of "unknown".
 */
class TokenScopesTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $aServerBackup = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->aServerBackup = $_SERVER;
		unset($_SERVER['HTTP_AUTH_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
		MCPHttp::ForgetAuthToken();
	}

	protected function tearDown(): void
	{
		MCPHttp::ForgetAuthToken();
		$_SERVER = $this->aServerBackup;
		parent::tearDown();
	}

	/**
	 * iTop honours a token scope only when a context tag of the same name was
	 * pushed before login, so a scope nobody pushes is a token that cannot log
	 * in at all. MCP is the one that must always be pushed: it is the scope the
	 * endpoint itself is named by, and it is not read from anywhere.
	 */
	public function testTheBaseScopeIsAlwaysDeclared(): void
	{
		$this->assertContains(MCPContext::SCOPE_MCP, TokenScopes::DeclaredContextTags());
	}

	/**
	 * Without a MetaModel - or with one that raises reading an attribute
	 * definition - the enumeration cannot be read. Returning nothing there
	 * would push no tags, and every token on the instance would stop being able
	 * to log in; the failure has to degrade to "the base scope only".
	 *
	 * This is the path the unit suite takes when it runs outside an iTop, which
	 * is where it usually runs.
	 */
	public function testAnUnreadableEnumerationLeavesTheBaseScopeStanding(): void
	{
		if (class_exists('MetaModel')) {
			$this->markTestSkipped('an iTop is loaded, so the enumeration is readable.');
		}

		$this->assertSame([MCPContext::SCOPE_MCP], TokenScopes::DeclaredContextTags());
	}

	/**
	 * PersonalToken and UserToken carry the same enumeration, so every value is
	 * seen twice. A duplicate tag pushed twice is harmless and a duplicate tag
	 * in a list somebody reads is not.
	 */
	public function testTheDeclaredTagsAreDistinctAndAllOurs(): void
	{
		$aTags = TokenScopes::DeclaredContextTags();

		$this->assertSame(array_values(array_unique($aTags)), $aTags, 'the same scope was declared twice');
		foreach ($aTags as $sTag) {
			$this->assertStringStartsWith(MCPContext::SCOPE_MCP, $sTag, 'a scope outside this module was declared');
		}
	}

	public function testARequestWithNoCredentialCarriesNoToken(): void
	{
		$this->assertFalse(TokenScopes::RequestCarriesAToken());
	}

	public function testABearerRequestCarriesAToken(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer a-token';
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertTrue(TokenScopes::RequestCarriesAToken());
	}

	/**
	 * ForgetAuthToken() runs before the PSR-7 request is built, and everything
	 * downstream of it has to agree that there is no longer a token to read.
	 */
	public function testForgettingTheTokenIsVisibleHere(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer a-token';
		MCPHttp::PromoteBearerToAuthToken();
		MCPHttp::ForgetAuthToken();

		$this->assertFalse(TokenScopes::RequestCarriesAToken());
		$this->assertNull(TokenScopes::OfCurrentRequest());
	}

	/**
	 * Null, not []. The two are read differently by everything downstream: []
	 * is "this token holds no scopes", null is "the scopes could not be
	 * established", and only the second one is answered with the narrowest
	 * policy. A method that returned [] on failure would be handing a token
	 * minted read-only the treatment of one that was not.
	 */
	public function testUnknownScopesAreNullRatherThanEmpty(): void
	{
		$mScopes = TokenScopes::OfCurrentRequest();

		$this->assertNull($mScopes);
		$this->assertNotSame([], $mScopes);
	}

	/**
	 * authent-token is a declared dependency of this module, so an instance
	 * missing it is broken rather than merely unconfigured - but the endpoint
	 * still has to answer, and the answer is "unknown".
	 */
	public function testScopesAreUnknownWhenAuthentTokenIsAbsent(): void
	{
		if (class_exists(TokenLoginExtension::class)) {
			$this->markTestSkipped('authent-token is installed, so this path is unreachable here.');
		}

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer a-token';
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertNull(TokenScopes::OfCurrentRequest());
	}
}
