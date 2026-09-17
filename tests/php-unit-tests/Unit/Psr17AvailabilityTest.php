<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Http\Discovery\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A PSR-17 implementation is installed.
 *
 * MCPService builds every request and response through Http\Discovery's
 * Psr17Factory, which is a *locator*: it holds no implementation itself and
 * throws NotFoundException when it cannot find one on the autoloader. The
 * module therefore depends on a concrete implementation that no line of its
 * source ever names - the kind of dependency that looks unused to anyone
 * reading the code, and that composer remove will happily take out.
 *
 * Requiring only psr/http-factory (the interfaces) and php-http/discovery
 * would leave the endpoint working solely because iTop happens to ship
 * guzzlehttp/psr7 and index.php loads iTop's autoloader too - a working
 * endpoint contingent on another project's dependency list, with which
 * implementation gets used decided by load order.
 *
 * So nyholm/psr7 is required explicitly. Nyholm rather than Guzzle on
 * purpose: a Composer loader prepends itself, so of the two in this process
 * the one registered last answers first - and that is this module's. iTop
 * registers its own from bootstrap.inc.php, reached on the first line of
 * index.php; this module's vendor/autoload.php is a datamodel file and is
 * loaded later, during MetaModel::Startup(). A second copy of GuzzleHttp\Psr7
 * vendored here would therefore be the copy that *wins*, shadowing iTop's own
 * for every request to the environment rather than only the ones this
 * endpoint serves. A namespace iTop does not ship displaces nothing.
 *
 * This test needs no iTop, which is the point - it fails in a bare checkout
 * the moment the dependency goes away, rather than in production.
 */
class Psr17AvailabilityTest extends TestCase
{
	/**
	 * Discovery resolves without iTop's autoloader in the picture. Under
	 * PHPUnit only this module's vendor/ is loaded, so a pass here is the
	 * statement that the module carries its own implementation.
	 */
	public function testDiscoveryFindsAnImplementation(): void
	{
		$oFactory = new Psr17Factory();

		$this->assertInstanceOf(
			ServerRequestFactoryInterface::class,
			$oFactory,
			'no PSR-17 implementation is installed: MCPService cannot build a request'
		);
		$this->assertInstanceOf(StreamFactoryInterface::class, $oFactory);
		$this->assertInstanceOf(ResponseFactoryInterface::class, $oFactory);
	}

	/**
	 * Locating one is not the same as it working. These three calls are
	 * exactly what MCPService and the SDK's transport make.
	 */
	public function testTheThreeFactoriesMCPServiceUsesAllWork(): void
	{
		$oFactory = new Psr17Factory();

		$oRequest = $oFactory->createServerRequest('POST', 'https://itop.example.com/mcp');
		$this->assertSame('POST', $oRequest->getMethod());

		$oStream = $oFactory->createStream('{"jsonrpc":"2.0"}');
		$this->assertSame('{"jsonrpc":"2.0"}', (string)$oStream);

		$this->assertSame(204, $oFactory->createResponse(204)->getStatusCode());
	}

	/**
	 * The implementation is the one that was chosen, not whatever happened to
	 * be on the autoloader.
	 *
	 * Named here so that swapping it is a deliberate edit in two places. If it
	 * ever becomes GuzzleHttp\Psr7, read the class comment first: that one
	 * collides with iTop's copy.
	 */
	public function testTheImplementationIsTheOneThisModuleRequires(): void
	{
		$oRequest = (new Psr17Factory())->createServerRequest('GET', 'https://itop.example.com/mcp');

		$this->assertStringStartsWith(
			'Nyholm\\Psr7\\',
			get_class($oRequest),
			'the PSR-17 implementation changed: check it is not a second copy of a namespace iTop already ships'
		);
	}
}
