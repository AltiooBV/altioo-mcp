<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\CurrentUserReader;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

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

	/**
	 * @return string The same envelope core_current_user returns: see
	 *                {@see \Altioo\iTop\Extension\MCP\Helper\CurrentUserReader}
	 *                for why the identity is exposed on both surfaces.
	 */
	public function read(): mixed
	{
		return ResourceOutput::Json(CurrentUserReader::Payload());
	}
}
