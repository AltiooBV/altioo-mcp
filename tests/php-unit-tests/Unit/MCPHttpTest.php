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
}
