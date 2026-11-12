<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Helper\Settings;
use ExecutionKPI;
use LoginWebPage;

/**
 * The user holds a profile this instance lets through to the MCP services.
 *
 * Asked of a user who logged in, after any portal admission, and only when
 * access is restricted at all.
 *
 * @internal
 * @since 2.0.0
 */
final class McpProfileGate implements iGate
{
	/**
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		if (Settings::IsAccessRestricted() && !AllowedProfiles::UserHasMCPProfile()) {
			throw AuthRefusal::CreateAuthException(LoginWebPage::EXIT_CODE_NOTAUTHORIZED);
		}

		return true;
	}
}
