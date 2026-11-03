<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
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
 * The read side only, for now: the write tools move onto it when they are
 * served in portal mode.
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
}
