<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use RuntimeException;

/**
 * A provider that fails the way a real one does: something it registers turns
 * out not to honour the contract, or a class it needs is not installed.
 */
class BrokenServiceProvider implements iMCPServiceProvider
{
	public static function RegisterServiceProvider(): void
	{
		throw new RuntimeException('this provider is broken on purpose');
	}
}
