<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use ExecutionKPI;

/**
 * One check a request passes before it is served.
 *
 * The controller runs its gates in one ordered list, and the order is the
 * security property: each gate is placed where it is because of a side effect
 * it must come before. A gate either lets the request continue, by returning
 * true, or answers it: by throwing the refusal the controller turns into the
 * response, or - for the preflight, which is no refusal - by writing the answer
 * itself and returning false.
 *
 * @internal
 * @since 2.0.0
 */
interface iGate
{
	/**
	 * @return bool True to go on to the next gate; false when this one has answered the request.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPAuthException
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException
	 */
	public function Check(ExecutionKPI $oKPI): bool;
}
