<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use appUserPreferences;
use utils;

/**
 * Turns the caller's own "Show obsolete data" preference on or off.
 *
 * Obsolescence is a condition the datamodel evaluates per class, and the
 * conditions are not all alike: a FunctionalCI is obsolete when its status
 * says so, an Organization when it is inactive, a DatabaseInstance when the
 * server it runs on is obsolete, and a contract when its end date passed
 * fifteen months ago. What they share is that iTop leaves those objects out of
 * result sets unless the account asks for them.
 *
 * "The account" is the point. It is a preference on the user - the checkbox in
 * the console, stored in appUserPreferences - not a session flag and not
 * something a URL can carry, so before this the only way to change what
 * searches returned here was to open the console and tick it. An assistant
 * asked "and the decommissioned ones?" had no answer it could act on.
 *
 * Self-scoped by construction: SetPref() writes the preferences of
 * UserRights::GetUserId() and takes no user argument, so this tool cannot
 * reach anyone else's - which is what makes writing it safe to offer at all.
 *
 * It is not a per-call switch, and says so. The preference persists, and it is
 * the same one the console reads, so a caller that turns it on has changed
 * what its user sees there too until something turns it back.
 *
 * @since 1.0.0
 */
class SetObsoleteData extends AbstractMCPTool
{
	/** The class the preference lives on, asked about rather than assumed. */
	private const PREFERENCE_CLASS = 'appUserPreferences';

	public function getNamespace(): string
	{
		return 'core';
	}

	/** About the session and the account, like the identity tool it sits beside. */
	public function getToolset(): string
	{
		return 'server';
	}

	protected function defaultTitle(): string
	{
		return 'Show or Hide Obsolete Data';
	}

	public function getDescription(): ?string
	{
		return 'Turn the calling user\'s "Show obsolete data" preference on or off. '
			.'Obsolete objects are left out of search results while it is off, which is the default; a read by id returns one either way. '
			.'This is a stored preference on the account, not a per-call switch: it persists, and it is the same setting the console reads, so turning it on changes what that user sees there too. '
			.'core_current_user reports the current value. To include archived objects instead - a different notion - use the archived argument of the search tools.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Show or hide obsolete data',
			false,  // readOnlyHint - it stores a preference
			false,  // destructiveHint - nothing is lost, and the same call undoes it
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'visible'  => [
					'type'        => 'boolean',
					'description' => 'true to include obsolete objects in search results, false to leave them out.',
				],
				'simulate' => WritePlan::SimulateSchemaProperty('store the preference'),
			],
			'required' => ['visible'],
		];
	}

	public function getOutputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'visible'   => [
					'type'        => 'boolean',
					'description' => 'The preference as it now stands, or would stand.',
				],
				'simulated' => [
					'type'        => 'boolean',
					'description' => 'true when the call reported what it would do and stored nothing.',
				],
				'previous' => [
					'type'        => 'boolean',
					'description' => 'What it was before this call.',
				],
				'changed'  => [
					'type'        => 'boolean',
					'description' => 'false when the call asked for the value it already had.',
				],
			],
			'required'             => ['visible', 'simulated', 'previous', 'changed'],
			'additionalProperties' => false,
		];
	}

	/**
	 * @param bool $visible  Whether searches should return obsolete objects
	 * @param bool $simulate When true (the default), the change is reported and not stored
	 *
	 * @return mixed The preference as it now stands, what it was, and whether the call moved it
	 * @throws ToolCallException if the preference cannot be stored.
	 */
	public static function execute(bool $visible, bool $simulate = WritePlan::SIMULATE_BY_DEFAULT): mixed
	{
		// The barrier every write on this endpoint passes, asked about the
		// class this one writes. appUserPreferences is not a class that grades
		// access today, and asking rather than assuming is what covers the day
		// a version or a pack makes it one.
		$sRefusal = AccessGrants::RefusalFor(self::PREFERENCE_CLASS);
		if ($sRefusal !== null) {
			throw new ToolCallException($sRefusal);
		}

		// Read before the write, and read the effective value rather than the
		// stored one: utils::ShowObsoleteData() is what the searches ask, and
		// it falls back to obsolescence.show_obsolete_data where the account
		// has never expressed a preference. "It was already on" is otherwise
		// indistinguishable from "nobody has ever said".
		$bPrevious = utils::ShowObsoleteData();

		if ($simulate) {
			// A preference is small, persistent, and shared with the console -
			// which is exactly the shape of thing this server withholds on a
			// first call.
			return ToolOutput::Structured([
				'visible'   => $visible,
				'simulated' => true,
				'previous'  => $bPrevious,
				'changed'   => $bPrevious !== $visible,
			]);
		}

		try {
			appUserPreferences::SetPref('show_obsolete_data', $visible);
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to store the preference', $e));
		}

		return ToolOutput::Structured([
			'visible'   => $visible,
			'simulated' => false,
			'previous'  => $bPrevious,
			'changed'   => $bPrevious !== $visible,
		]);
	}
}
