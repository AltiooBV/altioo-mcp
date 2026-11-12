<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

/**
 * One case: a prompt, and what the calls it provokes must and must not look like.
 *
 * A scenario file is JSON, one case per file:
 *
 *   id            string, the file name without ".json"
 *   description   string, what the case is for
 *   token         "all" | "read" | "write" | "portal": which credential the case expects,
 *                 named as the surface snapshots are (tests/php-unit-tests/Integration/Snapshots).
 *                 The runner does not mint it; it is given one by the environment.
 *   prerequisites string, the data the case expects on the instance. Not loaded by the harness.
 *   prompt        string, what the user says
 *   maxCalls      int, the step budget: more tool calls than this fails
 *   allowedTools  [string], optional: a call to any other tool fails
 *   mustCall      [matcher], optional: each has to be matched by at least one call
 *   forbid        [matcher], optional: no call may match any
 *   firstCallIn   [string], optional: the first call has to be one of these
 *   before        [{commit: matcher, after: matcher}], optional: every call matching `commit`
 *                 is preceded by one matching `after` (named for what it guards, e.g. a dry run).
 *
 * A matcher is {"tool": "name", "arguments": {"key": value}}: the tool, and every listed
 * argument equal to the call's. "arguments" is optional.
 *
 * @internal
 */
final class Scenario
{
	/** @var array<int, string> */
	private const TOKENS = ['all', 'read', 'write', 'portal'];

	/**
	 * @param array<string, mixed> $aData
	 */
	private function __construct(public readonly array $aData)
	{
	}

	/** @throws \InvalidArgumentException */
	public static function FromFile(string $sPath): self
	{
		$sJson = @file_get_contents($sPath);
		if ($sJson === false) {
			throw new \InvalidArgumentException("cannot read {$sPath}");
		}
		try {
			$aData = json_decode($sJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new \InvalidArgumentException("{$sPath}: not JSON ({$e->getMessage()})");
		}

		return self::FromArray(is_array($aData) ? $aData : [], basename($sPath, '.json'));
	}

	/**
	 * @param array<string, mixed> $aData
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function FromArray(array $aData, string $sId): self
	{
		$aErrors = [];
		foreach (['description', 'prompt', 'prerequisites'] as $sKey) {
			if (!isset($aData[$sKey]) || !is_string($aData[$sKey]) || trim($aData[$sKey]) === '') {
				$aErrors[] = "'{$sKey}' is required, a non-empty string";
			}
		}
		if (!isset($aData['token']) || !in_array($aData['token'], self::TOKENS, true)) {
			$aErrors[] = "'token' is required, one of ".implode(', ', self::TOKENS);
		}
		if (!isset($aData['maxCalls']) || !is_int($aData['maxCalls']) || $aData['maxCalls'] < 1) {
			$aErrors[] = "'maxCalls' is required, an integer of at least 1";
		}
		if (($aData['id'] ?? $sId) !== $sId) {
			$aErrors[] = "'id' must be the file name ({$sId})";
		}
		foreach (['allowedTools', 'firstCallIn'] as $sKey) {
			if (isset($aData[$sKey]) && (!is_array($aData[$sKey]) || array_filter($aData[$sKey], 'is_string') !== $aData[$sKey])) {
				$aErrors[] = "'{$sKey}' is a list of tool names";
			}
		}
		foreach (['mustCall', 'forbid'] as $sKey) {
			foreach ($aData[$sKey] ?? [] as $i => $aMatcher) {
				if (!self::IsMatcher($aMatcher)) {
					$aErrors[] = "'{$sKey}[{$i}]' is not a matcher";
				}
			}
		}
		foreach ($aData['before'] ?? [] as $i => $aRule) {
			if (!is_array($aRule) || !self::IsMatcher($aRule['commit'] ?? null) || !self::IsMatcher($aRule['after'] ?? null)) {
				$aErrors[] = "'before[{$i}]' needs a 'commit' and an 'after' matcher";
			}
		}
		if ($aErrors !== []) {
			throw new \InvalidArgumentException("scenario {$sId}: ".implode('; ', $aErrors));
		}

		return new self($aData + ['id' => $sId]);
	}

	public static function IsMatcher(mixed $mValue): bool
	{
		return is_array($mValue)
			&& isset($mValue['tool']) && is_string($mValue['tool'])
			&& (!isset($mValue['arguments']) || is_array($mValue['arguments']));
	}

	public function id(): string
	{
		return (string)$this->aData['id'];
	}

	public function prompt(): string
	{
		return (string)$this->aData['prompt'];
	}

	public function maxCalls(): int
	{
		return (int)$this->aData['maxCalls'];
	}

	public function token(): string
	{
		return (string)$this->aData['token'];
	}
}
