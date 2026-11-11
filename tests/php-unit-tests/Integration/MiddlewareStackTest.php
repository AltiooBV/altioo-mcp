<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use utils;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The middleware MCPService puts in front of the SDK, built from the
 * instance's own settings.
 *
 * Nothing else builds it short of a real HTTP request: ServerBootTest and
 * ProtocolEraTest pass an empty list, on purpose. So when mcp/sdk 0.9.0 gave
 * DnsRebindingProtectionMiddleware a new second argument, the unit and
 * integration suites stayed green while every authenticated request on an
 * instance with a host allow-list - the default - died on a TypeError.
 *
 * Settings are changed in memory only and put back afterwards.
 */
class MiddlewareStackTest extends ItopDataTestCaseAlias
{
	private const HOST = 'itop.example.com';

	/** @var array<string, mixed> */
	private array $aSaved = [];

	protected function setUp(): void
	{
		parent::setUp();

		foreach ([Settings::MODULE_SETTING_ALLOWED_HOSTS, Settings::MODULE_SETTING_ALLOWED_ORIGINS] as $sSetting) {
			$this->aSaved[$sSetting] = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, $sSetting, null);
		}

		$this->configure([self::HOST], ['https://client.example.org']);
	}

	protected function tearDown(): void
	{
		foreach ($this->aSaved as $sSetting => $mValue) {
			utils::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, $sSetting, $mValue);
		}

		parent::tearDown();
	}

	public function testTheStackIsBuiltWithTheHostCheck(): void
	{
		$aClasses = array_map('get_class', $this->stack());

		$this->assertContains(DnsRebindingProtectionMiddleware::class, $aClasses);
	}

	public function testAnyHostLeavesTheHostCheckOut(): void
	{
		$this->configure([MCPHttp::ANY_HOST], []);

		$this->assertNotContains(DnsRebindingProtectionMiddleware::class, array_map('get_class', $this->stack()));
	}

	public function testAnAllowedHostWithNoOriginIsLetThrough(): void
	{
		$this->assertSame(200, $this->status(self::HOST, null));
	}

	public function testAnotherHostIsRefused(): void
	{
		$this->assertSame(403, $this->status('attacker.example.net', null));
	}

	public function testAListedOriginIsLetThrough(): void
	{
		$this->assertSame(200, $this->status(self::HOST, 'https://client.example.org'));
	}

	public function testTheEndpointsOwnOriginIsLetThroughUnlisted(): void
	{
		$this->assertSame(200, $this->status(self::HOST, 'https://'.self::HOST));
	}

	/**
	 * What changed with SDK 0.9: an origin on an allowed host used to pass on
	 * the host alone. It now has to be listed in mcp_allowed_origins, which
	 * the CHANGELOG tells operators.
	 */
	public function testAnUnlistedOriginOnAnAllowedHostIsRefused(): void
	{
		$this->assertSame(403, $this->status(self::HOST, 'http://'.self::HOST.':8080'));
	}

	/**
	 * @param array<int, string> $aHosts
	 * @param array<int, string> $aOrigins
	 */
	private function configure(array $aHosts, array $aOrigins): void
	{
		utils::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_ALLOWED_HOSTS, $aHosts);
		utils::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_ALLOWED_ORIGINS, $aOrigins);
	}

	/**
	 * @return array<int, MiddlewareInterface>
	 */
	private function stack(): array
	{
		return (new ReflectionMethod(MCPService::class, 'middleware'))->invoke(null, new Psr17Factory());
	}

	/**
	 * The status a POST gets from the stack, in front of a handler that
	 * answers 200 to whatever reaches it.
	 */
	private function status(string $sHost, ?string $sOrigin): int
	{
		$oFactory = new Psr17Factory();
		$oRequest = $oFactory
			->createServerRequest('POST', 'https://'.self::HOST.'/extensions/altioo-mcp/index.php')
			->withHeader('Host', $sHost);

		if ($sOrigin !== null) {
			$oRequest = $oRequest->withHeader('Origin', $sOrigin);
		}

		$oHandler = new class($oFactory) implements RequestHandlerInterface {
			public function __construct(private Psr17Factory $oFactory)
			{
			}

			public function handle(ServerRequestInterface $request): ResponseInterface
			{
				return $this->oFactory->createResponse(200);
			}
		};

		foreach (array_reverse($this->stack()) as $oMiddleware) {
			$oNext = $oHandler;
			$oHandler = new class($oMiddleware, $oNext) implements RequestHandlerInterface {
				public function __construct(private MiddlewareInterface $oMiddleware, private RequestHandlerInterface $oNext)
				{
				}

				public function handle(ServerRequestInterface $request): ResponseInterface
				{
					return $this->oMiddleware->process($request, $this->oNext);
				}
			};
		}

		return $oHandler->handle($oRequest)->getStatusCode();
	}
}
