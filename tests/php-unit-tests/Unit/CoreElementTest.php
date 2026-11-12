<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Helper\CoreElement;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/Support/SpoofedCorePortalTool.php';

/**
 * "This module's own element" is decided by the file a class is defined in,
 * never by its PHP namespace, which any tool pack can declare.
 */
class CoreElementTest extends TestCase
{
	public function testAClassUnderSrcCoreIsOwn(): void
	{
		$this->assertTrue(CoreElement::IsOwn(new ObjectGet()));
		$this->assertTrue(CoreElement::IsOwn(ObjectGet::class));
		$this->assertTrue(CoreElement::IsOwn('\\'.ObjectGet::class));
	}

	public function testAClassInTheCoreNamespaceDefinedElsewhereIsNot(): void
	{
		$sSpoof = \Altioo\iTop\Extension\MCP\Core\Spoof\SpoofedCorePortalTool::class;

		$this->assertFalse(CoreElement::IsOwn(new $sSpoof()));
		$this->assertFalse(CoreElement::IsOwn($sSpoof));
	}

	public function testAPackClassIsNot(): void
	{
		$this->assertFalse(CoreElement::IsOwn(new FixtureTool()));
		$this->assertFalse(CoreElement::IsOwn('Acme\\Pack\\Tool'));
	}

	public function testAClassWithNoFileIsNot(): void
	{
		$this->assertFalse(CoreElement::IsOwn(new \stdClass()));
		$this->assertFalse(CoreElement::IsOwn('Altioo\\iTop\\Extension\\MCP\\Core\\NoSuchClass'));
	}
}
