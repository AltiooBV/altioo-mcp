<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
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
}
