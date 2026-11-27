<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Rights\WriteCap;
use Altioo\iTop\Extension\MCP\Service\Write\DryRun;
use Altioo\iTop\Extension\MCP\Service\Write\WriteValues;
use DBObjectSet;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
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
				'simulate' => DryRun::SimulateSchemaProperty('append the entry for real'),
				'comment'  => [
					'type'        => ['string', 'null'],
					'description' => 'Why the entry is being added, recorded on the audit row of the call.',
				],
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
			// forced_by_credential and forced_reason: said when an advisory credential turned a real write into a rehearsal.
			] + DryRun::ForcedSchemaProperties(),
			'required'   => ['id', 'simulated', 'appended'],
		];
	}

	/**
	 * @param int    $id       Id of the ticket
	 * @param string $message  Text to append to the public log
	 * @param bool        $simulate When true (the default), nothing is written
	 * @param string|null $comment  Why, recorded on the audit row
	 *
	 * @return mixed What was appended, or would be
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When the ticket cannot be read, cannot be written, or does not exist.
	 */
	public static function execute(
		int    $id,
		string $message,
		bool   $simulate = DryRun::SIMULATE_BY_DEFAULT,
		?string $comment = null,
	): mixed
	{
		// First, before anything reads $simulate. A credential scoped MCP-advisory may only rehearse: the server
		// does not serve it a pack's write tool at all (ADR 0022), and this call answers true for such a credential
		// whatever the caller sent - the core tools' guarantee, kept here so that the audit row's simulate and
		// forced_by_credential say what ran. Use its answer, never the argument, from here on.
		$simulate = DryRun::Simulated($simulate);

		if (trim($message) === '') {
			throw ToolRefusal::Because('The message is empty.');
		}

		// What the operator excluded is not this tool's to touch, whatever
		// UserRights says (ADR 0012). Refused in the words for a class or an
		// attribute that does not exist, so the refusal cannot be used to find
		// out what was excluded. A tool that writes any class a caller names
		// would also ask AccessGrants::RefusalFor() and
		// ChangeLogReservation::IsReserved(); neither applies to UserRequest.
		if (!ExclusionPolicy::Serves('UserRequest')) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable('UserRequest'));
		}

		// Nothing here replaces UserRights. The framework authenticated the
		// caller and checked the endpoint gates; what this particular user may
		// do to this particular object is still this tool's job to ask.
		if (!UserRights::IsActionAllowed('UserRequest', UR_ACTION_MODIFY)) {
			throw ToolRefusal::Because('Access denied: cannot modify tickets.');
		}

		// Object-level rights are the point: "may modify a ticket" and "may
		// modify THIS ticket" are different questions, and only a set of
		// exactly one object asks the second.
		$oSet = new DBObjectSet(ObjectQuery::ById('UserRequest', $id));
		if ($oSet->Count() === 0) {
			// Not found and not yours are answered alike, so that the tool
			// cannot be used to discover which ticket ids exist.
			throw ToolRefusal::Because("Ticket {$id} not found.");
		}
		if (!UserRights::IsActionAllowed('UserRequest', UR_ACTION_MODIFY, $oSet)) {
			throw ToolRefusal::Because("Access denied: cannot modify ticket {$id}.");
		}

		$oTicket = $oSet->Fetch();
		if ($oTicket->IsReadOnly()) {
			throw ToolRefusal::Because("Ticket {$id} is read-only.");
		}

		// One validator for every write this module makes: WriteValues::Validated() is what the core tools
		// ask, and it holds, in one place, what a hand-written copy forgets. The operator's excluded attributes
		// (an excluded public_log is answered as one that does not exist, ADR 0012), the right to modify the
		// attribute on this object, what the console's form would let this user change in this state (a closed
		// ticket's log is read-only), the mention cap, the spreadsheet-formula rule, and RestValue - which is what
		// makes a caselog append work: the MCP SDK decodes inbound JSON with json_decode($input, true), so a nested
		// object reaches a tool as a PHP array, while RestUtils branches on stdClass to tell a search from an id
		// and an append from a plain string.
		[$aValues, $aIssues] = WriteValues::Validated($oTicket, 'UserRequest', [self::LOG_ATTRIBUTE => $message], $oSet);
		if ($aIssues !== []) {
			throw ToolRefusal::Because('Failed to add the entry: '.implode(' ', $aIssues));
		}
		foreach ($aValues as $sAttCode => $mValue) {
			$oTicket->Set($sAttCode, $mValue);
		}

		// Runs iTop's own consistency checks and throws with the reasons, so
		// that a dry run reports what a real call would refuse.
		DryRun::Check($oTicket, "Ticket {$id}");

		if (!$simulate) {
			// The operator's cap on committed writes per credential (ADR 0019): asked once the call is known to
			// commit and before it writes, with the objects it will write - here, the ticket - and never for a dry run.
			$sCapRefusal = WriteCap::RefusalFor(1);
			if ($sCapRefusal !== null) {
				throw ToolRefusal::Because($sCapRefusal);
			}
			// Said before the write, because the change record is built by the write itself: the tool is named on
			// it and the caller's reason goes to the audit row.
			ChangeTracking::Explain($comment);

			$oTicket->DBUpdate();
		}

		// StructuredWrite() adds forced_by_credential and forced_reason to the answer, so a caller whose credential
		// may only propose is told that nothing was written, and why.
		return ToolOutput::StructuredWrite([
			'id'        => $id,
			'simulated' => $simulate,
			'appended'  => !$simulate,
		]);
	}
}
