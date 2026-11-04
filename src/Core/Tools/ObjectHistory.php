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
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use DBObjectSearch;
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
	private const PORTAL_DESCRIPTION = 'Report what iTop recorded happening to one object the portal shows this user: each change with its date, who made it, the attribute affected and the values before and after, newest first. Served when the portal shows history - core_class_list says so in "history" - and limited to the fields the object\'s view form shows. Narrow to one attribute with att_code, using the field codes core_class_schema or core_object_get report.';

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

		try {
			$oAccess = ObjectAccess::Current();
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}

		// The portal's refusal first, so that it is phrased as the portal's.
		if (!$oAccess->ShowsHistory()) {
			throw new ToolCallException('The portal shows no history. Call core_class_list for what it offers.');
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
		}

		// The object gate before the log is read, so that a history request
		// cannot report the existence of something a plain read would deny.
		$oObject = $oAccess->Readable($class, $id) ?? throw new ToolCallException("Object {$class}::{$id} not found.");
		$sFinalClass = get_class($oObject);

		try {
			$fShows = $oAccess->ShownOf($oObject);
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}

		// A field the portal hides is answered as a code the class lacks, so
		// this cannot map out what the portal withholds.
		$sAttCode = trim($att_code);
		if ($sAttCode !== '' && (!MetaModel::IsValidAttCode($sFinalClass, $sAttCode) || !$fShows($sAttCode))) {
			throw new ToolCallException(ObjectSerializer::UnknownAttributes([$sAttCode], $sFinalClass));
		}

		$aHistory = HistoryReader::Within(
			$oObject,
			static fn (DBObjectSearch $oSearch): ?DBSearch => $oAccess->Bounded($oSearch),
			$fShows,
			$sAttCode,
			$limit,
			$offset
		);

		// Before and after values and case-log entries are what other people
		// wrote, and a planted instruction later edited out stays readable here.
		if ($aHistory['entries'] !== []) {
			$aHistory['untrusted_text'] = ['fields' => ['entries'], 'note' => ObjectSerializer::UNTRUSTED_NOTICE];
		}

		return ToolOutput::Json($aHistory);
	}
}
