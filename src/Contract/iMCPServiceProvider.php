<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Contract;

/**
 * @api
 * @since 1.0.0
 */
interface iMCPServiceProvider
{
	/**
	 * Called once at MCP server boot to register tools, resources, and prompts.
	 *
	 * @since 1.0.0
	 */
	public static function RegisterServiceProvider(): void;
}
