<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantFamilies;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Altioo\iTop\Extension\MCP\Service\Write\MentionPolicy;

/**
 * Where a request's state is opened and closed.
 *
 * The state stays static, as iTop's own helpers keep theirs (PHP serves one
 * request per process), but it is no longer set and forgotten one slot at a
 * time by whoever happens to touch it. A request is opened here, as the
 * controller learns what it is, and closed here, which forgets every slot in
 * one call. A slot added to a class and left out of Close() is caught by
 * RequestStateTest, which reads every static property in src/.
 *
 * The slots are not all set at the same moment, and this class does not move
 * them: portal mode is entered while the login is still being judged
 * ({@see EnterPortal()}), the policy and the credential's identity once the
 * gates have passed ({@see Open()}), what a caller is served and the change's
 * tool name when the server is built (MCPService::run()). It owns the coherent
 * setting of the portal pair, and it owns the forgetting.
 *
 * It sits in Service\Server because closing a request has to reach every layer
 * below it.
 *
 * @internal
 * @since 2.0.0
 */
final class RequestScope
{
	/**
	 * Puts the request in portal mode: the portal and the access that answers
	 * for it are set together, so that neither can be set without the other.
	 *
	 * Called by the controller's login once the admission checks pass (doc/adr/0007).
	 * The access is built on each call to ObjectAccess::Current(), not here, so a
	 * portal that cannot be read refuses the tool that needs it and not the
	 * connection. A request that never calls this is a console request.
	 */
	public static function EnterPortal(string $sPortal): void
	{
		PortalMode::Remember($sPortal);
		ObjectAccess::Remember(static fn (): ObjectAccess => PortalAccess::ForCurrentRequest());
	}

	/**
	 * Opens the request once the gates have passed: remembers the access policy
	 * the credential decided, and which credential ran the request.
	 *
	 * Both are asked while the raw token is still at hand, so the controller
	 * calls this before it drops the token (MCPHttp::ForgetAuthToken()). The
	 * policy is what a write tool asks to learn whether it is rehearsing; the
	 * identity, a class name and a row id, is what the audit row names.
	 *
	 * @param string|null $sPortal When given, the request is put in portal mode here too
	 *                             ({@see EnterPortal()}); null leaves the mode as it is,
	 *                             which is as the login set it.
	 */
	public static function Open(AccessPolicy $oPolicy, ?string $sPortal = null): void
	{
		if ($sPortal !== null) {
			self::EnterPortal($sPortal);
		}

		AccessPolicy::Remember($oPolicy);
		TokenScopes::RememberIdentity();
	}

	/**
	 * Forgets every per-request slot, and the caches derived from the settings
	 * and the datamodel with them.
	 *
	 * The controller calls it when the request is done, after the audit row is
	 * written, whichever way the request ended; a test calls it in its teardown;
	 * so does a task that runs in the background. It is safe to call twice, and
	 * on a request that opened nothing.
	 */
	public static function Close(): void
	{
		// The raw credential first: nothing else here is as dangerous to keep.
		MCPHttp::ForgetAuthToken();
		TokenScopes::ForgetIdentity();
		AccessPolicy::Forget();
		ServedAccess::Forget();

		// Portal mode: all of it, so that no half is left to disagree with the other.
		ObjectAccess::Forget();
		PortalPolicy::Forget();
		PortalMode::Forget();

		ChangeTracking::Forget();
		ReadSummary::Forget();
		ErrorReport::ForgetReferences();
		MentionPolicy::ForgetCount();

		ExclusionPolicy::Forget();
		AccessGrantFamilies::Forget();
	}
}
