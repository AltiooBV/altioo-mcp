<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Prompts;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;

class MyOpenTickets extends AbstractMCPPrompt
{
	public function getTitle(): ?string
	{
		return 'My Open Tickets';
	}

	public function getDescription(): ?string
	{
		return 'List all open tickets assigned to the current user or submitted by them.';
	}

	public function get(): array
	{
		return [
			[
				'role'    => 'user',
				'content' => [
					'type' => 'text',
					'text' => 'Use the ObjectSearchByOQL tool with OQL "SELECT UserRequest WHERE status NOT IN (\'closed\', \'resolved\')  AND (agent_id = :current_contact_id OR caller_id = :current_contact_id)" to retrieve my open tickets (assigned to me or submitted by me). Present the results as a clear list showing for each ticket: ID, title, requester, priority, and how long it has been open. If no tickets are found, say so clearly. Respond in the same language as the ticket titles.',
				],
			],
		];
	}
}
