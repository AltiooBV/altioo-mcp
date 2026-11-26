<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Core\Prompts\MyOpenTickets;
use Altioo\iTop\Extension\MCP\Core\Tools\MyOpenTickets as MyOpenTicketsTool;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, AC-3: `core_my_open_tickets` was advertised on UserRights alone and instructed a search that the operator's
 * exclusions refuse as an unknown class or attribute. A prompt a model is offered and then fails on is worse than an absent one,
 * and an excluded class is absent from every other route (ADR 0012).
 */
class PromptExclusionTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidAttCode('Ticket', 'agent_id') || !MetaModel::IsValidAttCode('Ticket', 'caller_id') || !MetaModel::IsValidAttCode('Ticket', 'operational_status')) {
			$this->markTestSkipped('Needs the ticket attributes the prompt asks about.');
		}
		$this->exclude([], []);
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		parent::tearDown();
	}

	/**
	 * @param array<int, string>                $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 */
	private function exclude(array $aClasses, array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	private function text(): string
	{
		return (new MyOpenTickets())->get()[0]['content']['text'];
	}

	public function testTheBaselineIsOfferedAndSearchesTicketsByBothContacts(): void
	{
		$this->assertTrue((new MyOpenTickets())->isAvailable());
		$this->assertStringContainsString('agent_id = :current_contact_id OR caller_id = :current_contact_id', $this->text());
		$this->assertStringContainsString("operational_status = 'ongoing'", $this->text());
	}

	public function testAnExcludedTicketClassMakesThePromptAbsent(): void
	{
		$this->exclude(['Ticket'], []);

		$this->assertFalse((new MyOpenTickets())->isAvailable());
	}

	public function testAnExcludedContactAttributeIsNotAskedFor(): void
	{
		$this->exclude([], ['Ticket' => ['agent_id']]);

		$this->assertTrue((new MyOpenTickets())->isAvailable());
		$this->assertStringNotContainsString('agent_id', $this->text());
		$this->assertStringContainsString('caller_id = :current_contact_id', $this->text());
	}

	public function testThePromptIsAbsentWhenNeitherContactAttributeIsServed(): void
	{
		$this->exclude([], ['Ticket' => ['agent_id', 'caller_id']]);

		$this->assertFalse((new MyOpenTickets())->isAvailable());
	}

	public function testAnExcludedStatusIsLeftOutOfTheQueryAndTheModelIsToldToReadTheLifecycle(): void
	{
		$this->exclude([], ['Ticket' => ['operational_status']]);

		$this->assertTrue((new MyOpenTickets())->isAvailable());
		$this->assertStringNotContainsString('operational_status =', $this->text());
		$this->assertStringContainsString('has no operational_status attribute', $this->text());
	}

	/** ADR 0025, Amendment 1: the tool twin answers what the prompt does, and is offered exactly where the prompt is. */
	public function testTheToolTwinAnswersThePromptsMessagesAndFollowsItsAvailability(): void
	{
		$aTool = \Altioo\iTop\Extension\MCP\Api\ToolOutput::Decode(MyOpenTicketsTool::execute());
		$this->assertSame((new MyOpenTickets())->get(), $aTool['messages']);
		$this->assertTrue((new MyOpenTicketsTool())->isAvailable());

		foreach ([[['Ticket'], []], [[], ['Ticket' => ['agent_id', 'caller_id']]]] as [$aClasses, $aAttributes]) {
			$this->exclude($aClasses, $aAttributes);
			$this->assertFalse((new MyOpenTickets())->isAvailable());
			$this->assertFalse((new MyOpenTicketsTool())->isAvailable(), 'the twin is served where the prompt is not, and would hand out its recipe');
		}

		$this->exclude([], ['Ticket' => ['agent_id']]);
		$this->assertStringNotContainsString('agent_id', MyOpenTicketsTool::execute()->content[0]->text, 'the twin names an attribute the operator excluded');
	}
}
