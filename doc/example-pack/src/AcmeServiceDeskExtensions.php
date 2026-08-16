<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk;

use Acme\iTop\Extension\ServiceDesk\Prompts\TriageMyQueue;
use Acme\iTop\Extension\ServiceDesk\Tools\TicketAddLogEntry;
use Acme\iTop\Extension\ServiceDesk\Tools\TicketSearchMine;
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;

/**
 * Everything this pack contributes, registered once per MCP request.
 *
 * A provider that throws is logged and skipped, and only this provider: the
 * endpoint stays up for the base tools and for every other pack. That makes
 * throwing the right thing to do when a precondition is not met, and it is
 * why the version guard below is a throw rather than a silent return - the
 * log entry is the only thing that will tell an administrator why the pack
 * they installed is not there.
 */
class AcmeServiceDeskExtensions implements iMCPServiceProvider
{
	public static function RegisterServiceProvider(): void
	{
		// The module dependency in module.acme-servicedesk.php is the real
		// gate and the setup enforces it. This catches the case that gets past
		// it: a base extension downgraded under a pack already installed.
		MCPHelper::RequireVersion('1.0.0', 'acme-servicedesk');

		MCPRegistry::RegisterTool(new TicketAddLogEntry());
		MCPRegistry::RegisterTool(new TicketSearchMine());
		MCPRegistry::RegisterPrompt(new TriageMyQueue());

		// Session-level guidance, paid for by every session whether or not any
		// of these tools is called - so it earns its place only if it says
		// something no single tool description could. A convention that spans
		// the pack qualifies; describing a tool does not.
		MCPRegistry::AddInstructions(
			'Acme service desk: tickets are triaged by team, not by agent. '
			.'Use acme_ticket_search_mine before asking the user which ticket they mean.'
		);
	}
}
