<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\OAuthResource;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Combodo\iTop\Application\WebPage\JsonPage;
use Psr\Http\Message\ResponseInterface;

/**
 * What the endpoint writes to the wire: the answer a request gets, the
 * answer to a refusal, the answer to a CORS preflight, the CORS headers all
 * three carry and the challenge a 401 is sent with.
 *
 * The response half of what MCPHttp and MCPController used to do together; the
 * request half, which runs before anything is answered, stays in Helper\MCPHttp.
 *
 * @internal
 * @since 2.0.0
 */
final class MCPResponse
{
	/** Named in the challenge so a human reading a 401 knows what refused them. */
	private const REALM = 'iTop MCP';

	/**
	 * The WWW-Authenticate challenge that goes with a 401.
	 *
	 * A 401 with no challenge tells a client that it failed, not what it
	 * failed at, and RFC 9110 requires one on the status anyway. The
	 * resource_metadata parameter is RFC 9728: it points a client at the
	 * document describing which authorization server protects this resource,
	 * which is what turns "the connect button does nothing" into a working
	 * OAuth flow.
	 *
	 * This module implements no OAuth and does not intend to - the deployment
	 * pattern is an OIDC-terminating proxy in front of iTop - but the proxy
	 * cannot inject the parameter into a 401 it never sees. Advertising the
	 * URL of a document someone else serves is the whole of what is needed
	 * here, so it is a setting rather than an implementation.
	 *
	 * The error is RFC 6750's, and only for a request that presented a token:
	 * invalid_token is what tells a client to refresh or connect again rather
	 * than retry, and a request that presented nothing gets no error code at
	 * all (RFC 6750 section 3.1), only the challenge.
	 *
	 * @param string|null $sResourceMetadataUrl As configured, or null when nothing is.
	 * @param string|null $sError An RFC 6750 error code, or null for none.
	 * @since 1.0.0
	 */
	public static function BearerChallenge(?string $sResourceMetadataUrl, ?string $sError = null): string
	{
		$sChallenge = 'Bearer realm="'.self::REALM.'"';

		// A code is a fixed word this module chose; anything else is a bug,
		// and is left out rather than put in a header.
		if ($sError !== null && preg_match('/^[a-z_]+$/D', $sError) === 1) {
			$sChallenge .= ', error="'.$sError.'"';
		}

		$sUrl = self::SafeMetadataUrl($sResourceMetadataUrl);
		if ($sUrl !== null) {
			$sChallenge .= ', resource_metadata="'.$sUrl.'"';
		}

		return $sChallenge;
	}

	/**
	 * The configured URL, if it is one that can go in a header at all.
	 *
	 * It comes from the instance configuration rather than from the request,
	 * so this is not defending against an attacker - it is making a typo in
	 * config-itop.php produce no parameter rather than a split header.
	 */
	private static function SafeMetadataUrl(?string $sUrl): ?string
	{
		if ($sUrl === null) {
			return null;
		}

		$sUrl = trim($sUrl);
		if ($sUrl === '') {
			return null;
		}

		if (!preg_match('#^https?://[^\s"\'\\\\\x00-\x1F\x7F]+$#', $sUrl)) {
			return null;
		}

		return $sUrl;
	}

	/** What every refused call says on its audit row, whatever the tool said. @since 1.3.0 */
	public const TOOL_REFUSED_MESSAGE = 'The tool refused the call';

	/**
	 * Answers a preflight, and tells the browser nothing when the origin is not
	 * one the operator allow-listed.
	 *
	 * A 204 either way: refusing the preflight itself would say which origins
	 * are configured, and the browser blocks the real request just as firmly
	 * when the headers are simply absent.
	 */
	public static function EmitPreflight(): void
	{
		http_response_code(204);

		foreach (self::corsHeaders() as $sName => $sValue) {
			header($sName.': '.$sValue, true);
		}
	}

	public static function EmitResponse(ResponseInterface $response): MCPResult
	{
		foreach ($response->getHeaders() as $name => $values) {
			foreach ($values as $value) {
				header(sprintf('%s: %s', $name, $value), false);
			}
		}

		foreach (self::corsHeaders() as $sName => $sValue) {
			header($sName.': '.$sValue, true);
		}

		http_response_code($response->getStatusCode());

		$body = $response->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}
		$sBody = (string)$body;

		// Inspect before emitting — no double decode
		$oResult = self::buildResultFromBody($response, $sBody);
		$oResult->responseBytes = strlen($sBody);

		echo $sBody;

		return $oResult;
	}

	/**
	 * The text parts of a tool result's content, as one string.
	 *
	 * @param mixed $mContent The decoded `content` of the result.
	 */
	private static function textOfContent(mixed $mContent): ?string
	{
		if (!is_array($mContent)) {
			return null;
		}
		$aTexts = [];
		foreach ($mContent as $aPart) {
			if (is_array($aPart) && ($aPart['type'] ?? null) === 'text' && is_string($aPart['text'] ?? null)) {
				$aTexts[] = $aPart['text'];
			}
		}

		return $aTexts === [] ? null : implode(' ', $aTexts);
	}

	private static function buildResultFromBody(ResponseInterface $response, string $sBody): MCPResult
	{
		if (!str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
			return new MCPResult(MCPResult::OK, 'Response emitted successfully');
		}

		$aDecoded = json_decode($sBody, true);
		if (isset($aDecoded['error'])) {
			return new MCPResult($aDecoded['error']['code'] ?? MCPResult::INTERNAL_ERROR, $aDecoded['error']['message'] ?? 'Unknown MCP error');
		}

		// A tool that refuses a call - an object the caller may not change, a class
		// they may not read - answers it with a result flagged isError, which the
		// protocol sends as HTTP 200 and a JSON-RPC success. The caller is meant to
		// read it as an answer; the audit trail is not the caller. A row saying
		// "success" for a call that was refused is the one an operator cannot afford,
		// so it is recorded as an error. The message is fixed. The tool's own text is
		// written for the caller and may quote what the caller sent or the value that
		// was refused, and this row does not record what objects contain: it is kept
		// apart, and written only at log_mcp_level => 'debug'.
		if (is_array($aDecoded) && ($aDecoded['result']['isError'] ?? false) === true) {
			$oRefused = new MCPResult(MCPResult::TOOL_REFUSED, self::TOOL_REFUSED_MESSAGE);
			$oRefused->toolText = self::textOfContent($aDecoded['result']['content'] ?? null);

			return $oRefused;
		}

		return new MCPResult(MCPResult::OK, 'Response emitted successfully');
	}

	// The following methods are for handling exceptions and logging in a consistent way, ensuring that even if the MCP response cannot be emitted properly, we still return a valid JSON response with error details.
	public static function OutputJsonResultException(MCPResult $oResult): void
	{
		$sResponse = json_encode($oResult);
		if ($sResponse === false || is_null($sResponse)) {
			// The dump of the unencodable structure goes to the log, not the wire:
			// it is arbitrary internal state and may hold whatever the failed call
			// was carrying.
			$sReference = ErrorReport::NewReference();
			ErrorReport::Log(sprintf(
				'[%s] json encoding failed (%s). Response structure (print_r+bin2hex): %s',
				$sReference,
				json_last_error_msg(),
				bin2hex(print_r($oResult, true))
			));

			$oJsonIssue = new MCPResult();
			$oJsonIssue->code = MCPResult::INTERNAL_ERROR;
			$oJsonIssue->message = 'The MCP response could not be encoded. Server log reference: '.$sReference;
			$sResponse = json_encode($oJsonIssue);
		}

		$bUnauthorized = $oResult->code === MCPResult::UNAUTHORIZED;

		// A throw can also happen after emitResponse() has already flushed a
		// successful body, and a status set at that point is only noise.
		if (!headers_sent()) {
			http_response_code($oResult->httpStatus ?? ($bUnauthorized ? 401 : 500));
		}

		$oP = new JsonPage();
		self::addCorsHeader($oP);
		if ($bUnauthorized) {
			// A 401 with no challenge says "no" without saying what would have
			// worked. RFC 9110 requires one on this status; RFC 9728 is what a
			// client with a Connect button follows to find the authorization
			// server, when the operator has put one in front of iTop.
			$oP->add_header('WWW-Authenticate: '.self::BearerChallenge(OAuthResource::MetadataUrl(), $oResult->challengeError));
		}
		$oP->SetData(json_decode($sResponse, true));
		$oP->SetOutputDataOnly(true);
		$oP->Output();
	}

	/**
	 * Echoes the request Origin only when it is explicitly allow-listed.
	 *
	 * A wildcard here would let any site read the authenticated responses of a
	 * logged-in user's browser session. Default is an empty list, i.e. no CORS
	 * header at all, which is correct for a token-authenticated endpoint called
	 * from a backend.
	 *
	 * The same set answers the preflight, the successful response and the error
	 * response. Sending them on failures only - which is what happens when the
	 * success path forwards the SDK's headers and nothing else - lets a browser
	 * client read every error and no result.
	 *
	 * @return array<string, string> Header name => value, empty when the origin is not allowed.
	 */
	private static function corsHeaders(): array
	{
		$sOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ($sOrigin === '') {
			return [];
		}

		if (!in_array($sOrigin, Settings::GetAllowedOrigins(), true)) {
			return [];
		}

		return [
			'Access-Control-Allow-Origin' => $sOrigin,
			// Anything but Origin-independent: a cache that missed this would
			// serve one origin's response to another.
			'Vary'                        => 'Origin',
			// Streamable HTTP also defines GET, for opening a stream the
			// client can resume. The SDK does not answer it - it matches
			// OPTIONS, POST and DELETE and refuses everything else with 405
			// and 'Allow: POST, DELETE, OPTIONS' - so advertising GET here
			// would promise a browser something the transport refuses.
			'Access-Control-Allow-Methods'  => 'POST, DELETE, OPTIONS',
			// Authorization and Auth-Token carry the credential, Mcp-Session-Id
			// and MCP-Protocol-Version are set by the client on every call, and
			// a browser sends none of them without being told they are allowed.
			// Last-Event-ID belongs to stream resumption, which is the GET
			// above, so it is not among them.
			//
			// Mcp-Method and Mcp-Name are the 2026-07-28 revision's mirror of
			// the body (SEP-2243). The SDK validates them by default and
			// refuses the call with -32020 when one that applies is missing, so
			// a browser client on that revision cannot work without them here.
			// Mcp-Param-* mirrors the arguments and is deliberately not listed:
			// the names are per-tool, CORS has no prefix wildcard, and a
			// wildcard entry is ignored on a credentialed request anyway. The
			// SDK treats those as optional - a call that omits them is served -
			// so what a browser loses by not being able to send them is the
			// mirror, not the call.
			'Access-Control-Allow-Headers'  => 'Content-Type, Accept, Authorization, Auth-Token, Mcp-Session-Id, MCP-Protocol-Version, Mcp-Method, Mcp-Name',
			// Headers are invisible to fetch() unless exposed, and a client that
			// cannot read Mcp-Session-Id cannot make a second call.
			'Access-Control-Expose-Headers' => 'Mcp-Session-Id, WWW-Authenticate',
			'Access-Control-Max-Age'        => '600',
		];
	}

	private static function addCorsHeader(JsonPage $oP): void
	{
		foreach (self::corsHeaders() as $sName => $sValue) {
			$oP->add_header($sName.': '.$sValue);
		}
	}
}
