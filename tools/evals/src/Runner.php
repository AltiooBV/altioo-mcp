<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

/**
 * Plays one scenario: the prompt goes to the model, each tool call it makes is
 * made against the endpoint, the answer goes back, until it stops or the step
 * budget (plus one, so that going over is seen rather than cut) is spent.
 *
 * @internal
 */
final class Runner
{
	public function __construct(private readonly McpClient $oMcp, private readonly Agent $oAgent)
	{
	}

	/**
	 * @return array{id: string, passed: bool, failures: array<int, string>, calls: array<int, array<string, mixed>>, finalText: string, inputTokens: int, outputTokens: int, seconds: float}
	 */
	public function run(Scenario $oScenario): array
	{
		$fStarted = microtime(true);
		$aInit = $this->oMcp->initialize();
		$aTools = $this->oMcp->listTools();
		$sInstructions = (string)($aInit['instructions'] ?? '');

		$aMessages = [['role' => 'user', 'content' => $oScenario->prompt()]];
		$aCalls = [];
		$aRecord = [];
		$iIn = $iOut = 0;
		$sFinal = '';

		for ($iStep = 0; $iStep <= $oScenario->maxCalls() + 1; $iStep++) {
			$aTurn = $this->oAgent->turn($aTools, $sInstructions, $aMessages);
			$iIn += $aTurn['inputTokens'];
			$iOut += $aTurn['outputTokens'];
			$sFinal = $aTurn['text'];
			if ($aTurn['calls'] === []) {
				break;
			}

			$aAssistant = $aTurn['text'] === '' ? [] : [['type' => 'text', 'text' => $aTurn['text']]];
			$aResults = [];
			foreach ($aTurn['calls'] as $aCall) {
				$aAssistant[] = ['type' => 'tool_use', 'id' => $aCall['id'], 'name' => $aCall['name'], 'input' => $aCall['input'] === [] ? new \stdClass() : $aCall['input']];
				$aCalls[] = ['tool' => $aCall['name'], 'arguments' => $aCall['input']];
				$aAnswer = $this->oMcp->callTool($aCall['name'], $aCall['input']);
				$aRecord[] = ['tool' => $aCall['name'], 'arguments' => $aCall['input'], 'isError' => $aAnswer['isError'], 'answer' => mb_substr($aAnswer['text'], 0, 600)];
				$aResults[] = ['type' => 'tool_result', 'toolUseID' => $aCall['id'], 'content' => $aAnswer['text'], 'isError' => $aAnswer['isError']];
			}
			$aMessages[] = ['role' => 'assistant', 'content' => $aAssistant];
			$aMessages[] = ['role' => 'user', 'content' => $aResults];
		}

		$aFailures = Expectations::Check($oScenario, $aCalls);

		return [
			'id'           => $oScenario->id(),
			'passed'       => $aFailures === [],
			'failures'     => $aFailures,
			'calls'        => $aRecord,
			'finalText'    => $sFinal,
			'inputTokens'  => $iIn,
			'outputTokens' => $iOut,
			'seconds'      => round(microtime(true) - $fStarted, 1),
		];
	}
}
