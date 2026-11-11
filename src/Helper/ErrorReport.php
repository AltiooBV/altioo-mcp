<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Throwable;

/**
 * How a failure is told to the caller and to the log: the log gets everything, the caller a reference.
 *
 * @api
 * @since 2.0.0
 */
class ErrorReport
{
	/**
	 * Server-side error channel.
	 *
	 * Detail that must not reach the caller goes here: the response gets a
	 * generic message, the log gets everything.
	 *
	 * @since 2.0.0
	 */
	public static function Log(string $sMessage, array $aContext = []): void
	{
		MCPLog::Error($sMessage, null, $aContext);
	}

	/**
	 * A fresh correlation reference for one failure.
	 *
	 * Eight random bytes: short enough that a person can read it out of a
	 * response and grep for it, long enough that two failures on a busy
	 * instance do not collide.
	 *
	 * @since 2.0.0
	 */
	public static function NewReference(): string
	{
		$sReference = bin2hex(random_bytes(8));

		// Kept so the audit row can carry it. Minted deep inside a tool and
		// handed to the caller inside a message, a reference used to reach the
		// iTop log file and nothing else - so the row recording that very call
		// had error_ref empty, and the identifier the caller was told to quote
		// resolved to nothing anyone could query. A review found 118 error rows
		// and not one populated.
		//
		// Static, and safe to be: this endpoint serves one request per process
		// and holds nothing between them. Bounded because a bulk call mints one
		// per failing row and the column is not a log.
		if (count(self::$aErrorReferences) < self::MAX_ERROR_REFERENCES) {
			self::$aErrorReferences[] = $sReference;
		}

		return $sReference;
	}

	/** Most references one audit row carries; the log has the rest. */
	private const MAX_ERROR_REFERENCES = 12;

	/** @var array<int, string> */
	private static array $aErrorReferences = [];

	/**
	 * Every reference this request minted, in the order it minted them.
	 *
	 * @return array<int, string>
	 * @since 2.0.0
	 */
	public static function MintedReferences(): array
	{
		return self::$aErrorReferences;
	}

	/**
	 * For tests, and for a runner that serves more than one request. @since 1.0.0
	 *
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function ForgetReferences(): void
	{
		self::$aErrorReferences = [];
	}

	/**
	 * Tells the caller that something failed, and tells the log what.
	 *
	 * The rule this implements is provenance, not severity. A message this
	 * module composed - a CheckToWrite() issue, "Unknown attribute 'x'", a
	 * validation refusal - is written for whoever is on the other end and is
	 * exactly what lets a model correct its own call, so it goes back
	 * unchanged. A message that came out of the ORM is written for whoever
	 * maintains iTop: it routinely carries SQL, table names, class internals
	 * and occasionally row values, and a caller cannot act on any of it.
	 *
	 * That second kind is what this is for. Everything the ORM said goes to
	 * log/error.log under a reference, and the caller gets the reference.
	 *
	 * Nothing is lost for the model, either: by the time an ORM call throws,
	 * the pre-write check has already run and refused everything the caller
	 * could have fixed. What is left is the instance's problem - a deadlock, a
	 * broken trigger, a column that no longer matches the datamodel - so the
	 * honest thing to return is "this is not yours to fix" rather than a
	 * fragment of SQL the model will try to reason about.
	 *
	 * @param string    $sWhat A phrase naming the operation, e.g. "Failed to create the object".
	 * @param Throwable $e     What the ORM threw. Never shown to the caller.
	 *
	 * @return string The message to put in front of the caller.
	 *
	 * @since 1.0.0
	 */
	public static function OpaqueFailure(string $sWhat, Throwable $e): string
	{
		$sReference = self::NewReference();

		self::Log(sprintf(
			'[%s] %s - %s: %s in %s:%d',
			$sReference,
			$sWhat,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		return sprintf(
			'%s. The cause is server-side and was not something this call could have avoided; '
			.'it is recorded in the iTop log, and on this call\'s own audit row, under reference %s. '
			.'Report that reference rather than retrying%s',
			$sWhat,
			$sReference,
			self::auditLookup()
		);
	}

	/**
	 * Where the audit row is found, for the account that may read it. A portal
	 * user may not, and the tools that would find it are not served to them.
	 */
	private static function auditLookup(): string
	{
		return PortalMode::Current() !== null
			? '.'
			: ': an account that may read AltiooEventMCPService can find the row with core_object_search_by_class on error_ref.';
	}

	/**
	 * Tells the caller iTop refused a value they supplied, and tells the log why.
	 *
	 * The sibling of {@see self::OpaqueFailure()} and the same provenance rule;
	 * what differs is whose problem it is. OpaqueFailure() answers a server-side
	 * failure nothing the caller does will fix, so it says not to retry. This
	 * one answers a value the caller chose, so it names the attribute and leaves
	 * another attempt open - while still keeping iTop's own words out of the
	 * response.
	 *
	 * Those words are the point. RestUtils::MakeValue() catches whatever it hit
	 * and rethrows it as "<attcode>: <message>", and one of the things it can
	 * hit is a database error: an external key given as a string is run as OQL
	 * by RestUtils::FindObjectFromKey(), and CoreException folds its context
	 * array into the message it exposes - so a MySQLException raised on that
	 * path arrives carrying 'query' => the SQL that was issued, with the table
	 * names in it and the MySQL error beside them. Passing that straight back
	 * hands a caller the schema in exchange for a malformed value.
	 *
	 * @param string    $sWhat A phrase naming the value, e.g. "Invalid value for attribute 'org_id'".
	 * @param Throwable $e     What iTop threw. Never shown to the caller.
	 *
	 * @return string The message to put in front of the caller.
	 *
	 * @since 1.0.0
	 */
	public static function RejectedValue(string $sWhat, Throwable $e): string
	{
		$sReference = self::NewReference();

		self::Log(sprintf(
			'[%s] %s - %s: %s in %s:%d',
			$sReference,
			$sWhat,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		// The advice is addressed to a model, so it names what a model can
		// actually do. The reference belongs in iTop's log, which nothing on
		// this server reads: telling a caller to "quote that reference" sent it
		// looking for a tool that does not exist. It is worth reporting to the
		// user, who has a console; it is not a step the caller can take.
		return sprintf(
			'%s. iTop refused the value and its reason is in the instance log, not in this answer. '
			.'Check the attribute against core_class_schema - its type, its allowed values, its pattern - and correct the call. '
			.'If the value looks right, tell the user it failed and give them reference %s: it is in the instance log, and on '
			.'this call\'s own audit row%s',
			$sWhat,
			$sReference,
			self::auditLookup()
		);
	}
}
