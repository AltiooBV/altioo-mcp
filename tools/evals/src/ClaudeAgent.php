<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

use Anthropic\Client;

/**
 * A Claude model through the official PHP SDK. The one class here that needs
 * `composer install` in tools/evals/, and the one that spends money.
 *
 * The key is read by the SDK from ANTHROPIC_API_KEY (or its other credential
 * sources) and is never handled, printed or stored here.
 *
 * @internal
 */
final class ClaudeAgent implements Agent
{
	private readonly Client $oClient;

	public function __construct(private readonly string $sModel)
	{
		$this->oClient = new Client();
	}

	#[\Override]
	public function turn(array $aTools, string $sInstructions, array $aMessages): array
	{
		$aToolParams = [];
		foreach ($aTools as $aTool) {
			$aToolParams[] = [
				'name'        => $aTool['name'],
				'description' => $aTool['description'] ?? '',
				'inputSchema' => $aTool['inputSchema'] ?? ['type' => 'object'],
			];
		}

		$oMessage = $this->oClient->messages->create(
			model: $this->sModel,
			maxTokens: 8000,
			system: $sInstructions,
			tools: $aToolParams,
			messages: $aMessages,
		);

		$sText = '';
		$aCalls = [];
		foreach ($oMessage->content as $oBlock) {
			if ($oBlock->type === 'text') {
				$sText .= $oBlock->text;
			} elseif ($oBlock->type === 'tool_use') {
				$aCalls[] = ['id' => $oBlock->id, 'name' => $oBlock->name, 'input' => (array)$oBlock->input];
			}
		}

		return [
			'text'         => $sText,
			'calls'        => $aCalls,
			'stop'         => (string)($oMessage->stopReason ?? ''),
			'inputTokens'  => (int)($oMessage->usage->inputTokens ?? 0),
			'outputTokens' => (int)($oMessage->usage->outputTokens ?? 0),
		];
	}
}
