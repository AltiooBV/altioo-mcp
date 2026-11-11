<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The request is opened and closed in one place, and portal mode fails closed
 * through both (doc/adr/0015, rule 5; the fail-open of 9c2e25f).
 *
 * RequestStateTest holds that every static property is cleared; these hold the
 * portal pair's coherence, which a property-by-property check cannot.
 */
class RequestScopeTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		RequestScope::Close();
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	/**
	 * Entering a portal sets the mode and the access together: the request is
	 * refused for want of a readable portal here, never answered with the
	 * console's rules.
	 */
	public function testEnteringAPortalSetsTheModeAndTheAccessTogether(): void
	{
		RequestScope::EnterPortal('itop-portal');

		$this->assertSame('itop-portal', PortalMode::Current());
		$this->expectException(MCPPortalException::class);
		ObjectAccess::Current(); // the portal cannot be read without iTop: refused, not the console's
	}

	/**
	 * Open in portal mode, then Close: the mode, the access, the portal's policy and the access policy are
	 * all forgotten, and the request is a console request again.
	 */
	public function testClosingAPortalRequestForgetsEveryHalfOfIt(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-write']);
		RequestScope::Open($oPolicy, 'itop-portal');

		(new ReflectionProperty(PortalPolicy::class, 'oCurrent'))->setValue(null, (new \ReflectionClass(PortalPolicy::class))->newInstanceWithoutConstructor());
		$this->assertSame('itop-portal', PortalMode::Current());
		$this->assertSame($oPolicy, AccessPolicy::Current());

		RequestScope::Close();

		$this->assertNull(PortalMode::Current(), 'the portal mode outlived the request');
		$this->assertNull(AccessPolicy::Current(), 'the access policy outlived the request');
		$this->assertNull((new ReflectionProperty(PortalPolicy::class, 'oCurrent'))->getValue(), 'the portal policy outlived the request');
		$this->assertNull((new ReflectionProperty(ObjectAccess::class, 'fCurrent'))->getValue(), 'the portal access outlived the request');
		$oAccess = ObjectAccess::Current();
		$this->assertNotInstanceOf(PortalAccess::class, $oAccess, 'a closed request still answers with the portal');
	}

	/**
	 * The failure the pair exists to prevent: portal mode set and its access not.
	 * Closing clears both, so no order of the two forgets can leave one behind;
	 * and a mode set alone is still refused.
	 */
	public function testPortalModeWithoutItsAccessStillFailsClosed(): void
	{
		PortalMode::Remember('itop-portal');
		try {
			ObjectAccess::Current();
			$this->fail('portal mode with no access served the console\'s rules');
		} catch (MCPPortalException) {
			$this->addToAssertionCount(1);
		}

		RequestScope::Close();
		$this->assertNotInstanceOf(PortalAccess::class, ObjectAccess::Current());
	}

	/** Open without a portal leaves the mode as the login set it. */
	public function testOpenWithoutAPortalKeepsTheModeTheLoginSet(): void
	{
		RequestScope::EnterPortal('itop-portal');
		RequestScope::Open(AccessPolicy::FromScopes(['MCP']));

		$this->assertSame('itop-portal', PortalMode::Current());
		$this->assertNotNull(AccessPolicy::Current());
	}
}
