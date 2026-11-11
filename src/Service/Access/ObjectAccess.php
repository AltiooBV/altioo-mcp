<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Access;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Mcp\Exception\ToolCallException;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DBSearch;
use MetaModel;
use UserRights;

/**
 * What the caller may read of objects, in the mode this request is served in.
 *
 * One tool serves both modes (doc/adr/0006-one-tool-surface-the-portal-is-a-policy.md),
 * and asks this rather than UserRights: the console's answer is UserRights on
 * the class and on the object, and the portal's is that same answer narrowed
 * by the portal's scopes, bricks and forms ({@see PortalAccess}). Nothing a
 * console read refuses is ever served in portal mode.
 *
 * Its write side holds only what a mode adds to the console's checks, which
 * stay in the write tools: on the console every refusal here is null.
 *
 * @internal
 * @since 1.2.0
 */
class ObjectAccess
{
	/**
	 * How this request builds its access, when something above set one.
	 *
	 * Set by whoever puts the request in portal mode ({@see PortalAccess::Enter()}),
	 * because choosing the portal's subclass is a decision about the portal, and
	 * this base class sits below it. A factory and not an instance: the portal
	 * cannot always be read, and the refusal belongs to the first tool that asks,
	 * as it did when this class chose for itself. Static for the reason
	 * AccessPolicy::Current() is: one request per process.
	 */
	private static ?\Closure $fCurrent = null;

	/**
	 * The access of this request: the one a caller above set for it (the
	 * portal's, in portal mode), the console's when none did.
	 *
	 * @throws MCPPortalException When the portal cannot be read; portal mode then serves nothing.
	 */
	public static function Current(): self
	{
		return self::$fCurrent === null ? new self() : (self::$fCurrent)();
	}

	/**
	 * Sets how this request builds its access.
	 *
	 * @param \Closure(): self $fFactory
	 */
	public static function Remember(\Closure $fFactory): void
	{
		self::$fCurrent = $fFactory;
	}

	/** For tests, and for a runner that serves more than one request. */
	public static function Forget(): void
	{
		self::$fCurrent = null;
	}

	/**
	 * The object, if the caller may read it; null otherwise, whether it is
	 * refused or absent, so that a refusal cannot tell that it exists.
	 *
	 * UserRights on the object before it is read, then on the class it
	 * actually is: being allowed to read the parent says nothing about the
	 * leaf, at class level or at object level.
	 */
	public function Readable(string $sClass, int $iId): ?DBObject
	{
		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ, new DBObjectSet(ObjectQuery::ById($sClass, $iId)))) {
			return null;
		}

		$oObject = MetaModel::GetObject($sClass, $iId, false);
		if ($oObject === null) {
			return null;
		}

		$sFinalClass = get_class($oObject);
		if (ExclusionPolicy::ExcludesClass($sFinalClass) || ExclusionPolicy::IsFiledUnderExcluded($oObject)) {
			return null; // an object of an excluded class, or a file filed under one, is not there (doc/adr/0012)
		}
		if ($sFinalClass !== $sClass
			&& (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)
				|| !UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ, new DBObjectSet(ObjectQuery::ById($sFinalClass, $iId))))) {
			return null;
		}

		return $oObject;
	}

	/**
	 * A query narrowed to what the caller may read of it, or null for none of
	 * it. On the console the ORM applies the caller's rights to every query,
	 * so there is nothing to add.
	 *
	 * DBObjectSearch extends DBSearch; Psalm runs with no iTop checkout to say
	 * so (psalm.xml).
	 *
	 * @psalm-suppress InvalidReturnType
	 * @psalm-suppress InvalidReturnStatement
	 */
	public function Bounded(DBObjectSearch $oSearch): ?DBSearch
	{
		return $oSearch;
	}

	/**
	 * Whether the caller is shown an attribute of the object, on top of the
	 * attribute rights every read applies. On the console, every attribute
	 * that is not excluded.
	 *
	 * @return \Closure(string): bool Built once per object, asked once per attribute.
	 */
	public function ShownOf(DBObject $oObject): \Closure
	{
		$sClass = get_class($oObject);

		// Every attribute, but the ones the operator excluded (doc/adr/0012).
		return static fn (string $sAttCode): bool => !ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode);
	}

	/**
	 * What of a write's `changes` the caller is shown. A write reports every
	 * attribute it touches, the ones iTop fills in as well as the ones named,
	 * and on the console the attribute rights already decided what each shows.
	 * On the console, all of them.
	 *
	 * @param array<string, mixed> $aChanges  As WritePlan::Changes() reports them.
	 * @param array<int, string>   $aSupplied The attribute codes the caller sent.
	 *
	 * @return array<string, mixed>
	 */
	public function Disclosed(DBObject $oObject, array $aChanges, array $aSupplied): array
	{
		return $aChanges;
	}

	/** Whether the caller may read what was recorded happening to objects. */
	public function ShowsHistory(): bool
	{
		return true;
	}

	/**
	 * The attributes a read of the object may report, or null for every one
	 * the attribute rights let through. On the console, null.
	 *
	 * @return array<int, string>|null
	 */
	public function VisibleFields(DBObject $oObject): ?array
	{
		return null;
	}

	/**
	 * Who created the object and who last changed it, from the change log,
	 * or null when this caller is not given it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function Attribution(DBObject $oObject): ?array
	{
		return ObjectHistory::AttributionFor($oObject);
	}

	/**
	 * The write gates on the object, with 'depends' resolved where the
	 * object settles it ({@see ObjectSerializer::RightsOn()}).
	 *
	 * @param DBObjectSet|null $oInstanceSet Reused across gates of one object.
	 *
	 * @return array<string, string>
	 */
	public function RightsOn(DBObject $oObject, ?DBObjectSet &$oInstanceSet): array
	{
		return ObjectSerializer::RightsOn($oObject, get_class($oObject), null, $oInstanceSet);
	}

	/**
	 * The stimuli the object's current state accepts, and whether the caller
	 * may apply each ({@see ObjectSerializer::StimuliOn()}); null for a class
	 * with no lifecycle.
	 *
	 * @param array<string, string> $aRights      {@see RightsOn()} for this object.
	 * @param DBObjectSet|null      $oInstanceSet Reused across gates of one object.
	 *
	 * @return array<string, mixed>|null
	 */
	public function StimuliOn(DBObject $oObject, array $aRights, ?DBObjectSet &$oInstanceSet): ?array
	{
		return ObjectSerializer::StimuliOn($oObject, get_class($oObject), $aRights, $oInstanceSet);
	}

	/**
	 * What a read adds in this mode to the object's own fields. Nothing on the
	 * console, where an attachment is an object of its own.
	 *
	 * @return array<string, mixed>
	 */
	public function Extras(DBObject $oObject): array
	{
		return [];
	}

	/**
	 * The object a creation starts from, when the mode decides it; null when
	 * the caller's class decides, as on the console, where a create action
	 * means nothing.
	 *
	 * @throws ToolCallException When an action or a starting object is given on the console.
	 */
	public function Prepared(string $sAction, int $iFromId): ?DBObject
	{
		if ($sAction !== '' || $iFromId !== 0) {
			throw new ToolCallException('action and from_id are a portal\'s create action, and this connection is not served in portal mode: pass class and fields.');
		}

		return null;
	}

	/**
	 * Whether this mode hides an object UserRights lets the caller read. A
	 * hidden object is answered as one that does not exist.
	 */
	public function Hides(string $sClass, int $iId): bool
	{
		return ExclusionPolicy::ExcludesClass($sClass);
	}

	/** Why this mode refuses a change to the object UserRights allows, or null. */
	public function RefusalToChange(DBObject $oObject): ?string
	{
		return null;
	}

	/**
	 * Whether files are read and added as objects of the class Attachment,
	 * through UserRights. True on the console. In portal mode a file is never
	 * reached that way: the portal checks the object it is attached to, and
	 * so does {@see ShownAttachment()}
	 * (doc/adr/0011-portal-mode-reads-and-adds-attachments.md).
	 */
	public function AttachmentsAreObjects(): bool
	{
		return true;
	}

	/**
	 * The Attachment the mode shows this caller, or null for one it does not -
	 * unknown, out of reach, or somebody's unsent draft, all alike. Meaningful
	 * only where {@see AttachmentsAreObjects()} is false.
	 */
	public function ShownAttachment(int $iId): ?DBObject
	{
		return null;
	}

	/**
	 * Why this mode refuses adding a file to the object, or null. The console's
	 * checks stay in the tool; on the console nothing is added here.
	 */
	public function RefusalToAttach(DBObject $oObject): ?string
	{
		return null;
	}

	/** Why this mode refuses a stimulus UserRights allows on the object now, or null. */
	public function RefusalToApply(DBObject $oObject, string $sStimulus): ?string
	{
		return null;
	}

	/**
	 * Why this mode refuses fields the console's form would let the caller
	 * set, or null.
	 *
	 * @param array<string, mixed> $aFields
	 * @param string               $sStimulus The transition being applied, '' for an edit.
	 */
	public function RefusalToSet(DBObject $oObject, array $aFields, string $sStimulus = ''): ?string
	{
		return null;
	}

	/**
	 * The values, once set, that point at objects this mode does not let the
	 * caller read: attribute code => why.
	 *
	 * @param array<int, string> $aAttCodes
	 *
	 * @return array<string, string>
	 */
	public function RefusalsForReach(DBObject $oObject, array $aAttCodes): array
	{
		return [];
	}
}
