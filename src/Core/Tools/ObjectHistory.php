<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory as HistoryReader;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use DBObjectSet;
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
 * @since 1.0.0
 */
class ObjectHistory extends AbstractMCPTool
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
		return 'Report what iTop recorded happening to one object: each change with its date, the user who made it, the attribute affected and the values before and after. This is the only place creation and update attribution exists - iTop keeps no "created by" or "last updated" field on an object - so read it to answer when something was opened, who last touched it, or why a value is what it is. Newest first. Narrow to one attribute with att_code, using the codes core_class_schema reports. Only what iTop tracked is here: an attribute excluded from tracking, and anything written outside iTop, leaves no record.';
	}

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
	 * @return array An array containing the class, the id, the attribute asked about, the paging, the number of recorded operations and the entries themselves
	 * @throws ToolCallException if the class is unknown, if the object is not found, if the attribute is not one the class declares, or if this user may not read the change log.
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

		if (!HistoryReader::IsReadable()) {
			// A real refusal about this user's rights, phrased as one: the
			// change log is granted per profile and this one does not have it.
			throw new ToolCallException('Read access denied to the change log for this user.');
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
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

		return ToolOutput::Json(HistoryReader::For($oObject, $sAttCode, $limit, $offset));
	}
}
