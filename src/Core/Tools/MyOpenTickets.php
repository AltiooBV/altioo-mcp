<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Prompts\MyOpenTickets as MyOpenTicketsPrompt;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Mcp\Schema\ToolAnnotations;

/**
 * The prompt core_my_open_tickets, as a tool.
 *
 * A prompt is something a person starts and the model cannot: Claude Code lists it as a slash command only the user
 * types, and a model that searches its tools never finds it (review 2026-11-23, L1). So the recipe has a tool twin, as
 * a resource has (ADR 0025, amended): the same messages, from the same handler
 * ({@see MyOpenTicketsPrompt::Messages()}), served wherever the prompt is. It runs no search itself: what it
 * answers is the call to make, which the model then makes.
 *
 * @internal
 * @since 2.2.0
 */
class MyOpenTickets extends AbstractMCPTool implements PortalAware
{
	#[\Override]
	public function getNamespace(): string
	{
		return 'core';
	}

	/** The prompt's toolset, so a token narrowed to one serves both or neither. */
	#[\Override]
	public function getToolset(): string
	{
		return 'objects';
	}

	#[\Override]
	protected function defaultTitle(): string
	{
		return 'My Open Tickets';
	}

	#[\Override]
	public function getDescription(): ?string
	{
		return 'Give the recipe for listing '
			.(PortalMode::Current() !== null ? MyOpenTicketsPrompt::PORTAL_RETURNS : MyOpenTicketsPrompt::RETURNS)
			.': the call to make, worded for this instance. The prompt of this name, for a client that offers no prompts. Runs nothing. No arguments.';
	}

	#[\Override]
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'My open tickets',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);
	}

	/** Offered where, and only where, the prompt is (ADR 0025). */
	#[\Override]
	public function isAvailable(): bool
	{
		return MyOpenTicketsPrompt::Offered();
	}

	/**
	 * No arguments; 'properties' left out for the reason spelled out in {@see CurrentUser::getInputSchema()}.
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
	 * @return mixed The prompt's messages, as prompts/get answers them
	 */
	public static function execute(): mixed
	{
		return ToolOutput::Json(['messages' => MyOpenTicketsPrompt::Messages()]);
	}
}
