<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\CurrentUserReader;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Mcp\Schema\ToolAnnotations;

/**
 * Who the caller is, as a tool.
 *
 * Same answer as itop://core/current-user, over the same code - see
 * {@see \Altioo\iTop\Extension\MCP\Helper\CurrentUserReader} for why the
 * identity is exposed on both surfaces. Unlike the class list, the tool form
 * adds no narrowing: there is exactly one identity to report and nothing to
 * pass. What it adds is reachability, which for this particular fact is the
 * whole point - a model that cannot find out who it is either asks the user a
 * question the server would have answered, or quietly answers "my tickets"
 * with somebody else's.
 *
 * Served in portal mode too, where "who am I acting for" is as much the
 * question: the identity is the caller's own and asks the portal for nothing.
 *
 * @since 1.0.0
 */
class CurrentUser extends AbstractMCPTool implements PortalAware
{

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Who is calling, rather than what they can reach. */
	public function getToolset(): string
	{
		return 'server';
	}

	protected function defaultTitle(): string
	{
		return 'Current User';
	}

	public function getDescription(): ?string
	{
		if (PortalMode::Current() !== null) {
			return self::PORTAL_DESCRIPTION;
		}

		return 'Report who this session is authenticated as: the contact name and id, the user id, the language, and whether the instance is in archive mode. Read this before answering anything phrased as "me", "my tickets" or "assigned to me", rather than asking the user who they are. The contact id it returns is what ties the caller to objects: pass it as the value of a person or team attribute in core_object_search_by_class, or in a core_object_search_by_oql query. Every other tool here already runs as this user, so this also reports whose rights an "access denied" was about. It also reports the toolsets this session is served: if something you expected is not in your tool list, check that before concluding this server does not have it.';
	}

	/** What this tool is to a portal-mode connection, which has no OQL and no console search. */
	private const PORTAL_DESCRIPTION = 'Report who this session is authenticated as: the contact name and id, their organisation, the user id, their language, and the portal this connection is served through. Read this before answering anything phrased as "me" or "my requests", rather than asking the user who they are, and answer in their language. The portal\'s lists are already this user\'s own: core_portal_list needs no contact id. It also reports the toolsets and the tools this session is served.';

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'Current iTop user',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);
	}

	/**
	 * No arguments: the one identity this reports is the one the request
	 * authenticated as.
	 *
	 * 'properties' is left out rather than written as an empty array. The SDK
	 * normalises [] to an object only in Tool::fromArray(), which is the
	 * client-side path; the server builds through the constructor, so an empty
	 * array would reach the wire as "properties": [], which is not a valid
	 * JSON Schema object. Omitting the key says the same thing and stays valid.
	 */
	public function getInputSchema(): ?array
	{
		return [
			'type'     => 'object',
			'required' => [],
		];
	}

	/**
	 * @return array An array containing the caller's contact friendlyname and id, their user id, their language, and whether archive mode is on
	 */
	public static function execute(): mixed
	{
		// The envelope is CurrentUserReader's, so that itop://core/current-user
		// answers with the same shape for the same data.
		return ToolOutput::Json(CurrentUserReader::Payload());
	}
}
