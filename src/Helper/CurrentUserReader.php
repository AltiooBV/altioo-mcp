<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Service\MCPService;
use MetaModel;
use UserRights;
use utils;

/**
 * Who the request authenticated as.
 *
 * Served twice - as itop://core/current-user and as core_current_user - for
 * the reason {@see DatamodelReader} is: many clients never fetch resources at
 * all, so a model reaches for a tool and concludes the server cannot answer
 * when it finds none. The question this answers is one the model raises
 * mid-task rather than one a user attaches up front - "my tickets", "assigned
 * to me" - which is the side of the protocol's split that tools are on.
 *
 * Two surfaces, one implementation: what the resource reports and what the
 * tool reports cannot drift apart. That is also why the access block is read
 * here rather than in either surface - MCPService is the only place the
 * policy, the registry and the disabled list meet, and asking it once is
 * cheaper than keeping two copies of the answer level with each other.
 *
 * @api
 * @since 1.0.0
 */
final class CurrentUserReader
{
	/**
	 * The caller's identity, as both surfaces report it.
	 *
	 * Nothing here is read from the request: every value comes from the user
	 * record the authentication settled on, so there is no input to validate
	 * and no way to ask about anyone else.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Payload(): array
	{
		return [
			// What this caller can reach, so that "the server has no such tool"
			// and "I am not served it" stop looking the same. Only what is
			// served is named - see MCPService::ServedAccess().
			'access' => MCPService::ServedAccess(),
			'current_contact_friendlyname' => self::ContactFriendlyname(),
			'current_contact_id' => UserRights::GetContactId(),
			'current_id' => UserRights::GetUserId(),
			'current_user_language' => UserRights::GetUserLanguage(),
			'archive_mode' => utils::IsArchiveMode() ? 'archive' : 'active',
		];
	}

	/**
	 * The caller's own contact name, or null when it has none.
	 *
	 * Not UserRights::GetContactFriendlyname(): that resolves through
	 * User::GetContactObject(), which reads the contact under the caller's own
	 * silo. A contact outside it is then not merely hidden - the Person lookup
	 * answers null and the Contact fallback runs with $bMustBeFound at its
	 * default, so the accessor raises CoreException and takes the whole call
	 * down with it.
	 *
	 * Reading it under the silo is the wrong question to begin with. This is
	 * the caller's *own* contact, named by its own user record, and an identity
	 * is not something a caller has to hold a right on to be told - the same
	 * reason a user may change their own password without holding any right
	 * over anyone else's. iTop settles the principle itself one method away:
	 * FindUser() loads the user's own account with AllowAllData(), because an
	 * account outside its holder's silo still has to be able to log in.
	 *
	 * So the id comes from UserRights::GetContactId() - the caller's own user
	 * record, never anything the caller sent - and the object is fetched with
	 * $bAllowAllData true. That is what keeps this from being a contact reader:
	 * the one id it will ever look up is the caller's own. $bMustBeFound stays
	 * false so that a contactid left dangling by a deleted contact answers null
	 * rather than throwing.
	 *
	 * @since 1.0.0
	 */
	private static function ContactFriendlyname(): ?string
	{
		$iContactId = (int) UserRights::GetContactId();
		if ($iContactId === 0) {
			return null;
		}

		$oContact = MetaModel::GetObject('Contact', $iContactId, false, true);

		return $oContact === null ? null : $oContact->GetRawName();
	}
}
