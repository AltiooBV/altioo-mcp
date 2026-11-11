<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

/**
 * What a refused write says, spelled once so every tool says the same thing.
 *
 * Each sentence names what is being refused and, where one decides it, the
 * setting that does - a model told only "no" retries a variation, and a model
 * told which switch is off reports it. The sentences are data: which of them a
 * write gets is decided by {@see AccessGrants}.
 *
 * @internal
 * @since 2.0.0
 */
final class AccessGrantRefusals
{
	/**
	 * What every write tool says when the instance refuses these classes
	 * outright, spelled once so they all say the same thing and all name the
	 * setting that decides it - a model told only "no" retries a variation,
	 * and a model told which switch is off reports it.
	 */
	public const GRANT_REFUSAL = 'Class \'%s\' decides who may reach this endpoint and what they may do with it, so it cannot be written here unless mcp_allow_access_administration is on. Change it in the iTop console.';

	/**
	 * What they say when the instance allows these classes and the call
	 * reaches the caller's own access anyway.
	 *
	 * A different sentence from the one above on purpose: the first is an
	 * instance that has not opted in and can, the second is a rule no
	 * configuration lifts. Telling them apart is what stops a model - or the
	 * person reading over its shoulder - from asking an operator to turn on a
	 * setting that would not have helped.
	 */
	public const SELF_REFUSAL = 'Class \'%s\' can be administered here, but not for yourself: this call reaches the access you are connected with. Change your own token, account or profiles in the iTop console.';

	/**
	 * What every write tool says when the instance refuses a synchronisation
	 * definition.
	 *
	 * Names what is actually being refused - a write performed later by
	 * something else - rather than "access denied", because a model told the
	 * latter tries a neighbouring class and a model told the former reports
	 * it.
	 */
	public const DELEGATION_REFUSAL = 'Class \'%s\' defines work that iTop\'s synchronisation engine carries out later, and this call does not settle which class it would be carried out on. Name the target - scope_class on the data source, sync_source_id on a row that hangs off one - so the write can be graded against it.';

	/**
	 * What they say when the instance allows it and the definition targets a
	 * class this endpoint may not write anyway.
	 *
	 * The setting buys administration of other people's access through *this*
	 * endpoint, where every write is graded against the caller's own
	 * credential. A definition handed to the engine is graded against nothing,
	 * so the one rule no setting lifts - never your own access - would be
	 * lifted by staging it instead of performing it.
	 */
	public const DELEGATED_TARGET_REFUSAL = 'Class \'%s\' would be synchronised into \'%s\', which this endpoint does not write directly - so it does not write it through something else either. Whatever refuses \'%2$s\' to a tool refuses it here, and no setting changes that, because the engine performs the write without any of the checks a tool applies. Do it in the iTop console.';

	/**
	 * What they say when the definition targets an ordinary class the caller
	 * could not have written itself.
	 *
	 * The rule the whole barrier reduces to, once the escalation above is
	 * shut: staging a write must not be a way to perform one you were refused.
	 * The engine runs as a trusted internal process and consults no
	 * UserRights, so the rights are asked here, once, about the class the
	 * definition points at - and all five of them, because a data source can
	 * create, can modify, and depending on its own delete_policy can delete,
	 * in bulk.
	 */
	public const DELEGATED_RIGHTS_REFUSAL = 'Class \'%s\' would be synchronised into \'%s\', and the synchronisation engine writes without consulting anyone\'s rights - so a definition may only be written here for a class you could write yourself. %s Ask an administrator for the right on \'%s\', or define the source in the iTop console.';

	/**
	 * What every write tool says when the instance refuses a trigger, an
	 * action or the connection behind one.
	 *
	 * Names the capability rather than the class, because that is what an
	 * operator is being asked to decide about: not "may an assistant write
	 * Trigger rows" but "may an assistant leave standing instructions that
	 * make this instance call out on its own".
	 */
	public const AUTOMATION_REFUSAL = 'Class \'%s\' arranges something no tool here can do at all - sending mail, calling a URL, invoking a method by name, or authenticating outward as this instance - so it is not refused for want of a right you might be granted: no caller of this endpoint has one, administrators included. Writing it is refused unless mcp_allow_privilege_escalation is on, which is an operator allowing this endpoint to grant more than the credential it was called with, rather than a switch for managing automation. Do it in the iTop console. Reading is unaffected.';

	/**
	 * What every write tool says about the record of what happened.
	 *
	 * Names no setting, because there is not one. A caller told "unless X is
	 * on" goes and asks for X.
	 */
	public const RECORDING_REFUSAL = 'Class \'%s\' is a record of something that happened - this endpoint writes one for every request it serves - so it cannot be written or deleted here at all, by anyone, with no setting to change that. An audit trail the audited party can edit is not one. Reading is unaffected: ask for the rows rather than changing them.';

	/**
	 * What they say about the rules that decide what gets flagged.
	 */
	/**
	 * What they say about editing a check that is watching the caller.
	 */
	/** What they say when a recipient query reaches something the caller may not read. */
	public const RECIPIENT_REFUSAL = 'The \'%2$s\' of this \'%1$s\' is a query over \'%3$s\', and the mailer runs it with rights and silos switched off - so it would collect addresses from every matching row. Refused because %4$s. Narrow the query to a class you can read here, or set the recipients up in the iTop console.';

	public const OPERATOR_ESCALATION_REFUSAL = 'Class \'%s\' is listed in mcp_privilege_escalation_classes: this instance\'s operator has said that writing it stages work something else carries out later, outside this endpoint, where what you may do is not checked. Writing it is refused unless mcp_allow_privilege_escalation is on, whatever your profile says. Do it in the iTop console. Reading is unaffected.';

	public const DETECTION_TAMPER_REFUSAL = 'Class \'%s\' is a check that already exists, and a check is not something the account it watches gets to edit here: turning one off, making the change it would have flagged and turning it back on leaves nothing for anyone to notice. Creating a new one is allowed; changing or deleting this one is not, whatever mcp_allow_privilege_escalation says. Use the iTop console.';

	public const DETECTION_REFUSAL = 'Class \'%s\' is part of iTop\'s data-quality audit, which decides what gets flagged to a person as wrong - not something this endpoint changes on its own initiative. It is refused unless mcp_allow_privilege_escalation is on, which is an operator allowing this endpoint to grant more than the credential it was called with rather than a switch for managing automation. Change it in the iTop console. Reading is unaffected.';

	/**
	 * What they say when a write reaches somebody else's personal row.
	 *
	 * The mirror of SELF_REFUSAL and deliberately its own sentence: that one
	 * refuses your own access, this one refuses everyone else's preferences.
	 */
	public const NOT_YOURS_REFUSAL = 'Class \'%s\' holds one person\'s own settings, and this row is not yours. iTop\'s own API for it only ever reads and writes the account it is called by, and so does this endpoint unless mcp_allow_access_administration is on. Act on your own row, ask an operator to turn that setting on, or use the iTop console.';
}
