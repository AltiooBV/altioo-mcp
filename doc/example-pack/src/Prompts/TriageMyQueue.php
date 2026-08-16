<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Prompts;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use MetaModel;
use UserRights;

/**
 * A worked instruction for the thing this pack exists to make easy.
 *
 * A prompt is offered in a list the model picks from, so an unusable one is
 * worse than an absent one - it gets chosen and then fails. isAvailable() is
 * what keeps that from happening on an instance with no ticketing module, or
 * for a user who cannot read a ticket.
 */
class TriageMyQueue extends AbstractMCPPrompt
{
	public function getNamespace(): string
	{
		return 'acme';
	}

	public function getToolset(): string
	{
		return 'acme-servicedesk';
	}

	protected function defaultTitle(): string
	{
		return 'Triage My Queue';
	}

	public function getDescription(): ?string
	{
		return 'Walk through the tickets assigned to the current user and propose a triage order.';
	}

	public function isAvailable(): bool
	{
		if (!class_exists(MetaModel::class)) {
			// No iTop loaded: nothing can be verified, so nothing is claimed.
			return false;
		}

		return MetaModel::IsValidClass('UserRequest')
			&& UserRights::IsActionAllowed('UserRequest', UR_ACTION_READ);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get(): array
	{
		return [
			[
				'role'    => 'user',
				'content' => [
					'type' => 'text',
					'text' => 'Call acme_ticket_search_mine to list my open tickets. '
						.'Group them by urgency, and for each group say in one line what the tickets have in common. '
						.'Then propose an order to work through them, and ask me to confirm before you record anything. '
						.'Do not call acme_ticket_add_log_entry with simulate=false until I have confirmed.',
				],
			],
		];
	}
}
