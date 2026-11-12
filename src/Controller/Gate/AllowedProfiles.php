<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use UserRights;
use utils;

/**
 * The profiles this instance lets through to the MCP services, and whether the
 * signed-in user holds one.
 *
 * What the profile gate, the portal admission and the refusal that names the
 * list all ask.
 *
 * @internal
 * @since 2.0.0
 */
final class AllowedProfiles
{
	/**
	 * The profiles the datamodel ships in mcp_allowed_profiles, repeated here
	 * as the fallback for an instance whose configuration block is missing.
	 * Kept in step with datamodel.altioo-mcp.xml, which is what a normal
	 * install actually reads.
	 */
	private const DEFAULT_ALLOWED_PROFILES = ['Administrator', 'MCP Services User'];

	public static function UserHasMCPProfile(): bool
	{
		/** @var \User $oUser */
		$oUser = UserRights::GetUserObject();

		if (is_null($oUser)) {
			return false;
		}

		foreach (self::GetAuthorizedProfiles() as $sProfile) {
			if (UserRights::HasProfile($sProfile, $oUser)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The profiles this instance lets through, as configured.
	 *
	 * The fallback is the list the datamodel ships rather than an empty one,
	 * and the difference is the whole behaviour of an instance whose
	 * configuration block is not there. Falling back to nobody, combined with
	 * secure_mcp_services independently falling back to true, refuses every
	 * caller - an Administrator included - for a reason no message names. A
	 * missing block is a config file a hand edit went wrong in, not a decision
	 * anyone made.
	 *
	 * An operator who writes an empty list is still obeyed: an explicit
	 * array() is what GetModuleSetting() returns, and it means what it has
	 * always meant, which is nobody.
	 *
	 * @return array<int, string>
	 */
	public static function GetAuthorizedProfiles() : array
	{
		$aProfiles = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, 'mcp_allowed_profiles', self::DEFAULT_ALLOWED_PROFILES);
		if (is_array($aProfiles)) {
			return $aProfiles;
		}

		$sType = gettype($aProfiles);
		ErrorReport::Log("Itop configuration parameter 'mcp_allowed_profiles' should be an array instead of $sType");
		return [];
	}
}
