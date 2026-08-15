<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\UnavailableTool;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the operator's kill switch accepts.
 *
 * Naming the identifier is the ordinary case. Naming the class matters when
 * two extensions claimed one identifier: the registry then serves it to
 * nobody, and the only way to say which of the two should come back is to
 * name the class, because the identifier no longer distinguishes them.
 *
 * The profile branch is exercised elsewhere - it calls UserRights and needs a
 * live iTop; everything here stays on the paths that do not.
 */
class MCPServiceGatingTest extends TestCase
{
	/** @param array<int, string> $aDisabled */
	private function isHidden(object $oElement, array $aDisabled): bool
	{
		// No setAccessible(): it has been a no-op since PHP 8.1 and is
		// deprecated in 8.5, which this suite treats as a failure.
		$oMethod = new ReflectionMethod(MCPService::class, 'isHidden');

		return $oMethod->invoke(null, $oElement->getQualifiedName(), $oElement, $aDisabled);
	}

	public function testAnElementIsServedWhenNothingDisablesIt(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), []));
	}

	public function testDisablingByIdentifier(): void
	{
		$this->assertTrue($this->isHidden(new FixtureTool(), ['test_FixtureTool']));
	}

	public function testDisablingByClass(): void
	{
		$this->assertTrue($this->isHidden(new FixtureTool(), [FixtureTool::class]));
	}

	public function testAnUnrelatedEntryDisablesNothing(): void
	{
		$this->assertFalse($this->isHidden(new FixtureTool(), ['core_ObjectDelete', 'Acme\Tools\Whatever']));
	}

	public function testAnElementCanTakeItselfOut(): void
	{
		$this->assertTrue($this->isHidden(new UnavailableTool(), []));
	}
}
