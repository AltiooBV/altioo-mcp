<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

/**
 * What the runner needs from a model: given the tools the server offers and
 * the conversation so far, the next assistant turn.
 *
 * @internal
 */
interface Agent
{
	/**
	 * @param array<int, array<string, mixed>> $aTools    As tools/list answered.
	 * @param string                           $sInstructions The server's instructions, passed as the system prompt the way a client does.
	 * @param array<int, array<string, mixed>> $aMessages Anthropic-format messages.
	 *
	 * @return array{text: string, calls: array<int, array{id: string, name: string, input: array<string, mixed>}>, stop: string, inputTokens: int, outputTokens: int}
	 */
	public function turn(array $aTools, string $sInstructions, array $aMessages): array;
}
