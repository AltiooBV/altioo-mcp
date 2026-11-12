<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * The tickets assigned to the caller, newest first.
 *
 * Extends AbstractObjectSearch rather than AbstractMCPTool, which is what that
 * class is there for: paging, ordering, field selection and the has_more rule
 * come with it, and they are the parts easy to get subtly wrong. What is left
 * to write is the one thing that makes this tool different from a generic
 * search - the query.
 *
 * It is also the worked example of a pack element served in portal mode
 * (doc/extending.md, "Serving your element in portal mode"). Implementing
 * PortalAware is the declaration; what backs it is that every read below goes
 * through ObjectAccess::Current(), which is the portal's access in portal mode
 * and the console's otherwise. AbstractObjectSearch does not do that for you:
 * it asks UserRights, and "Portal user" is granted read on every class. The
 * operator still has to list this tool in mcp_portal_extensions.
 */
class TicketSearchMine extends AbstractObjectSearch implements PortalAware
{
	public function getNamespace(): string
	{
		return 'acme';
	}

	public function getToolset(): string
	{
		return 'acme-servicedesk';
	}

	protected function defaultTitle(): string
	{
		return 'Search My Tickets';
	}

	public function getDescription(): ?string
	{
		return 'List the open tickets assigned to or raised by the authenticated user, most recently updated first. '
			.'Reach for this before asking the user which ticket they mean: it is usually the shortest path from "my ticket" to an id.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Search my tickets',
			true,  // readOnlyHint — and so served to an MCP-read token
			false, // destructiveHint — meaningless when readOnlyHint is set
			true,  // idempotentHint
			false, // openWorldHint
		);
	}

	public function isAvailable(): bool
	{
		return MetaModel::IsValidClass('UserRequest');
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			// The three groups the base class contributes. Spelling them the
			// same way as every other paged tool is the point of borrowing
			// them: a model that has used one search already knows this one.
			'properties' => self::fieldsSchemaProperties()
				+ self::pagingSchemaProperties()
				+ self::orderingSchemaProperties(),
			'required'   => [],
		];
	}

	/**
	 * @param int    $limit           Page size
	 * @param int    $offset          Rows to skip
	 * @param string $order_by        Attribute to sort on, '' for the default
	 * @param string $order_direction 'asc' or 'desc'
	 * @param string $output_fields   Comma-separated attribute codes, or '*'
	 *
	 * @throws ToolCallException When tickets cannot be read at all.
	 */
	public static function execute(
		int    $limit = self::DEFAULT_LIMIT,
		int    $offset = self::DEFAULT_OFFSET,
		string $order_by = '',
		string $order_direction = self::DEFAULT_SORT,
		string $output_fields = ObjectSerializer::DEFAULT_LIST_FIELDS,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException('Invalid limit. Specify between '.self::MIN_LIMIT.' and '.self::MAX_LIMIT.'.');
		}
		if ($offset < self::MIN_OFFSET) {
			throw new ToolCallException('Invalid offset. Specify a non-negative offset.');
		}
		if (!UserRights::IsActionAllowed('UserRequest', UR_ACTION_READ)) {
			throw new ToolCallException('Access denied: cannot read tickets.');
		}

		// The whole reason this tool exists rather than a documented OQL
		// string: the model does not have to know the attributes are agent_id
		// and caller_id, nor how to name the current user. caller_id is there
		// for the portal, where a user raises tickets and is never the agent.
		$oSearch = DBObjectSearch::FromOQL(
			'SELECT UserRequest WHERE (agent_id = :current_contact_id OR caller_id = :current_contact_id) AND operational_status != "closed"',
			['current_contact_id' => UserRights::GetContactId()]
		);

		// Portal mode: narrow the query to what the portal shows this user.
		// null is "none of it". The console returns the query unchanged, as
		// its ORM already applies the caller's rights. A refusal (portal mode
		// entered by nothing) is reported as a tool error and serves nothing.
		$oAccess = self::portalRefusal(static fn () => ObjectAccess::Current());
		$oSearch = self::portalRefusal(static fn () => $oAccess->Bounded($oSearch));
		if ($oSearch === null) {
			return ToolOutput::Json([
				'class'   => 'UserRequest',
				'total'   => 0,
				'limit'   => $limit,
				'offset'  => $offset,
				'objects' => [],
			] + self::pagingFooter(0, $limit, $offset));
		}

		$aOrderBy = self::orderBy('UserRequest', $order_by, $order_direction);
		$aFields = self::fieldsForPage('UserRequest', $output_fields, $limit);

		$oSet = new DBObjectSet($oSearch, $aOrderBy, [], null, $limit, $offset);

		$aResults = [];
		while ($oTicket = $oSet->Fetch()) {
			$sFinalClass = self::finalClassIfReadable($oTicket, 'UserRequest');
			if ($sFinalClass === null) {
				continue;
			}
			if (self::portalRefusal(static fn () => $oAccess->Hides($sFinalClass, (int)$oTicket->GetKey()))) {
				continue; // answered as a ticket that does not exist
			}
			// Only the attributes the portal's form shows; null is "every
			// attribute the rights let through", which is the console.
			$aVisible = self::portalRefusal(static fn () => $oAccess->VisibleFields($oTicket));
			$aRowFields = $aVisible === null ? $aFields : array_values($aFields === null ? $aVisible : array_intersect($aFields, $aVisible));
			$aResults[] = self::serializeObject($oTicket, $sFinalClass, $aRowFields);
		}

		$iTotal = $oSet->Count();

		// pagingFooter() carries has_more and next_offset. They matter more
		// than they look: object-level rights drop rows from a page after the
		// database counted them, so a short page is not the end of the set and
		// a caller that treats it as one stops early and silently.
		return ToolOutput::Json([
			'class'   => 'UserRequest',
			'total'   => $iTotal,
			'limit'   => $limit,
			'offset'  => $offset,
			'objects' => $aResults,
		] + self::pagingFooter($iTotal, $limit, $offset));
	}
}
