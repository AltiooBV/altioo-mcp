<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Controller\MCPResponse;
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
		unset(
			$_SERVER['HTTP_AUTH_TOKEN'],
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['PHP_AUTH_USER'],
			$_SERVER['REMOTE_USER'],
			$_SERVER['REDIRECT_REMOTE_USER']
		);
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

	/**
	 * The predicate MCPController gates LoginWebPage::ResetSession() on.
	 *
	 * A request with nothing but cookies is the CSRF shape: a page on any
	 * website makes the browser call this URL, the browser attaches the iTop
	 * session by itself, and the reset would log the user out of the console.
	 */
	public function testACookieOnlyRequestCarriesNoCredential(): void
	{
		$_SERVER['HTTP_COOKIE'] = 'itop-a1b2c3=deadbeef';

		$this->assertFalse(MCPHttp::CarriesACredential());
	}

	public function testABearerRequestCarriesACredential(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';

		$this->assertTrue(MCPHttp::CarriesACredential());
	}

	public function testAnAuthTokenHeaderCarriesACredential(): void
	{
		$_SERVER['HTTP_AUTH_TOKEN'] = 'abc123';

		$this->assertTrue(MCPHttp::CarriesACredential());
	}

	/**
	 * Basic is not a bearer and is left for LoginBasic to consume, so
	 * CurrentAuthToken() is null for it. Gating the reset on the token alone
	 * would therefore have refused every Basic-authenticated client.
	 */
	public function testBasicCarriesACredentialEvenThoughItIsNotAToken(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('user:password');

		$this->assertTrue(MCPHttp::CarriesACredential());
		$this->assertNull(MCPHttp::ReadBearerToken());
	}

	/**
	 * mod_php parses Basic itself and removes the header, leaving only this.
	 */
	public function testBasicDecodedByTheSapiStillCounts(): void
	{
		$_SERVER['PHP_AUTH_USER'] = 'someone';

		$this->assertTrue(MCPHttp::CarriesACredential());
	}

	/**
	 * An authenticating proxy in front of iTop decides who this is and says so
	 * here. Refusing it would break the deployment the README recommends.
	 */
	public function testAnIdentityDecidedUpstreamCounts(): void
	{
		$_SERVER['REMOTE_USER'] = 'someone@example.org';

		$this->assertTrue(MCPHttp::CarriesACredential());
	}

	public function testTheRedirectPrefixedVariantsCountToo(): void
	{
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer abc123';
		$this->assertTrue(MCPHttp::CarriesACredential());

		unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
		$_SERVER['REDIRECT_REMOTE_USER'] = 'someone';
		$this->assertTrue(MCPHttp::CarriesACredential());
	}

	/**
	 * A header that is present and blank is not evidence of anything.
	 */
	public function testAnEmptyAuthorizationHeaderIsNotACredential(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = '   ';

		$this->assertFalse(MCPHttp::CarriesACredential());
	}

	/**
	 * Review 2026-11-18, RT-5: iTop's LoginBasic claims any Authorization header and splits it on a colon without checking, so
	 * these reached it and raised "Undefined array key 1". None of them is a credential a login could use.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unusableAuthorizationProvider(): array
	{
		return [
			'bearer alone' => ['Bearer'],
			'bearer and a blank' => ['Bearer '],
			'bearer and blanks' => ['Bearer      '],
			'basic alone' => ['Basic'],
			'basic with junk' => ['Basic !!!!'],
			'basic with no colon once decoded' => ['Basic '.base64_encode('nocolonhere')],
			'basic with no user' => ['Basic '.base64_encode(':password')],
			'another scheme' => ['Digest username="a"'],
		];
	}

	/** @dataProvider unusableAuthorizationProvider */
	public function testAMalformedAuthorizationHeaderIsNoCredential(string $sHeader): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = $sHeader;

		$this->assertFalse(MCPHttp::CarriesACredential());
	}

	/** @dataProvider unusableAuthorizationProvider */
	public function testAMalformedAuthorizationHeaderNeverReachesTheLogin(string $sHeader): void
	{
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $sHeader;
		$_SERVER['HTTP_AUTH_TOKEN'] = 'abc123';

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertArrayNotHasKey('REDIRECT_HTTP_AUTHORIZATION', $_SERVER, 'iTop\'s LoginBasic would read it');
		$this->assertTrue(MCPHttp::CarriesACredential(), 'the Auth-Token beside it still counts');
	}

	public function testAUsableBasicHeaderIsKept(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('user:pass:with:colons');

		MCPHttp::PromoteBearerToAuthToken();

		$this->assertArrayHasKey('HTTP_AUTHORIZATION', $_SERVER);
		$this->assertTrue(MCPHttp::CarriesACredential());
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
		$sChallenge = MCPResponse::BearerChallenge(null);

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
		$sChallenge = MCPResponse::BearerChallenge('https://sso.example.com/.well-known/oauth-protected-resource');

		$this->assertStringContainsString(
			'resource_metadata="https://sso.example.com/.well-known/oauth-protected-resource"',
			$sChallenge
		);
	}

	/**
	 * RFC 6750: a token that was presented and refused is invalid_token, which
	 * is what tells an OAuth client to refresh or connect again rather than
	 * read the refusal as a wrong password.
	 */
	public function testARefusedTokenIsNamedInTheChallenge(): void
	{
		$sChallenge = MCPResponse::BearerChallenge('https://itop.example.com/.well-known/oauth-protected-resource', 'invalid_token');

		$this->assertStringContainsString(', error="invalid_token"', $sChallenge);
		$this->assertStringContainsString('resource_metadata=', $sChallenge);
	}

	/** A request that presented nothing gets no error code (RFC 6750 section 3.1). */
	public function testNoErrorIsNamedUnlessOneIsGiven(): void
	{
		$this->assertStringNotContainsString('error=', MCPResponse::BearerChallenge(null));
	}

	public function testAnErrorThatIsNotACodeIsLeftOut(): void
	{
		$this->assertSame(MCPResponse::BearerChallenge(null), MCPResponse::BearerChallenge(null, "invalid_token\", x=\"y"));
	}

	/**
	 * The URL comes from config-itop.php, so this is not about an attacker -
	 * it is about a typo producing no parameter rather than a split header.
	 *
	 * @dataProvider unusableMetadataUrlProvider
	 */
	public function testAnUnusableUrlIsLeftOutRatherThanEmitted(string $sUrl): void
	{
		$sChallenge = MCPResponse::BearerChallenge($sUrl);

		$this->assertSame(MCPResponse::BearerChallenge(null), $sChallenge);
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
	 * An empty list is not "allow everything" - Settings never produces one,
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

	/**
	 * Review 2026-11-18, RT-2: under Basic the password stayed in $_SERVER for the whole call
	 * (PHP_AUTH_PW, and the header base64 of it), and the PSR-7 request is built from $_SERVER.
	 */
	public function testForgettingTheCredentialDropsWhatBasicLeftInTheServerArray(): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic '.base64_encode('someone:hunter2');
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'];
		$_SERVER['PHP_AUTH_USER'] = 'someone';
		$_SERVER['PHP_AUTH_PW'] = 'hunter2';
		$_SERVER['PHP_AUTH_DIGEST'] = 'digest-response';

		MCPHttp::PromoteBearerToAuthToken();
		$this->assertTrue(MCPHttp::CarriesACredential(), 'Basic must still be seen until the login has run');
		MCPHttp::ForgetAuthToken();

		$this->assertStringNotContainsString('hunter2', serialize($_SERVER));
		$this->assertStringNotContainsString(base64_encode('someone:hunter2'), serialize($_SERVER));
		$this->assertArrayNotHasKey('PHP_AUTH_PW', $_SERVER);
		$this->assertArrayNotHasKey('PHP_AUTH_DIGEST', $_SERVER);
		$this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $_SERVER);
		$this->assertArrayNotHasKey('REDIRECT_HTTP_AUTHORIZATION', $_SERVER);
	}
}
