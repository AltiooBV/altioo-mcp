<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Api\ResourceOutput;
use Altioo\iTop\Extension\MCP\Core\ServerVersion;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

/**
 * @internal
 * @since 1.0.0
 */
class Version extends AbstractMCPResource
{

	protected function defaultTitle(): string
	{
		return 'iTop Version';
	}

	public function getDescription(): ?string
	{
		return 'Read the iTop version information, and the identity, licence and source of the MCP extension serving this session. The core_version tool returns the same thing for clients that do not read resources.';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	/** What is running, rather than what is in it. */
	public function getToolset(): string
	{
		return 'server';
	}

	protected function getResourcePath(): string
	{
		return 'version';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::Assistant],
			1,
		);
	}

	/**
	 * @return string The same payload core_version returns: see
	 *                {@see \Altioo\iTop\Extension\MCP\Core\ServerVersion}, which
	 *                also says why its `extension` block is the AGPL 13 source offer.
	 */
	public function read(): mixed
	{
		return ResourceOutput::Json(ServerVersion::Payload());
	}
}
