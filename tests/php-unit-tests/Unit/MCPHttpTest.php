<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

class MCPHttpTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $aServerBackup = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->aServerBackup = $_SERVER;
		unset($_SERVER['HTTP_AUTH_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
	}

	protected function tearDown(): void
	{
		$_SERVER = $this->aServerBackup;
		parent::tearDown();
	}

	public function testPromotesBearerToTheHeaderAuthentTokenReads(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('abc123', $_SERVER['HTTP_AUTH_TOKEN']);
	}

	/**
	 * RFC 7235 credentials are case-insensitive on the scheme, and clients do
	 * differ on it.
	 */
	public function testSchemeMatchIsCaseInsensitive(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'bearer abc123';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('abc123', $_SERVER['HTTP_AUTH_TOKEN']);
	}

	public function testReadsTheRedirectPrefixedVariant(): void
	{
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer rewritten';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('rewritten', $_SERVER['HTTP_AUTH_TOKEN']);
	}

	/**
	 * Basic has to reach LoginBasic untouched, or enabling it as a login mode
	 * would break the moment this helper ran.
	 */
	public function testLeavesOtherSchemesAlone(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertArrayNotHasKey('HTTP_AUTH_TOKEN', $_SERVER);
		$this->assertSame('Basic dXNlcjpwYXNz', $_SERVER['HTTP_AUTHORIZATION']);
	}

	public function testDoesNotOverwriteAnExplicitAuthTokenHeader(): void
	{
		$_SERVER['HTTP_AUTH_TOKEN'] = 'explicit';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer other';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('explicit', $_SERVER['HTTP_AUTH_TOKEN']);
		$this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER);
	}

	/**
	 * LoginBasic claims any request carrying an Authorization header, whatever
	 * its scheme, and GetLoginPluginList() orders the plugins by
	 * allowed_login_types - so with 'basic' listed before 'token' the bearer
	 * would be base64-decoded into garbage instead of reaching the token
	 * plugin.
	 */
	public function testDropsTheBearerHeaderSoLoginBasicCannotClaimIt(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('abc123', $_SERVER['HTTP_AUTH_TOKEN']);
		$this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER);
	}

	public function testDropsTheRedirectPrefixedVariantToo(): void
	{
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer abc123';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertSame('abc123', $_SERVER['HTTP_AUTH_TOKEN']);
		$this->assertArrayNotHasKey('REDIRECT_HTTP_AUTHORIZATION', $_SERVER);
	}

	public function testIgnoresAnEmptyBearerValue(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer   ';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertArrayNotHasKey('HTTP_AUTH_TOKEN', $_SERVER);
	}

	public function testIsANoOpWithoutAnyCredential(): void
	{
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertArrayNotHasKey('HTTP_AUTH_TOKEN', $_SERVER);
		$this->assertNull(MCPHttp::ReadBearerToken());
	}

	public function testReadBearerTokenReturnsTheRawCredential(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer  spaced-out  ';

		$this->assertSame('spaced-out', MCPHttp::ReadBearerToken());
	}

	/**
	 * A 401 that carries no challenge tells a client it failed, not what it
	 * failed at - and RFC 9110 requires one on the status.
	 */
	public function testTheChallengeNamesTheSchemeAndTheRealm(): void
	{
		$sChallenge = MCPHttp::BearerChallenge(null);

		$this->assertStringStartsWith('Bearer ', $sChallenge);
		$this->assertStringContainsString('realm=', $sChallenge);
		$this->assertStringNotContainsString('resource_metadata', $sChallenge);
	}

	/**
	 * RFC 9728: this is what a client with nothing but a Connect button
	 * follows to find the authorization server.
	 */
	public function testAConfiguredMetadataDocumentIsAdvertised(): void
	{
		$sChallenge = MCPHttp::BearerChallenge('https://sso.example.com/.well-known/oauth-protected-resource');

		$this->assertStringContainsString(
			'resource_metadata="https://sso.example.com/.well-known/oauth-protected-resource"',
			$sChallenge
		);
	}

	/**
	 * The URL comes from config-itop.php, so this is not about an attacker -
	 * it is about a typo producing no parameter rather than a split header.
	 *
	 * @dataProvider unusableMetadataUrlProvider
	 */
	public function testAnUnusableUrlIsLeftOutRatherThanEmitted(string $sUrl): void
	{
		$sChallenge = MCPHttp::BearerChallenge($sUrl);

		$this->assertSame(MCPHttp::BearerChallenge(null), $sChallenge);
	}

	/** @return array<string, array{0: string}> */
	public static function unusableMetadataUrlProvider(): array
	{
		return [
			'empty'            => [''],
			'blank'            => ['   '],
			'no scheme'        => ['sso.example.com/meta'],
			'not http'         => ['file:///etc/passwd'],
			'carriage return'  => ["https://sso.example.com/meta\r\nX-Injected: 1"],
			'newline'          => ["https://sso.example.com/meta\nX-Injected: 1"],
			'closing quote'    => ['https://sso.example.com/"meta'],
		];
	}

	// ------------------------------------------------------------------
	// Which hosts this endpoint answers to
	//
	// The same rule the SDK's DnsRebindingProtectionMiddleware applies, held
	// here because the controller decides it a second time - before
	// ResetSession(), which is the part that matters. The two reading the same
	// list is what stops them disagreeing; these tests are what stop this copy
	// drifting from the one in the SDK.
	// ------------------------------------------------------------------

	private const SERVED = ['itop.example.com', 'localhost', '[::1]'];

	public function testAHostOnTheListIsServed(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost(null, 'itop.example.com', self::SERVED));
	}

	public function testAHostThatIsNotOnTheListIsRefused(): void
	{
		$this->assertFalse(MCPHttp::IsAllowedHost(null, 'evil.example.com', self::SERVED));
	}

	/** A Host header carries the port; the list is written without one. */
	public function testThePortIsIgnored(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost(null, 'itop.example.com:8443', self::SERVED));
	}

	public function testTheComparisonIsCaseInsensitive(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost(null, 'iTop.Example.COM', self::SERVED));
	}

	/** parse_url() returns IPv6 bracketed, so that is the form a list uses. */
	public function testAnIPv6LiteralKeepsItsBrackets(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost(null, '[::1]:8080', self::SERVED));
		$this->assertFalse(MCPHttp::IsAllowedHost(null, '[::2]:8080', self::SERVED));
	}

	/**
	 * Origin decides when it is there, and Host is not consulted at all - which
	 * is what the middleware does, and the case a rebinding attack produces: a
	 * Host the server recognises, sent from a page that is not on it.
	 */
	public function testOriginDecidesWhenItIsPresent(): void
	{
		$this->assertFalse(
			MCPHttp::IsAllowedHost('https://evil.example.com', 'itop.example.com', self::SERVED)
		);
		$this->assertTrue(
			MCPHttp::IsAllowedHost('https://itop.example.com', 'itop.example.com', self::SERVED)
		);
	}

	/** An Origin that is not a URL at all names no host, so it matches nothing. */
	public function testAnUnparseableOriginIsRefused(): void
	{
		$this->assertFalse(MCPHttp::IsAllowedHost('null', 'itop.example.com', self::SERVED));
	}

	/**
	 * The escape hatch for a deployment whose hostname the module cannot know.
	 * It has to be the whole answer, or configuring it would be a way to
	 * accidentally allow everything while believing a list was in force.
	 */
	public function testTheWildcardEntryAcceptsAnything(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost('https://anywhere.example', 'whatever', [MCPHttp::ANY_HOST]));
	}

	/** HTTP/1.0 sends no Host; no MCP client speaks it, and the SDK lets it by. */
	public function testNoOriginAndNoHostIsLeftAlone(): void
	{
		$this->assertTrue(MCPHttp::IsAllowedHost(null, null, self::SERVED));
	}

	/**
	 * An empty list is not "allow everything" - MCPHelper never produces one,
	 * and if it ever did the safe reading is that nothing is served.
	 */
	public function testAnEmptyListServesNoHost(): void
	{
		$this->assertFalse(MCPHttp::IsAllowedHost(null, 'itop.example.com', []));
	}

	// ------------------------------------------------------------------
	// What a POST may be sent as
	// ------------------------------------------------------------------

	/**
	 * These three are exactly what a browser can send cross-origin without a
	 * preflight. Refusing them is what makes the endpoint unreachable as a CORS
	 * simple request, and therefore what closes the CSRF class.
	 *
	 * @dataProvider simpleRequestContentTypeProvider
	 */
	public function testTheContentTypesThatNeedNoPreflightAreRefused(string $sContentType): void
	{
		$this->assertFalse(MCPHttp::IsJsonMediaType($sContentType));
	}

	/** @return array<string, array{0: string}> */
	public static function simpleRequestContentTypeProvider(): array
	{
		return [
			'text/plain'  => ['text/plain'],
			'form'        => ['application/x-www-form-urlencoded'],
			'multipart'   => ['multipart/form-data; boundary=----x'],
			'nothing'     => [''],
		];
	}

	public function testAMissingContentTypeIsRefused(): void
	{
		$this->assertFalse(MCPHttp::IsJsonMediaType(null));
	}

	public function testJsonIsAccepted(): void
	{
		$this->assertTrue(MCPHttp::IsJsonMediaType('application/json'));
	}

	/**
	 * Clients differ on the parameters and the casing, and none of that changes
	 * whether the request could have been sent without a preflight.
	 *
	 * @dataProvider acceptableJsonContentTypeProvider
	 */
	public function testTheSpellingOfTheJsonTypeDoesNotMatter(string $sContentType): void
	{
		$this->assertTrue(MCPHttp::IsJsonMediaType($sContentType));
	}

	/** @return array<string, array{0: string}> */
	public static function acceptableJsonContentTypeProvider(): array
	{
		return [
			'charset'   => ['application/json; charset=utf-8'],
			'uppercase' => ['Application/JSON'],
			'padded'    => ['  application/json  '],
			'suffixed'  => ['application/vnd.acme+json'],
		];
	}
}
