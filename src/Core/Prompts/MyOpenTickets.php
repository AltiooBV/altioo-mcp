<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Prompts;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use MetaModel;
use UserRights;

/**
 * The current user's open tickets, asked for in whatever terms this instance
 * actually uses.
 *
 * Written out once, this prompt named UserRequest and two status codes, and was
 * wrong on three counts at once. It was wrong on an instance with no ticketing
 * module, where the class does not exist. It was wrong for a CMDB manager, who
 * is offered a prompt about tickets they may not read. And it was wrong on any
 * instance whose XML delta renamed or removed the attributes it named, which is
 * the ordinary state of a customised iTop rather than an edge case.
 *
 * So nothing here is written down that the datamodel can answer instead. The
 * class is Ticket - abstract, and the parent of whatever ticket classes this
 * instance declares, rather than one of them - and every attribute in the query
 * is checked before it is used. What cannot be checked is not asked for, and if
 * too little survives the checks the prompt is not offered at all, which is
 * more useful than a prompt that produces an OQL error.
 *
 * @since 1.0.0
 */
class MyOpenTickets extends AbstractMCPPrompt
{
	/** The class iTop's ticketing modules derive every ticket from. */
	private const TICKET_CLASS = 'Ticket';

	/**
	 * The status attribute a ticket has whatever its lifecycle.
	 *
	 * An AttributeMetaEnum that itop-tickets maps onto each subclass's own
	 * status values, which is what makes "open" answerable without knowing
	 * whether this instance calls the state 'assigned', 'pending' or something
	 * a delta invented.
	 */
	private const OPERATIONAL_STATUS = 'operational_status';

	/** The value of that attribute meaning the ticket is not finished with. */
	private const STATUS_ONGOING = 'ongoing';

	/** The two ways a ticket belongs to someone, either of which will do. */
	private const CONTACT_ATTRIBUTES = ['agent_id', 'caller_id'];

	public function getNamespace(): string
	{
		return 'core';
	}

	protected function defaultTitle(): string
	{
		return 'My Open Tickets';
	}

	public function getDescription(): ?string
	{
		return 'List the open tickets assigned to the current user or submitted by them.';
	}

	/**
	 * Offered only where it would work, and only to someone it would work for.
	 *
	 * Three conditions, and each one has an instance behind it: no ticketing
	 * module means no Ticket class; a CMDB-only profile means a user who cannot
	 * read one; and a datamodel that has renamed both contact attributes means
	 * there is no way left to ask whose ticket it is. A prompt is advertised in
	 * a list the model chooses from, so an unusable one is worse than an absent
	 * one - it gets picked.
	 */
	public function isAvailable(): bool
	{
		if (!class_exists(MetaModel::class)) {
			// No iTop loaded, so nothing can be verified, so nothing is claimed.
			return false;
		}

		if (!MetaModel::IsValidClass(self::TICKET_CLASS)) {
			return false;
		}

		if (!UserRights::IsActionAllowed(self::TICKET_CLASS, UR_ACTION_READ)) {
			return false;
		}

		return !empty(self::availableContactAttributes());
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
					'text' => $this->instruction(),
				],
			],
		];
	}

	/**
	 * The contact attributes this instance still declares, of the two asked for.
	 *
	 * Read rights count as much as existence: an attribute the caller may not
	 * read is one the query may not filter on, since filtering on it reports
	 * its values just as surely as selecting it would.
	 *
	 * @return array<int, string>
	 */
	private static function availableContactAttributes(): array
	{
		$aFound = [];

		foreach (self::CONTACT_ATTRIBUTES as $sAttCode) {
			if (!MetaModel::IsValidAttCode(self::TICKET_CLASS, $sAttCode)) {
				continue;
			}
			if (!UserRights::IsActionAllowedOnAttribute(self::TICKET_CLASS, $sAttCode, UR_ACTION_READ)) {
				continue;
			}

			$aFound[] = $sAttCode;
		}

		return $aFound;
	}

	/** Whether "open" can be expressed in OQL on this instance. */
	private static function hasOperationalStatus(): bool
	{
		return MetaModel::IsValidAttCode(self::TICKET_CLASS, self::OPERATIONAL_STATUS)
			&& UserRights::IsActionAllowedOnAttribute(self::TICKET_CLASS, self::OPERATIONAL_STATUS, UR_ACTION_READ);
	}

	private function instruction(): string
	{
		$aWhose = array_map(
			static fn (string $sAttCode): string => "{$sAttCode} = :current_contact_id",
			self::availableContactAttributes()
		);

		$aConditions = ['('.implode(' OR ', $aWhose).')'];
		$bOpenInOql  = self::hasOperationalStatus();

		if ($bOpenInOql) {
			$aConditions[] = self::OPERATIONAL_STATUS." = '".self::STATUS_ONGOING."'";
		}

		$sOql = 'SELECT '.self::TICKET_CLASS.' WHERE '.implode(' AND ', $aConditions);

		$sText = 'Call core_object_search_by_oql with the OQL "'.$sOql.'" to retrieve my tickets'
			.' (:current_contact_id is resolved by iTop to the contact I am logged in as, so pass it exactly as written).';

		if (!$bOpenInOql) {
			// The instance has no operational_status to filter on, so the model
			// is told to do it from the lifecycle instead of being handed a
			// query that would have failed.
			$sText .= ' This instance has no operational_status attribute, so the query returns every ticket:'
				.' call core_class_schema on the ticket classes it returns to find which states count as finished,'
				.' and leave those out of what you present.';
		}

		return $sText
			.' Ask for output_fields covering at least the title, the status, the priority and the dates you need.'
			.' Present the results as a clear list showing for each ticket: its class and ID, title, requester,'
			.' priority, and how long it has been open. If no tickets are found, say so clearly.'
			.' Respond in the same language as the ticket titles.';
	}
}
