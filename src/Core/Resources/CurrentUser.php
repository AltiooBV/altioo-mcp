<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use UserRights;
use utils;

class CurrentUser extends AbstractMCPResource
{

	public function getTitle(): ?string
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
		$oUser = UserRights::GetUserObject();

		return json_encode([
			'current_contact_friendlyname' => UserRights::GetContactFriendlyname(),
			'current_contact_id' => UserRights::GetContactId(),
			'current_id' => UserRights::GetUserId(),
			'current_user_language' => UserRights::GetUserLanguage(),
			'archive_mode' => utils::IsArchiveMode() ? 'archive' : 'active',
		]);
	}
}
