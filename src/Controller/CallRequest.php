<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Mcp\Server\Stateless\RequestMeta;
use Psr\Http\Message\RequestInterface;

/**
 * What the request said, as the audit row records it: the JSON-RPC method,
 * the name of the tool, resource or prompt, the parameters, and the client
 * the caller declares itself to be.
 *
 * Read from the body once the call is answered. Everything here is the caller's
 * own text, so none of it is trusted; CallLog::AuditLabel() makes it fit to store.
 *
 * @internal
 * @since 2.0.0
 */
final class CallRequest
{
	public static function GetMCPInfoFromRequest(RequestInterface $request, MCPResult $oResult): MCPResult
	{
		// Extract MCP context from the request body for audit logging
		$sMcpMethod     = null;
		$sMcpName       = null;
		$sClient        = null;
		$sRequestParams = null;

		$body = $request->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}

		$sBody = $body->getContents();
		if ($sBody !== '') {
			$aPayload = json_decode($sBody, true);
			if (is_array($aPayload)) {
				$sMcpMethod     = $aPayload['method'] ?? null;
				$sRequestParams = isset($aPayload['params']) ? json_encode($aPayload['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

				// Extract tool/resource/prompt name depending on method
				$aParams = $aPayload['params'] ?? [];
				$sMcpName = match ($sMcpMethod) {
					MCPMethod::TOOLS_CALL     => $aParams['name'] ?? null,
					MCPMethod::RESOURCES_READ => $aParams['uri'] ?? null,
					MCPMethod::PROMPTS_GET    => $aParams['name'] ?? null,
					// The one row that says a client connected is worth naming
					// the client on. clientInfo is what the caller declares
					// about itself and nothing verifies it, so it identifies a
					// well-behaved integration rather than authenticating
					// anyone - which is what an operator reading the trail is
					// after: which of these connected, and when did it stop.
					MCPMethod::INITIALIZE     => self::clientDescription(self::clientInfoOf($aParams)),
					default                              => null,
				};
				$sMcpName = CallLog::AuditLabel($sMcpName);
				// On every row, so a client that never sends initialize (protocol
				// 2026-07-28) is still named - by the same text, with the same
				// standing: declared by the caller, verified by nobody.
				$sClient = CallLog::AuditLabel(self::clientDescription(self::clientInfoOf($aParams)));
			}
		}

		$oResult->mcpMethod     = $sMcpMethod;
		$oResult->mcpName       = $sMcpName;
		$oResult->client        = $sClient;
		$oResult->requestParams = $sRequestParams;
		return $oResult;
	}

	/**
	 * Where a request declares its client: `clientInfo` on an initialize
	 * request, else the `_meta` entry of the same name that a client of
	 * protocol 2026-07-28 carries on every request (a SHOULD, and such a
	 * client never sends initialize). A client of the older protocol that
	 * sent neither has none, and the endpoint, being stateless, does not
	 * remember it from an earlier call.
	 *
	 * @param mixed $mParams The params of the request, as decoded.
	 *
	 * @return mixed The declared value, whatever it is: it is the caller's.
	 */
	private static function clientInfoOf(mixed $mParams): mixed
	{
		if (!is_array($mParams)) {
			return null;
		}
		if (is_array($mParams['clientInfo'] ?? null)) {
			return $mParams['clientInfo'];
		}
		$aMeta = $mParams['_meta'] ?? null;

		return is_array($aMeta) ? ($aMeta[RequestMeta::CLIENT_INFO] ?? null) : null;
	}

	/**
	 * How a client describes itself: its name, then its version.
	 *
	 * Free text written by the caller, so it is never treated as anything but
	 * a label; CallLog::AuditLabel() makes it fit to store.
	 *
	 * @param mixed $mClient The declared `clientInfo`, of whatever shape.
	 */
	private static function clientDescription(mixed $mClient): ?string
	{
		if (!is_array($mClient)) {
			return null;
		}

		$sName    = is_string($mClient['name'] ?? null) ? trim($mClient['name']) : '';
		$sVersion = is_string($mClient['version'] ?? null) ? trim($mClient['version']) : '';

		if ($sName === '') {
			return null;
		}

		return $sVersion === '' ? $sName : $sName.' '.$sVersion;
	}
}
