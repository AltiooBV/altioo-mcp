<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory as HistoryReader;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DBSearch;
use MetaModel;
use UserRights;

/**
 * What happened to one object, and who did it.
 *
 * The state a read reports is the current one; why it got there is in the
 * change log and nowhere else, because iTop stamps no creation or update
 * attribution on the object itself. "Why did this ticket change status" and
 * "what did the agent do yesterday" are the questions this answers, and
 * without it they are answerable in the console and not through the surface
 * the agent works on.
 *
 * Its own toolset, and deliberately not 'objects'. The log crosses silos by
 * construction - objkey is an integer and the profile grant is class-wide - so
 * an operator narrowing what this endpoint serves should be able to withhold
 * the history without withholding object reads. {@see HistoryReader} applies
 * the gates that make it safe to serve at all.
 *
 * Served in portal mode too, one tool for both: there the portal is one more
 * set of limits on top of the same gates. It shows history when a brick it
 * grants reaches the change log; the object must be one it shows the user,
 * the rows those its change-log scope holds, and the attributes those the
 * object's view form shows. Nothing a console read refuses is served.
 *
 * @since 1.0.0
 */
class ObjectHistory extends AbstractMCPTool implements PortalAware
{

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Who changed what, which an operator can withhold on its own. */
	public function getToolset(): string
	{
		return 'history';
	}

	protected function defaultTitle(): string
	{
		return 'Get Object History';
	}

	public function getDescription(): ?string
	{
		if (PortalMode::Current() !== null) {
			return self::PORTAL_DESCRIPTION;
		}

		return 'Report what iTop recorded happening to one object: each change with its date, the user who made it, the attribute affected and the values before and after. This is the only place creation and update attribution exists - iTop keeps no "created by" or "last updated" field on an object - so read it to answer when something was opened, who last touched it, or why a value is what it is. Newest first. Narrow to one attribute with att_code, using the codes core_class_schema reports. Case-log entries are included, which the console shows separately rather than in its history panel. Only what iTop tracked is here: an attribute excluded from tracking, and anything written outside iTop, leaves no record.';
	}

	/** What this tool is to a portal-mode connection, which has no class schema. */
	private const PORTAL_DESCRIPTION = 'Report what iTop recorded happening to one object the portal shows this user: each change with its date, who made it, the attribute affected and the values before and after, newest first. Served when the portal shows history - core_portal_describe says so in "history" - and limited to the fields the object\'s view form shows. Narrow to one attribute with att_code, using the field codes core_portal_describe or core_portal_get report.';

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get iTop object history',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'    => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name.',
				],
				'id'       => [
					'type'        => 'integer',
					'description' => 'The ID of the object whose history is wanted.',
					'minimum'     => 1,
				],
				'att_code' => [
					'type'        => 'string',
					'description' => 'Restrict to one attribute, e.g. "status". Empty (the default) reports every recorded operation, including the creation.',
					'default'     => '',
				],
				'limit'    => [
					'type'        => 'integer',
					'description' => 'Maximum number of entries to return, newest first.',
					'minimum'     => 1,
					'maximum'     => HistoryReader::MAX_LIMIT,
					'default'     => HistoryReader::DEFAULT_LIMIT,
				],
				'offset'   => [
					'type'        => 'integer',
					'description' => 'Number of entries to skip, for paging through a long history.',
					'minimum'     => 0,
					'default'     => 0,
				],
			],
			'required' => ['class', 'id'],
		];
	}

	/**
	 * @param string $class The class of the object whose history is wanted, e.g. 'UserRequest'
	 * @param int $id The ID of that object
	 * @param string $att_code Optional attribute code to restrict the history to, e.g. 'status'
	 * @param int $limit Maximum number of entries to return (default 50, max 500)
	 * @param int $offset Number of entries to skip for paging
	 * @return mixed An array containing the class, the id, the attribute asked about, the paging, the number of recorded operations and the entries themselves
	 * @throws ToolCallException if the class is unknown, if the object is not found, or if the attribute is not one the class declares.
	 */
	public static function execute(
		string $class,
		int    $id,
		string $att_code = '',
		int    $limit = HistoryReader::DEFAULT_LIMIT,
		int    $offset = 0,
	): mixed
	{
		if ($id < 1) {
			throw new ToolCallException("Invalid ID '{$id}'.");
		}
		if ($limit < 1 || $limit > HistoryReader::MAX_LIMIT) {
			throw new ToolCallException('Invalid limit. Please specify a limit between 1 and '.HistoryReader::MAX_LIMIT.'.');
		}
		if ($offset < 0) {
			throw new ToolCallException('Invalid offset. Please specify a non-negative offset.');
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		// The portal first, so that its refusals are phrased as the portal's
		// and an object it does not show answers as one that does not exist.
		$oPolicy = null;
		if (PortalMode::Current() !== null) {
			$oPolicy = self::portalPolicy();
			if (!$oPolicy->ShowsHistory()) {
				throw new ToolCallException('The portal shows no history. Call core_portal_describe for what it offers.');
			}
			if (HistoryReader::IsReserved($class) || $oPolicy->Readable($class, $id) === null) {
				throw new ToolCallException("Object {$class}::{$id} not found.");
			}
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
		}

		// The object gate before the object is read, so that a history request
		// cannot report the existence of something a plain read would deny.
		$oSearch = ObjectQuery::ById($class, $id);
		$oSet = new DBObjectSet($oSearch);
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ, $oSet)) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		$oObject = MetaModel::GetObject($class, $id, false);
		if ($oObject === null) {
			throw new ToolCallException("Object {$class}::{$id} not found.");
		}

		// Reading the parent says nothing about the leaf, at class level or at
		// object level, and the log is filed under the leaf's own name.
		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found.");
			}
			$oFinalSet = new DBObjectSet(ObjectQuery::ById($sFinalClass, $id));
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ, $oFinalSet)) {
				throw new ToolCallException("Object {$class}::{$id} not found.");
			}
		}

		$sAttCode = trim($att_code);
		if ($sAttCode !== '' && !MetaModel::IsValidAttCode($sFinalClass, $sAttCode)) {
			throw new ToolCallException("Unknown attribute '{$sAttCode}' on class '{$sFinalClass}'. Call core_class_schema for the attribute codes.");
		}

		if ($oPolicy === null) {
			return ToolOutput::Json(HistoryReader::For($oObject, $sAttCode, $limit, $offset));
		}

		$fShows = self::shownBy($oPolicy, $oObject);
		if ($sAttCode !== '' && !$fShows($sAttCode)) {
			// Answered as the console answers a code the class lacks, so this
			// cannot map out the fields the portal hides.
			throw new ToolCallException("Unknown attribute '{$sAttCode}' on class '{$sFinalClass}'. Call core_portal_describe for the field codes.");
		}

		// DBObjectSearch extends DBSearch; Psalm runs with no iTop checkout to
		// say so (psalm.xml).
		/** @psalm-suppress InvalidArgument */
		$fBound = static fn (DBObjectSearch $oSearch): ?DBSearch => $oPolicy->Bounded($oSearch, $oSearch->GetClass());

		return ToolOutput::Json(HistoryReader::Within(
			$oObject,
			$fBound,
			$fShows,
			$sAttCode,
			$limit,
			$offset
		));
	}

	/** The policy of this request's portal, or the refusal a client is given when it cannot be read. */
	private static function portalPolicy(): PortalPolicy
	{
		try {
			return PortalPolicy::ForCurrentRequest();
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}
	}

	/**
	 * Whether the portal's view form shows an attribute of the object, as it
	 * is now: a row about a field the user is not shown is not theirs to read.
	 *
	 * @return \Closure(string): bool
	 */
	private static function shownBy(PortalPolicy $oPolicy, DBObject $oObject): \Closure
	{
		try {
			$aFlags = $oPolicy->Fields(get_class($oObject), PortalPolicy::MODE_VIEW, $oObject);
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}

		return static fn (string $sAttCode): bool => isset($aFlags[$sAttCode])
			&& ($aFlags[$sAttCode] & OPT_ATT_HIDDEN) !== OPT_ATT_HIDDEN;
	}
}
