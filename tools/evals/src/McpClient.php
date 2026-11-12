<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\McpEvals;

/**
 * JSON-RPC over HTTP to the endpoint, and nothing else.
 *
 * Not mcp/sdk's client, on purpose (ADR 0008): an SDK bump would then move both
 * ends of the connection and could hide what it broke. The bearer token is read
 * by the caller from the environment and never written anywhere here.
 *
 * @internal
 */
final class McpClient
{
	private ?string $sSession = null;

	private int $iId = 0;

	public function __construct(private readonly string $sUrl, private readonly string $sToken)
	{
	}

	/** @return array<string, mixed> The initialize result. */
	public function initialize(): array
	{
		$aResult = $this->call('initialize', [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new \stdClass(),
			'clientInfo'      => ['name' => 'altioo-mcp-evals', 'version' => '1'],
		]);

		return $aResult;
	}

	/** @return array<int, array<string, mixed>> */
	public function listTools(): array
	{
		return $this->call('tools/list', new \stdClass())['tools'] ?? [];
	}

	/**
	 * @param array<string, mixed> $aArguments
	 *
	 * @return array{text: string, isError: bool}
	 */
	public function callTool(string $sName, array $aArguments): array
	{
		try {
			$aResult = $this->call('tools/call', ['name' => $sName, 'arguments' => $aArguments === [] ? new \stdClass() : $aArguments]);
		} catch (\RuntimeException $e) {
			return ['text' => $e->getMessage(), 'isError' => true];
		}
		$aText = [];
		foreach ($aResult['content'] ?? [] as $aBlock) {
			$aText[] = (string)($aBlock['text'] ?? json_encode($aBlock));
		}

		return ['text' => implode("\n", $aText), 'isError' => (bool)($aResult['isError'] ?? false)];
	}

	/**
	 * @param array<string, mixed>|\stdClass $mParams
	 *
	 * @return array<string, mixed>
	 */
	private function call(string $sMethod, array|\stdClass $mParams): array
	{
		$aHeaders = [
			'Authorization: Bearer '.$this->sToken,
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
			'MCP-Protocol-Version: 2025-06-18',
		];
		if ($this->sSession !== null && $this->sSession !== '') {
			$aHeaders[] = 'Mcp-Session-Id: '.$this->sSession;
		}
		$rContext = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => implode("\r\n", $aHeaders),
			'content'       => json_encode(['jsonrpc' => '2.0', 'id' => ++$this->iId, 'method' => $sMethod, 'params' => $mParams]),
			'ignore_errors' => true,
			'timeout'       => 120,
		]]);
		$sBody = @file_get_contents($this->sUrl, false, $rContext);
		if ($sBody === false) {
			throw new \RuntimeException("no answer from the endpoint for {$sMethod}");
		}
		foreach ($http_response_header ?? [] as $sLine) {
			if (stripos($sLine, 'mcp-session-id:') === 0) {
				$this->sSession = trim(substr($sLine, 15));
			}
		}

		$aDecoded = self::DecodeBody($sBody);
		if (isset($aDecoded['error'])) {
			throw new \RuntimeException($sMethod.': '.json_encode($aDecoded['error'], JSON_UNESCAPED_SLASHES));
		}

		return $aDecoded['result'] ?? [];
	}

	/**
	 * A JSON body, or a server-sent event stream carrying one `data:` line.
	 *
	 * @return array<string, mixed>
	 */
	public static function DecodeBody(string $sBody): array
	{
		$sBody = trim($sBody);
		if (str_starts_with($sBody, 'event:') || str_starts_with($sBody, 'data:')) {
			foreach (preg_split('/\R/', $sBody) ?: [] as $sLine) {
				if (str_starts_with($sLine, 'data:')) {
					$sBody = trim(substr($sLine, 5));
					break;
				}
			}
		}
		$aDecoded = json_decode($sBody, true);
		if (!is_array($aDecoded)) {
			throw new \RuntimeException('the endpoint answered something that is not JSON: '.substr($sBody, 0, 120));
		}

		return $aDecoded;
	}
}
