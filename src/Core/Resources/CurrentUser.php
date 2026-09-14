<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use MetaModel;
use UserRights;
use utils;

/**
 * @since 1.0.0
 */
class CurrentUser extends AbstractMCPResource
{

	protected function defaultTitle(): string
	{
		return 'Current User';
	}

	public function getDescription(): ?string
	{
		return 'Read the current user information.';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	/** Who is calling, rather than what they can reach. */
	public function getToolset(): string
	{
		return 'server';
	}

	protected function getResourcePath(): string
	{
		return 'current-user';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::User, Role::Assistant],
			1,
		);
	}

	public function read(): mixed
	{
		return ResourceOutput::Json([
			'current_contact_friendlyname' => self::ContactFriendlyname(),
			'current_contact_id' => UserRights::GetContactId(),
			'current_id' => UserRights::GetUserId(),
			'current_user_language' => UserRights::GetUserLanguage(),
			'archive_mode' => utils::IsArchiveMode() ? 'archive' : 'active',
		]);
	}

	/**
	 * The caller's own contact name, or null when it has none.
	 *
	 * Not UserRights::GetContactFriendlyname(): that resolves through
	 * User::GetContactObject(), which reads the contact under the caller's own
	 * silo. A contact outside it is then not merely hidden - the Person lookup
	 * answers null and the Contact fallback runs with $bMustBeFound at its
	 * default, so the accessor raises CoreException and takes the whole
	 * resource down with it.
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
