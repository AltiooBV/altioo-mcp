<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Test\Unit;

use Acme\iTop\Extension\ServiceDesk\Prompts\TriageMyQueue;
use Acme\iTop\Extension\ServiceDesk\Tools\TicketAddLogEntry;
use Acme\iTop\Extension\ServiceDesk\Tools\TicketSearchMine;
use Altioo\iTop\Extension\MCP\Api\Testing\ElementContract;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use PHPUnit\Framework\TestCase;

/**
 * The whole of a pack's contract suite.
 *
 * ElementContract needs neither iTop nor a database - it reads what the
 * elements declare about themselves - so this runs anywhere PHP and Composer
 * do, which is what makes it worth having in CI. It catches, before an
 * installation does:
 *
 *   - an input schema property with no matching execute() parameter, and the
 *     reverse, both of which otherwise surface as a tool that lists fine and
 *     fails on every call;
 *   - a missing or empty description;
 *   - the reserved 'core' namespace, or a name the MCP schema will not accept;
 *   - a tool with no annotations, which registers, works for an administrator,
 *     and is invisible to every scoped token.
 */
class ContractTest extends TestCase
{
	/**
	 * @dataProvider elementProvider
	 */
	public function testTheElementHonoursTheContract(object $oElement): void
	{
		$this->assertSame(
			[],
			ElementContract::Violations($oElement),
			get_class($oElement).' does not honour the MCP element contract.'
		);
	}

	public function testTheWholeProviderHonoursItAtOnce(): void
	{
		$this->assertSame([], ElementContract::ViolationsOfAll(array_map(
			static fn (array $a): object => $a[0],
			self::elementProvider()
		)));
	}

	/**
	 * The one element that declares PortalAware is a search, and
	 * ElementContract::Violations() also checks its source asks
	 * ObjectAccess::Current() (hence the first test above passing for it).
	 * Declaring it is not enough for portal mode to serve it: the operator
	 * lists it in mcp_portal_extensions. The write tool and the prompt stay
	 * console-only, which is what leaving the interface off means.
	 */
	public function testOnlyTheSearchDeclaresPortalAwareness(): void
	{
		$this->assertInstanceOf(PortalAware::class, new TicketSearchMine());
		$this->assertNotInstanceOf(PortalAware::class, new TicketAddLogEntry());
		$this->assertNotInstanceOf(PortalAware::class, new TriageMyQueue());
		$this->assertSame([], ElementContract::Violations(new TicketSearchMine()));
	}

	/**
	 * Every element AcmeServiceDeskExtensions registers.
	 *
	 * Kept in step with the provider by hand, which is the one thing to
	 * remember when adding a tool.
	 *
	 * @return array<string, array{0: object}>
	 */
	public static function elementProvider(): array
	{
		return [
			'ticket_add_log_entry' => [new TicketAddLogEntry()],
			'ticket_search_mine'   => [new TicketSearchMine()],
			'triage_my_queue'      => [new TriageMyQueue()],
		];
	}
}
