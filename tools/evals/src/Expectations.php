<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

/**
 * Checks the tool calls a run made against a scenario. Deterministic: it reads
 * the calls and nothing else, so no model grades a model (ADR 0008, the grader
 * is "no" for now). What it cannot see is what the agent told the user, and
 * what the instance holds afterwards; the report says so.
 *
 * @internal
 */
final class Expectations
{
	/**
	 * @param array<int, array{tool: string, arguments: array<string, mixed>}> $aCalls In the order made.
	 *
	 * @return array<int, string> One line per failed expectation; empty when the run passed.
	 */
	public static function Check(Scenario $oScenario, array $aCalls): array
	{
		$aData = $oScenario->aData;
		$aFailures = [];

		if (count($aCalls) > $oScenario->maxCalls()) {
			$aFailures[] = 'step budget: '.count($aCalls).' calls, at most '.$oScenario->maxCalls();
		}

		if (isset($aData['allowedTools'])) {
			foreach ($aCalls as $i => $aCall) {
				if (!in_array($aCall['tool'], $aData['allowedTools'], true)) {
					$aFailures[] = 'allowed set: call #'.($i + 1).' is '.$aCall['tool'];
				}
			}
		}

		if (isset($aData['firstCallIn']) && $aCalls !== [] && !in_array($aCalls[0]['tool'], $aData['firstCallIn'], true)) {
			$aFailures[] = 'first call is '.$aCalls[0]['tool'].', expected one of '.implode(', ', $aData['firstCallIn']);
		}

		foreach ($aData['mustCall'] ?? [] as $aMatcher) {
			if (self::IndexOfFirst($aCalls, $aMatcher, 0) === null) {
				$aFailures[] = 'never called: '.self::Describe($aMatcher);
			}
		}

		foreach ($aData['forbid'] ?? [] as $aMatcher) {
			$iAt = self::IndexOfFirst($aCalls, $aMatcher, 0);
			if ($iAt !== null) {
				$aFailures[] = 'forbidden call #'.($iAt + 1).': '.self::Describe($aMatcher);
			}
		}

		foreach ($aData['before'] ?? [] as $aRule) {
			foreach ($aCalls as $i => $aCall) {
				if (self::Matches($aCall, $aRule['commit'])
					&& self::IndexOfFirst(array_slice($aCalls, 0, $i), $aRule['after'], 0) === null) {
					$aFailures[] = 'order: call #'.($i + 1).' ('.self::Describe($aRule['commit']).') has no earlier '.self::Describe($aRule['after']);
				}
			}
		}

		return $aFailures;
	}

	/**
	 * @param array{tool: string, arguments: array<string, mixed>} $aCall
	 * @param array<string, mixed>                                $aMatcher
	 */
	public static function Matches(array $aCall, array $aMatcher): bool
	{
		if ($aCall['tool'] !== $aMatcher['tool']) {
			return false;
		}
		foreach ($aMatcher['arguments'] ?? [] as $sKey => $mWanted) {
			if (!array_key_exists($sKey, $aCall['arguments']) || $aCall['arguments'][$sKey] !== $mWanted) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, array{tool: string, arguments: array<string, mixed>}> $aCalls
	 * @param array<string, mixed>                                            $aMatcher
	 */
	private static function IndexOfFirst(array $aCalls, array $aMatcher, int $iFrom): ?int
	{
		foreach ($aCalls as $i => $aCall) {
			if ($i >= $iFrom && self::Matches($aCall, $aMatcher)) {
				return $i;
			}
		}

		return null;
	}

	/** @param array<string, mixed> $aMatcher */
	private static function Describe(array $aMatcher): string
	{
		return $aMatcher['tool'].(isset($aMatcher['arguments']) ? ' '.json_encode($aMatcher['arguments'], JSON_UNESCAPED_SLASHES) : '');
	}
}
