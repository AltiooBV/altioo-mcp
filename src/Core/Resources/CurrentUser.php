<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
use CoreException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
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
	 * The caller's contact name, or null when it cannot be resolved.
	 *
	 * UserRights::GetContactFriendlyname() resolves through User::GetContactObject(),
	 * which tries Person with $bMustBeFound false and then falls back to
	 * MetaModel::GetObject('Contact', ...) with that flag left at its default. A
	 * contact the caller may not read - deleted, archived, or outside its silo -
	 * therefore raises CoreException instead of answering null, and an API identity
	 * whose own contact sits outside its silo is exactly the caller most likely to
	 * read this resource. Losing the name is worth an answer; losing the identity
	 * the rest of this payload carries is not.
	 *
	 * @since 1.0.0
	 */
	private static function ContactFriendlyname(): ?string
	{
		try {
			return UserRights::GetContactFriendlyname();
		} catch (CoreException $e) {
			MCPHelper::LogError(sprintf(
				'itop://core/current-user: contact of user %s could not be resolved: %s',
				(string) UserRights::GetUserId(),
				$e->getMessage()
			));

			return null;
		}
	}
}
