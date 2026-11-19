<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Combodo\iTop\Portal\Helper\ApplicationHelper;

/**
 * The columns one portal's lists give a class.
 *
 * A portal shows a link set as a table of the remote class's "list" columns
 * (ObjectFormManager::PrepareFields(), "Overriding attributes to display", then
 * BsLinkedSetFieldRenderer::PrepareItems()), never as every attribute of every
 * row. This answers which columns, with the portal's own lookup, so that the
 * rows a link set is served with are the ones the portal would draw.
 * Which rows, and what the user may read of each, is PortalAccess' to answer.
 *
 * @internal
 * @since 2.1.0
 */
final class PortalLists
{
	/**
	 * @param array<string, array<string, array<int, array<string, mixed>>>> $aLists The portal's lists, class then list id.
	 */
	public function __construct(private readonly array $aLists)
	{
	}

	/**
	 * The attribute codes the portal lists for a class in a link set: the
	 * list "list" of the class, else its default, else the closest parent's,
	 * else the datamodel's own "list"; and the friendly name, which the portal
	 * puts first when the list does not hold it.
	 *
	 * @return array<int, string>
	 */
	public function ColumnsOf(string $sClass): array
	{
		$aColumns = ApplicationHelper::GetLoadedListFromClass($this->aLists, $sClass, 'list');
		if (!in_array('friendlyname', $aColumns, true)) {
			array_unshift($aColumns, 'friendlyname');
		}

		return array_values(array_unique(array_map('strval', $aColumns)));
	}
}
