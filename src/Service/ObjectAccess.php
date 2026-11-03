<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
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
	 * The access of this request: the portal's in portal mode, the console's
	 * otherwise.
	 *
	 * @throws MCPPortalException When the portal cannot be read; portal mode then serves nothing.
	 */
	public static function Current(): self
	{
		return PortalMode::Current() === null
			? new self()
			: new PortalAccess(PortalPolicy::ForCurrentRequest());
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
	 * attribute rights every read applies. On the console, every attribute.
	 *
	 * @return \Closure(string): bool Built once per object, asked once per attribute.
	 */
	public function ShownOf(DBObject $oObject): \Closure
	{
		return static fn (string $sAttCode): bool => true;
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
		return false;
	}

	/** Why this mode refuses a change to the object UserRights allows, or null. */
	public function RefusalToChange(DBObject $oObject): ?string
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
