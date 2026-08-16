<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use RestUtils;
use UserRights;

/**
 * Append an entry to the public log of a ticket.
 *
 * The shape of a task-specific tool, which is the thing the base extension
 * deliberately does not ship: core_object_update can already write this
 * attribute, but only if the model first works out that the class is
 * UserRequest, that the attribute is public_log, and that a caselog append is
 * spelled as a plain string. A tool that does one job states all three.
 */
class TicketAddLogEntry extends AbstractMCPTool
{
	/** The attribute this tool appends to. */
	private const LOG_ATTRIBUTE = 'public_log';

	public function getNamespace(): string
	{
		// Yours. 'core' belongs to the base extension and is refused here.
		return 'acme';
	}

	/**
	 * Named so an operator can serve this pack's tools and not another's,
	 * and so that MCP-toolset-acme-servicedesk means something. Declaring the
	 * name here is half of it; the other half is the scope value in
	 * datamodel.acme-servicedesk.xml.
	 */
	public function getToolset(): string
	{
		return 'acme-servicedesk';
	}

	protected function defaultTitle(): string
	{
		return 'Add a Log Entry to a Ticket';
	}

	public function getDescription(): ?string
	{
		// Written for the model, and so deliberately not translated. It says
		// what the tool does, what it does not do, and when to reach for it.
		return 'Append an entry to the public log of a ticket, which is the part of the ticket the caller can read. '
			.'Use this rather than a generic update when the user wants to record a note, a diagnosis or a reply on a ticket. '
			.'Runs as a dry run by default: call it with simulate=true first, show the result to the user, then call again with simulate=false to write.';
	}

	/**
	 * Not optional in practice.
	 *
	 * A tool that returns null here is graded "delete" - the harshest grade -
	 * and is withheld from every token scoped MCP-read or MCP-write. The
	 * symptom is a tool that works for an administrator and is invisible to
	 * everyone else.
	 */
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Add a log entry to a ticket',
			false, // readOnlyHint  — it writes
			false, // destructiveHint — it appends, and destroys nothing
			false, // idempotentHint — calling twice appends twice
			false, // openWorldHint
		);
	}

	/**
	 * Hidden rather than broken on an instance with no ticketing module.
	 *
	 * The module dependency in the module declaration is the real gate. This
	 * is what keeps the pack honest if someone installs it anyway, or if a
	 * datamodel customisation removed the attribute.
	 */
	public function isAvailable(): bool
	{
		return MetaModel::IsValidClass('UserRequest')
			&& MetaModel::IsValidAttCode('UserRequest', self::LOG_ATTRIBUTE);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Id of the ticket. Find it with acme_ticket_search_mine or core_object_find_by_name.',
				],
				'message'  => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'The text to append. It is written as the authenticated user, and the caller of the ticket can read it.',
				],
				'simulate' => WritePlan::SimulateSchemaProperty('append the entry for real'),
			],
			// Every parameter of execute() without a default must be listed
			// here, and every property must match a parameter by name. The
			// registry refuses the tool at boot if they disagree.
			'required'   => ['id', 'message'],
		];
	}

	public function getOutputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'id'        => ['type' => 'integer'],
				'simulated' => ['type' => 'boolean'],
				'appended'  => ['type' => 'boolean'],
			],
			'required'   => ['id', 'simulated', 'appended'],
		];
	}

	/**
	 * @param int    $id       Id of the ticket
	 * @param string $message  Text to append to the public log
	 * @param bool   $simulate When true (the default), nothing is written
	 *
	 * @return mixed What was appended, or would be
	 *
	 * @throws ToolCallException When the ticket cannot be read, cannot be written, or does not exist.
	 */
	public static function execute(
		int    $id,
		string $message,
		bool   $simulate = WritePlan::SIMULATE_BY_DEFAULT,
	): mixed {
		if (trim($message) === '') {
			throw new ToolCallException('The message is empty.');
		}

		// Nothing here replaces UserRights. The framework authenticated the
		// caller and checked the endpoint gates; what this particular user may
		// do to this particular object is still this tool's job to ask.
		if (!UserRights::IsActionAllowed('UserRequest', UR_ACTION_MODIFY)) {
			throw new ToolCallException('Access denied: cannot modify tickets.');
		}
		if (!UserRights::IsActionAllowedOnAttribute('UserRequest', self::LOG_ATTRIBUTE, UR_ACTION_MODIFY)) {
			throw new ToolCallException('Access denied: cannot write the public log of a ticket.');
		}

		// Object-level rights are the point: "may modify a ticket" and "may
		// modify THIS ticket" are different questions, and only a set of
		// exactly one object asks the second.
		$oSet = new DBObjectSet(ObjectQuery::ById('UserRequest', $id));
		if ($oSet->Count() === 0) {
			// Not found and not yours are answered alike, so that the tool
			// cannot be used to discover which ticket ids exist.
			throw new ToolCallException("Ticket {$id} not found.");
		}
		if (!UserRights::IsActionAllowed('UserRequest', UR_ACTION_MODIFY, $oSet)) {
			throw new ToolCallException("Access denied: cannot modify ticket {$id}.");
		}

		$oTicket = $oSet->Fetch();
		if ($oTicket->IsReadOnly()) {
			throw new ToolCallException("Ticket {$id} is read-only.");
		}

		// RestValue is what makes a caselog append work. The MCP SDK decodes
		// inbound JSON with json_decode($input, true), so nested objects reach
		// a tool as PHP arrays, while RestUtils branches on stdClass to tell a
		// search from an id and an append from a plain string. Without this
		// call the wrong branch is taken, silently.
		$oTicket->Set(
			self::LOG_ATTRIBUTE,
			RestUtils::MakeValue('UserRequest', self::LOG_ATTRIBUTE, RestValue::FromDecodedJson($message))
		);

		// Runs iTop's own consistency checks and throws with the reasons, so
		// that a dry run reports what a real call would refuse.
		WritePlan::Check($oTicket, "Ticket {$id}");

		if (!$simulate) {
			$oTicket->DBUpdate();
		}

		return ToolOutput::Structured([
			'id'        => $id,
			'simulated' => $simulate,
			'appended'  => !$simulate,
		]);
	}
}
