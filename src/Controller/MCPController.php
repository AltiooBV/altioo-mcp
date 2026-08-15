<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Models\MCPResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Combodo\iTop\Application\WebPage\JsonPage;
use EventMCPService;
use ExecutionKPI;
use LoginWebPage;
use Throwable;
use UserRights;
use ContextTag;
use utils;
use MetaModel;

final class MCPController
{
	public static function handleRequest(): void
	{
		new MCPHelper();

		$oCtx = new ContextTag(MCPContext::TAG_MCP);
		$oKPI = new ExecutionKPI();

		try {
			$oKPI->ComputeAndReport('Data model loaded');

			MCPHttp::PromoteBearerToAuthToken();
			LoginWebPage::ResetSession(true);
			$iRet = LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_RETURN);
			$oKPI->ComputeAndReport('User login');

			if ($iRet === LoginWebPage::EXIT_CODE_OK && self::isMCPAccessRestricted() && !self::userHasMCPProfile()) {
				$iRet = LoginWebPage::EXIT_CODE_NOTAUTHORIZED;
			}

			if ($iRet !== LoginWebPage::EXIT_CODE_OK) {
				throw self::createAuthException($iRet);
			}

			$oKPI->ComputeAndReport('Parameters validated');
			$aRequestResponse = MCPService::run();
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

		self::logIfConfigured($oResult);
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

		$sReference = bin2hex(random_bytes(8));
		MCPHelper::LogError(sprintf(
			'[%s] Unhandled %s: %s in %s:%d',
			$sReference,
			get_class($e),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		));

		return new MCPResult(
			MCPResult::INTERNAL_ERROR,
			'The MCP request could not be completed. Server log reference: '.$sReference
		);
	}

	private static function emitResponse(ResponseInterface $response): MCPResult
	{
		foreach ($response->getHeaders() as $name => $values) {
			foreach ($values as $value) {
				header(sprintf('%s: %s', $name, $value), false);
			}
		}

		http_response_code($response->getStatusCode());

		$body = $response->getBody();
		if ($body->isSeekable()) {
			$body->rewind();
		}
		$sBody = (string)$body;


		// Inspect before emitting — no double decode
		$oResult = self::buildResultFromBody($response, $sBody);

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
			$sReference = bin2hex(random_bytes(8));
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

		// A throw can also happen after emitResponse() has already flushed a
		// successful body, and a status set at that point is only noise.
		if (!headers_sent()) {
			http_response_code($oResult->code === MCPResult::UNAUTHORIZED ? 401 : 500);
		}

		$oP = new JsonPage();
		self::addCorsHeader($oP);
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
	 */
	private static function addCorsHeader(JsonPage $oP): void
	{
		$sOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
		if ($sOrigin === '') {
			return;
		}

		$aAllowed = utils::GetConfig()->GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_ALLOWED_ORIGINS, []);
		if (!is_array($aAllowed) || !in_array($sOrigin, $aAllowed, true)) {
			return;
		}

		$oP->add_header('Access-Control-Allow-Origin: '.$sOrigin);
		$oP->add_header('Vary: Origin');
	}


	private static function logIfConfigured(MCPResult $oResult): void
	{
		if (MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG, MCPHelper::DEFAULT_LOG_SETTING) !== true) {
			return;
		}

		// Only log actual MCP method calls, skip infrastructure requests
		// (initialize, notifications/initialized, tools/list, etc.)
		$sMethod = $oResult->mcpMethod;
		if (utils::IsNullOrEmptyString($sMethod)) {
			return;
		}

		$aLoggedMethods = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_METHOD, []);
		if (!in_array($sMethod, $aLoggedMethods, true)) {
			return;
		}
		// If the response is successful and the log level is set to error, skip logging to avoid filling the logs with successful calls
		if ($oResult->isSuccess() && MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_LEVEL) === MCPHelper::LOG_LEVEL_ERROR) {
			return;
		}

		try {
			$oLog = new EventMCPService();
			$oLog->SetTrim('userinfo', UserRights::GetUser());
			$oLog->Set('message', $oResult->message);
			$oLog->Set('mcp_method', $sMethod);
			$oLog->Set('mcp_name', $oResult->mcpName ?? '');
			$oLog->Set('status', $oResult->isSuccess() ? 'success' : 'error');
			// Log request parameters only for debug level to avoid filling the logs with too much data in case of errors
			if (MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_LEVEL, MCPHelper::DEFAULT_LOG_LEVEL) === MCPHelper::LOG_LEVEL_DEBUG) {
				$oLog->Set('request_params', self::truncate($oResult->requestParams, 65535));
			}
			$oLog->DBInsertNoReload();
		} catch (Throwable $e) {
			// Never let audit logging take down a request that already succeeded.
			MCPHelper::LogError('Failed to log EventMCPService: '.$e->getMessage());
		}
	}

	 /**
	  * Truncate a string to fit in a TEXT column (max 65535 bytes).
	  */
	private static function truncate(?string $sValue, int $iMaxBytes): ?string
	{
		if ($sValue === null) {
			return null;
		}

		if (strlen($sValue) <= $iMaxBytes) {
			return $sValue;
		}

		return mb_substr($sValue, 0, $iMaxBytes - 3).'...';
	}
}
