<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Controller\MCPResponse;
use ExecutionKPI;

/**
 * A CORS preflight is answered here, and nothing after it runs.
 *
 * Before anything that needs a user: a preflight carries no credential by
 * definition, so answering it after DoLogin() would answer 401 and the browser
 * would never send the real request. The one gate that answers without
 * refusing.
 *
 * @internal
 * @since 2.0.0
 */
final class PreflightGate implements iGate
{
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		if (!self::isPreflight()) {
			return true;
		}

		MCPResponse::EmitPreflight();

		return false;
	}


	/**
	 * A CORS preflight, which is the one request that reaches this endpoint
	 * without a credential and must still be answered.
	 */
	private static function isPreflight(): bool
	{
		return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
			&& ($_SERVER['HTTP_ORIGIN'] ?? '') !== '';
	}
}
