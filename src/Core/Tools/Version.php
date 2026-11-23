<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\ServerVersion;
use Mcp\Schema\ToolAnnotations;

/**
 * What is running, as a tool.
 *
 * Same answer as itop://core/version, over the same code - see
 * {@see \Altioo\iTop\Extension\MCP\Core\ServerVersion}. Every resource has a
 * tool twin (ADR 0025), because plenty of clients never fetch a resource and
 * every client reads tools/list. Here that also carries the AGPL 13 source
 * offer to a client that would otherwise never be shown it.
 *
 * Served where the resource is, and nowhere else: the `server` toolset, no
 * profile, and not in portal mode, whose surface is what the portal offers
 * (ADR 0006, ADR 0010).
 *
 * @internal
 * @since 2.2.0
 */
class Version extends AbstractMCPTool
{

	#[\Override]
	public function getNamespace(): string
	{
		return 'core';
	}

	/** What is running, rather than what is in it. */
	#[\Override]
	public function getToolset(): string
	{
		return 'server';
	}

	#[\Override]
	protected function defaultTitle(): string
	{
		return 'iTop Version';
	}

	#[\Override]
	public function getDescription(): ?string
	{
		return 'Report what is running: the iTop version, build and edition, and the name, version, licence and source URL of the MCP extension serving this session. Read it when an answer depends on the iTop version, or when asked what this server is or where its source is. No arguments; reads nothing from the CMDB.';
	}

	#[\Override]
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'iTop version',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);
	}

	/**
	 * No arguments: there is one instance to report.
	 *
	 * 'properties' is left out rather than written as an empty array, for the
	 * reason spelled out in {@see CurrentUser::getInputSchema()}.
	 */
	#[\Override]
	public function getInputSchema(): ?array
	{
		return [
			'type'     => 'object',
			'required' => [],
		];
	}

	/**
	 * @return mixed iTop's version, build, build date and edition, and the extension's name, version, licence and source URL
	 */
	public static function execute(): mixed
	{
		// The payload is ServerVersion's, so that itop://core/version answers
		// with the same shape for the same data.
		return ToolOutput::Json(ServerVersion::Payload());
	}
}
