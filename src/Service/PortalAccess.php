<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use DBObject;
use DBObjectSearch;
use DBSearch;

/**
 * {@see ObjectAccess} in portal mode: the console's answer, narrowed by the
 * portal's. Each method asks the console's question first and the portal's
 * after it, so the portal can only take away.
 *
 * @internal
 * @since 1.2.0
 */
final class PortalAccess extends ObjectAccess
{
	public function __construct(private readonly PortalPolicy $oPolicy)
	{
	}

	/**
	 * The object, if UserRights and the portal's scopes both let the caller
	 * read it. The change log is never an object here: a portal shows it as
	 * history, which carries the gates on the object a row is about.
	 */
	#[\Override]
	public function Readable(string $sClass, int $iId): ?DBObject
	{
		if (ObjectHistory::IsReserved($sClass)) {
			return null;
		}

		$oObject = parent::Readable($sClass, $iId);
		if ($oObject === null || ObjectHistory::IsReserved(get_class($oObject))) {
			return null;
		}

		return $this->oPolicy->Readable($sClass, $iId) === null ? null : $oObject;
	}

	/**
	 * The query intersected with the portal's read scope on its class.
	 *
	 * DBObjectSearch extends DBSearch; Psalm runs with no iTop checkout to say
	 * so (psalm.xml).
	 *
	 * @psalm-suppress InvalidArgument
	 */
	#[\Override]
	public function Bounded(DBObjectSearch $oSearch): ?DBSearch
	{
		return $this->oPolicy->Bounded($oSearch, $oSearch->GetClass());
	}

	/**
	 * The attributes the portal's view form shows for the object as it is
	 * now. One the form hides is not the caller's to read, in a read or in
	 * the object's history.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's forms cannot be read.
	 */
	#[\Override]
	public function ShownOf(DBObject $oObject): \Closure
	{
		$aFlags = $this->oPolicy->Fields(get_class($oObject), PortalPolicy::MODE_VIEW, $oObject);

		return static fn (string $sAttCode): bool => isset($aFlags[$sAttCode])
			&& ($aFlags[$sAttCode] & OPT_ATT_HIDDEN) !== OPT_ATT_HIDDEN;
	}

	/** When a brick granted to the caller reaches the change log, and a scope covers it. */
	#[\Override]
	public function ShowsHistory(): bool
	{
		return $this->oPolicy->ShowsHistory();
	}
}
