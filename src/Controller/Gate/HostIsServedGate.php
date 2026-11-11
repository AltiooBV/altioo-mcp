<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use ExecutionKPI;

/**
 * The host the request names is one this instance serves.
 *
 * Runs before ResetSession(), which is the point of it: a page on any website
 * could otherwise make a logged-in user's browser call this URL and end their
 * console session - no credential needed, and nothing in the audit trail that
 * looks like an attack. The third guard on that path is the credential check
 * (CredentialPresentedGate), which is what makes the reset conditional at all.
 *
 * @internal
 * @since 2.0.0
 */
final class HostIsServedGate implements iGate
{
	/**
	 * Refuses a request that arrived under a hostname this instance does not
	 * serve.
	 *
	 * The SDK applies the same rule, but it applies it inside
	 * StreamableHttpTransport - which this endpoint only reaches after
	 * resetting the session and authenticating. Deciding it here as well is
	 * what makes the check worth anything: the two read the same allow-list, so
	 * they cannot disagree, and this one runs before anything has a side
	 * effect.
	 *
	 * @throws MCPRequestRejectedException
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		$sOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
		$sHost   = $_SERVER['HTTP_HOST'] ?? null;

		if (MCPHttp::IsAllowedHost($sOrigin, $sHost, Settings::GetAllowedHosts())) {
			return true;
		}

		// The name that was refused goes to the log, not to the caller: an
		// operator whose endpoint answers 403 needs to see it, and a caller
		// probing for the configured hostnames does not.
		ErrorReport::Log(sprintf(
			"Refused an MCP request: neither its Origin (%s) nor its Host (%s) is in '%s'. "
			.'Set that module parameter to the hostname this instance is served under.',
			is_string($sOrigin) && $sOrigin !== '' ? $sOrigin : '-',
			is_string($sHost) && $sHost !== '' ? $sHost : '-',
			Settings::MODULE_SETTING_ALLOWED_HOSTS
		));

		throw new MCPRequestRejectedException(
			'This host is not served by the MCP endpoint.',
			MCPRequestRejectedException::HTTP_FORBIDDEN
		);

		return true;
	}
}
