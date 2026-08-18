<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException;
use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use Altioo\iTop\Extension\MCP\Models\MCPResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Combodo\iTop\Application\WebPage\JsonPage;
use AltiooEventMCPService;
use ExecutionKPI;
use LoginWebPage;
use Throwable;
use UserRights;
use ContextTag;
use utils;
use MetaModel;

/**
 * @since 1.0.0
 */
final class MCPController
{
	public static function handleRequest(): void
	{
		new MCPHelper();

		// Before anything that needs a user: a preflight carries no credential
		// by definition, so answering it after DoLogin() would answer 401 and
		// the browser would never send the real request.
		if (self::isPreflight()) {
			self::emitPreflight();

			return;
		}

		// One tag per MCP scope this instance declares. iTop honours a token
		// scope only when a tag of the same name is on the stack, so a scope
		// nobody pushes is a token that cannot log in - including the ones a
		// pack adds to the token classes in its own datamodel. The objects
		// have to outlive the login: ContextTag pops on destruct.
		$aCtx = [];
		foreach (TokenScopes::DeclaredContextTags() as $sTag) {
			$aCtx[] = new ContextTag($sTag);
		}
		$oKPI = new ExecutionKPI();
		$fStarted = microtime(true);

		try {
			$oKPI->ComputeAndReport('Data model loaded');

			// Both of these run before ResetSession(), which is the point of
			// them: a page on any website could otherwise make a logged-in
			// user's browser call this URL and end their console session - no
			// credential needed, and nothing in the audit trail that looks
			// like an attack. The third guard on that path is the credential
			// check below, which is what makes the reset conditional at all.
			self::rejectUnlessHostIsServed();
			self::rejectUnlessBodyIsJson();

			MCPHttp::PromoteBearerToAuthToken();
			self::rejectUnlessACredentialWasPresented();

			LoginWebPage::ResetSession();
			$iRet = LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_RETURN);
			$oKPI->ComputeAndReport('User login');

			if ($iRet === LoginWebPage::EXIT_CODE_OK && self::isMCPAccessRestricted() && !self::userHasMCPProfile()) {
				$iRet = LoginWebPage::EXIT_CODE_NOTAUTHORIZED;
			}

			if ($iRet !== LoginWebPage::EXIT_CODE_OK) {
				throw self::createAuthException($iRet);
			}

			// The scopes are the last thing that needs the raw credential, so
			// they are read here and the credential dropped immediately after.
			// What follows builds a PSR-7 request out of $_SERVER, and a token
			// still sitting there would be copied into its server parameters
			// and live for the rest of the call.
			$oPolicy = MCPService::AccessPolicyOfCurrentRequest();
			MCPHttp::ForgetAuthToken();

			$oKPI->ComputeAndReport('Parameters validated');
			$aRequestResponse = MCPService::run($oPolicy);
			// Emit response and extract info for logging
			$oResult = self::emitResponse($aRequestResponse['response']);
			$oKPI->ComputeAndReport('Operation finished');
			$oResult = self::getMCPInfoFromRequest($aRequestResponse['request'], $oResult);
		} catch (Throwable $e) {
			// Throwable, not Exception: this is the single public entry point, and a
			// TypeError or a missing class here would otherwise escape uncaught -
			// producing a bare 500 with no audit entry.
			$oResult = self::buildErrorResult($e);
			$oResult->mcpMethod = MCPHelper::MCP_METHOD_EXCEPTION;
			$oKPI->ComputeAndReport('Exception catched');
			self::outputJsonResultException($oResult);
		}

		$oResult->durationMs = (int)round((microtime(true) - $fStarted) * 1000);

		self::logIfConfigured($oResult);
	}

	/**
	 * A CORS preflight, which is the one request that reaches this endpoint
	 * without a credential and must still be answered.
	 */
	private static function isPreflight(): bool
	{
		return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
			&& ($_SERVER['HTTP_ORIGIN'] ?? '') !== '';
	}

	/**
	 * Answers a preflight, and tells the browser nothing when the origin is not
	 * one the operator allow-listed.
	 *
	 * A 204 either way: refusing the preflight itself would say which origins
	 * are configured, and the browser blocks the real request just as firmly
	 * when the headers are simply absent.
	 */
	private static function emitPreflight(): void
	{
		http_response_code(204);

		foreach (self::corsHeaders() as $sName => $sValue) {
			header($sName.': '.$sValue, true);
		}
	}

	/**
	 * Refuses a request that arrived under a hostname this instance does not
	 * serve.
	 *
	 * The SDK applies the same rule, but it applies it inside
	 * StreamableHttpTransport - which this endpoint only reaches after
	 * resetting the session and authenticating. Deciding it here as well is
	 * what makes the check worth anything: the two read the same allow-list, so
	 * they cannot disagree, and this one runs before anything has a side
	 * effect.
	 *
	 * @throws MCPRequestRejectedException
	 */
	private static function rejectUnlessHostIsServed(): void
	{
		$sOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
		$sHost   = $_SERVER['HTTP_HOST'] ?? null;

		if (MCPHttp::IsAllowedHost($sOrigin, $sHost, MCPHelper::GetAllowedHosts())) {
			return;
		}

		// The name that was refused goes to the log, not to the caller: an
		// operator whose endpoint answers 403 needs to see it, and a caller
		// probing for the configured hostnames does not.
		MCPHelper::LogError(sprintf(
			"Refused an MCP request: neither its Origin (%s) nor its Host (%s) is in '%s'. "
			.'Set that module parameter to the hostname this instance is served under.',
			is_string($sOrigin) && $sOrigin !== '' ? $sOrigin : '-',
			is_string($sHost) && $sHost !== '' ? $sHost : '-',
			MCPHelper::MODULE_SETTING_ALLOWED_HOSTS
		));

		throw new MCPRequestRejectedException(
			'This host is not served by the MCP endpoint.',
			MCPRequestRejectedException::HTTP_FORBIDDEN
		);
	}

	/**
	 * Refuses a POST that does not announce a JSON body.
	 *
	 * A cross-origin fetch() is preflighted unless its Content-Type is one of
	 * three the browser considers safe, none of which is application/json.
	 * Requiring JSON therefore means every cross-origin call is preceded by an
	 * OPTIONS this endpoint answers with nothing unless the origin is
	 * allow-listed - so the browser never sends the call at all. Without it the
	 * endpoint is reachable as a simple request, which is the whole CSRF class.
	 *
	 * GET and DELETE carry no body and are left alone.
	 *
	 * @throws MCPRequestRejectedException
	 */
	private static function rejectUnlessBodyIsJson(): void
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return;
		}

		if (MCPHttp::IsJsonMediaType($_SERVER['CONTENT_TYPE'] ?? null)) {
			return;
		}

		throw new MCPRequestRejectedException(
			'A POST to the MCP endpoint must carry Content-Type: '.MCPHttp::JSON_MEDIA_TYPE.'.',
			MCPRequestRejectedException::HTTP_UNSUPPORTED_MEDIA_TYPE
		);
	}

	/**
	 * Refuses a request that brought no credential of its own.
	 *
	 * This is the guard that lets ResetSession() be conditional, and both
	 * halves of it matter.
	 *
	 * The reset destroys whatever iTop session the browser is holding. It is
	 * unauthenticated, so without this an <img src> on any website is a logout
	 * for every console user who loads that page - a side effect no MCP client
	 * ever asked for, on a request that was never going to be served.
	 *
	 * Skipping the reset alone would be worse than leaving it: a credential-less
	 * request would then reach DoLogin() with the browser's iTop cookie intact
	 * and be authenticated by it, which is the whole endpoint reachable on
	 * ambient authority. So the answer is not to reset later but to refuse
	 * earlier - the outcome for such a request is the 401 it already got, minus
	 * the collateral damage.
	 *
	 * What counts as a credential is anything the caller or the webserver put
	 * there deliberately - see MCPHttp::CarriesACredential(). A cookie is not
	 * one: the browser attaches it without being asked, which is precisely what
	 * makes it useless as evidence that this request was meant.
	 *
	 * @throws MCPAuthException
	 */
	private static function rejectUnlessACredentialWasPresented(): void
	{
		if (MCPHttp::CarriesACredential()) {
			return;
		}

		throw new MCPAuthException(
			'The MCP endpoint requires a credential on every request; a browser session is not one.',
			MCPResult::UNAUTHORIZED
		);
	}

	private static function isMCPAccessRestricted(): bool
	{
		return utils::GetConfig()->GetModuleSetting(MCPHelper::MODULE_NAME, 'secure_mcp_services', true) === true;
	}

	private static function userHasMCPProfile(): bool
	{
		/** @var \User $oUser */
		$oUser = UserRights::GetUserObject();

		if (is_null($oUser)){
			return false;
		}

		foreach (self::GetAuthorizedProfiles($oUser) as $sProfile) {
			if (UserRights::HasProfile($sProfile, $oUser)) {
				return true;
			}
		}

		return false;
	}

	private static function GetAuthorizedProfiles(\User $oUser) : array
	{
		$aProfiles = utils::GetConfig()->GetModuleSetting(MCPHelper::MODULE_NAME, 'mcp_allowed_profiles', []);
		if (is_array($aProfiles)) {
			return $aProfiles;
		}

		$sType = gettype($aProfiles);
		MCPHelper::LogError("Itop configuration parameter 'mcp_allowed_profiles' should be an array instead of $sType");
		return [];
	}

	private static function createAuthException(int $iRet): MCPAuthException
	{
		switch ($iRet) {
			case LoginWebPage::EXIT_CODE_WRONGCREDENTIALS:
				return new MCPAuthException('Invalid login', MCPResult::UNAUTHORIZED);
			case LoginWebPage::EXIT_CODE_NOTAUTHORIZED:
				return new MCPAuthException('This user is not authorized to use the MCP services. (The profile MCP Services User is required to access the MCP services)', MCPResult::UNAUTHORIZED);
			default:
				return new MCPAuthException('Unknown authentication error (retCode='.$iRet.')', MCPResult::UNAUTHORIZED);
		}
	}

	/**
	 * Turns a throwable into a response body that says nothing the caller has no
	 * business knowing.
	 *
	 * Only MCPAuthException carries a message this module wrote; everything else
	 * is answered generically and correlated to the server log by a reference,
	 * because iTop exception messages routinely embed SQL, table and class names.
	 */
	private static function buildErrorResult(Throwable $e): MCPResult
	{
		if ($e instanceof MCPAuthException) {
			return new MCPResult($e->getCode(), $e->getMessage());
		}

		if ($e instanceof MCPRequestRejectedException) {
			$oResult = new MCPResult(MCPResult::REQUEST_REJECTED, $e->getMessage());
			$oResult->httpStatus = $e->httpStatus();

			return $oResult;
		}

		$sReference = MCPHelper::NewErrorReference();
		MCPHelper::LogError(sprintf(
			'[%s] Unhandled %s: %s in %s:%d',
			$sReference,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		$oResult = new MCPResult(
			MCPResult::INTERNAL_ERROR,
			'The MCP request could not be completed. Server log reference: '.$sReference
		);
		$oResult->errorReference = $sReference;

		return $oResult;
	}

	private static function emitResponse(ResponseInterface $response): MCPResult
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

	private static function buildResultFromBody(ResponseInterface $response, string $sBody): MCPResult
	{
		if (!str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
			return new MCPResult(MCPResult::OK, 'Response emitted successfully');
		}

		$aDecoded = json_decode($sBody, true);
		if (isset($aDecoded['error'])) {
			return new MCPResult($aDecoded['error']['code'] ?? MCPResult::INTERNAL_ERROR, $aDecoded['error']['message'] ?? 'Unknown MCP error');
		}

		return new MCPResult(MCPResult::OK, 'Response emitted successfully');
	}

	private static function getMCPInfoFromRequest(RequestInterface $request, MCPResult $oResult): MCPResult
	{
		// Extract MCP context from the request body for audit logging
		$sMcpMethod     = null;
		$sMcpName       = null;
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
					'tools/call'      => $aParams['name'] ?? null,
					'resources/read'  => $aParams['uri'] ?? null,
					'prompts/get'     => $aParams['name'] ?? null,
					// The one row that says a client connected is worth naming
					// the client on. clientInfo is what the caller declares
					// about itself and nothing verifies it, so it identifies a
					// well-behaved integration rather than authenticating
					// anyone - which is what an operator reading the trail is
					// after: which of these connected, and when did it stop.
					MCPHelper::MCP_METHOD_INITIALIZE => self::clientDescription($aParams),
					default           => null,
				};
			}
		}

		$oResult->mcpMethod     = $sMcpMethod;
		$oResult->mcpName       = $sMcpName;
		$oResult->requestParams = $sRequestParams;
		return $oResult;
	}

	// The following methods are for handling exceptions and logging in a consistent way, ensuring that even if the MCP response cannot be emitted properly, we still return a valid JSON response with error details.
	private static function outputJsonResultException(MCPResult $oResult): void
	{
		$sResponse = json_encode($oResult);
		if ($sResponse === false || is_null($sResponse)) {
			// The dump of the unencodable structure goes to the log, not the wire:
			// it is arbitrary internal state and may hold whatever the failed call
			// was carrying.
			$sReference = MCPHelper::NewErrorReference();
			MCPHelper::LogError(sprintf(
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
			$oP->add_header('WWW-Authenticate: '.MCPHttp::BearerChallenge(MCPHelper::GetProtectedResourceMetadataUrl()));
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

		if (!in_array($sOrigin, MCPHelper::GetAllowedOrigins(), true)) {
			return [];
		}

		return [
			'Access-Control-Allow-Origin' => $sOrigin,
			// Anything but Origin-independent: a cache that missed this would
			// serve one origin's response to another.
			'Vary'                        => 'Origin',
			// Streamable HTTP is POST for calls, GET for a stream and DELETE to
			// end a session; the SDK answers all three.
			'Access-Control-Allow-Methods'  => 'POST, GET, DELETE, OPTIONS',
			// Authorization and Auth-Token carry the credential, Mcp-Session-Id
			// and MCP-Protocol-Version are set by the client on every call, and
			// a browser sends none of them without being told they are allowed.
			'Access-Control-Allow-Headers'  => 'Content-Type, Accept, Authorization, Auth-Token, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID',
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


	/**
	 * How an initialize request describes the client sending it.
	 *
	 * Free text written by the caller, so it is trimmed and cut to what the
	 * column holds and never treated as anything but a label.
	 *
	 * @param array<string, mixed> $aParams The params of the initialize request.
	 */
	private static function clientDescription(array $aParams): ?string
	{
		$aClient = $aParams['clientInfo'] ?? null;
		if (!is_array($aClient)) {
			return null;
		}

		$sName    = is_string($aClient['name'] ?? null) ? trim($aClient['name']) : '';
		$sVersion = is_string($aClient['version'] ?? null) ? trim($aClient['version']) : '';

		if ($sName === '') {
			return null;
		}

		return mb_substr($sVersion === '' ? $sName : $sName.' '.$sVersion, 0, 255);
	}

	/**
	 * Whether this row is the record that a client connected.
	 *
	 * Kept out of the "successes are not worth a row" rule that the default log
	 * level applies, and deliberately: a successful connection is precisely the
	 * one success an operator needs. Without it the trail answers what was
	 * called and never who arrived - so a token quietly in use by something
	 * nobody remembers issuing it to leaves no trace until it does something,
	 * and an integration that stopped connecting looks exactly like one that
	 * connected and had nothing to do.
	 *
	 * There is one of these per session rather than per call, so it costs a row
	 * per connection.
	 */
	private static function isConnection(string $sMethod): bool
	{
		return $sMethod === MCPHelper::MCP_METHOD_INITIALIZE;
	}

	private static function logIfConfigured(MCPResult $oResult): void
	{
		if (MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG, MCPHelper::DEFAULT_LOG_SETTING) !== true) {
			return;
		}

		// Only log actual MCP method calls, skip the rest of the handshake
		// (notifications/initialized, tools/list, etc.)
		$sMethod = $oResult->mcpMethod;
		if (utils::IsNullOrEmptyString($sMethod)) {
			return;
		}

		if (!in_array($sMethod, MCPHelper::GetAuditedMethods(), true)) {
			return;
		}
		// If the response is successful and the log level is set to error, skip logging to avoid filling the logs with successful calls
		if ($oResult->isSuccess()
			&& !self::isConnection($sMethod)
			&& MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_LEVEL) === MCPHelper::LOG_LEVEL_ERROR) {
			return;
		}

		try {
			$oLog = new AltiooEventMCPService();
			$oLog->SetTrim('userinfo', UserRights::GetUser());
			$oLog->Set('message', $oResult->message);
			$oLog->Set('mcp_method', $sMethod);
			$oLog->Set('mcp_name', $oResult->mcpName ?? '');
			$oLog->Set('status', $oResult->isSuccess() ? 'success' : 'error');
			// What the row could not answer before: how long the call took, how
			// much it sent back - the two numbers that tell a slow instance from
			// a client filling its context - and which log entry explains it.
			if ($oResult->durationMs !== null) {
				$oLog->Set('duration_ms', $oResult->durationMs);
			}
			if ($oResult->responseBytes !== null) {
				$oLog->Set('response_bytes', $oResult->responseBytes);
			}
			$oLog->Set('error_ref', $oResult->errorReference ?? '');
			// Log request parameters only for debug level to avoid filling the logs with too much data in case of errors
			if (MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_LEVEL, MCPHelper::DEFAULT_LOG_LEVEL) === MCPHelper::LOG_LEVEL_DEBUG) {
				$oLog->Set('request_params', self::truncate($oResult->requestParams, 65535));
			}
			$oLog->DBInsertNoReload();
		} catch (Throwable $e) {
			// Never let audit logging take down a request that already succeeded.
			MCPHelper::LogError('Failed to log AltiooEventMCPService: '.$e->getMessage());
		}
	}

	 /**
	  * Truncate a string to fit in a TEXT column (max 65535 bytes).
	  *
	  * Bytes throughout. Deciding in bytes and then cutting in characters is
	  * the bug this avoids: request_params is JSON holding whatever the caller
	  * sent, so one accented or CJK character is two or three bytes, and a cut
	  * to 65532 *characters* can still be three times the column. MySQL then
	  * truncates it itself, or refuses the insert under a strict SQL mode -
	  * and the audit row is either wrong or missing.
	  *
	  * mb_strcut cuts to a byte count without splitting a codepoint, which is
	  * the one thing a plain substr() would get wrong.
	  */
	private static function truncate(?string $sValue, int $iMaxBytes): ?string
	{
		if ($sValue === null) {
			return null;
		}

		if (strlen($sValue) <= $iMaxBytes) {
			return $sValue;
		}

		$sEllipsis = '...';

		return mb_strcut($sValue, 0, $iMaxBytes - strlen($sEllipsis)).$sEllipsis;
	}
}
