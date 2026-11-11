<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use ExecutionKPI;
use LoginWebPage;

/**
 * The credential logs in.
 *
 * It resets the session and then logs in, in that order, so that a browser
 * cookie can never authenticate the call: the three gates before this one are
 * what make the reset safe to run, and the credential check is what makes it
 * conditional at all. A portal-only user refused the console is let in under
 * portal mode, or left with the refusal, by PortalAdmission; every other login
 * goes on exactly as before portal mode existed.
 *
 * @internal
 * @since 2.0.0
 */
final class LoginGate implements iGate
{
	/**
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPAuthException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		LoginWebPage::ResetSession();
		$iRet = LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_RETURN);
		$oKPI->ComputeAndReport('User login');

		// Only ever from the console refusal, so a console login reaches
		// none of it and goes on exactly as before portal mode existed.
		if ($iRet === LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED) {
			$iRet = PortalAdmission::Admit();
		}

		if ($iRet !== LoginWebPage::EXIT_CODE_OK) {
			throw AuthRefusal::CreateAuthException($iRet);
		}

		return true;
	}
}
