<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Models\MCPResult;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Combodo\iTop\Application\WebPage\JsonPage;
use EventMCPService;
use Exception;
use ExecutionKPI;
use LoginWebPage;
use RestResult;
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
		} catch (Exception $e) {
			$oResult = new MCPResult($e->getCode(), 'Error: '.$e->getMessage());
			$oResult->mcpMethod = MCPHelper::MCP_METHOD_PARAM;
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

	private static function createAuthException(int $iRet): Exception
	{
		switch ($iRet) {
			case LoginWebPage::EXIT_CODE_WRONGCREDENTIALS:
				return new Exception('Invalid login', MCPResult::UNAUTHORIZED);
			case LoginWebPage::EXIT_CODE_NOTAUTHORIZED:
				return new Exception('This user is not authorized to use the MCP services. (The profile MCP Services User is required to access the MCP services)', RestResult::UNAUTHORIZED);
			default:
				return new Exception('Unknown authentication error (retCode='.$iRet.')', MCPResult::UNAUTHORIZED);
		}
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
			$oJsonIssue = new MCPResult();
			$oJsonIssue->code = MCPResult::INTERNAL_ERROR;
			$oJsonIssue->message = 'json encoding failed with message: '.json_last_error_msg().'. Full response structure for debugging purposes (print_r+bin2hex): '.bin2hex(print_r($oResult, true));
			$sResponse = json_encode($oJsonIssue);
		}

		$oP = new JsonPage();
		$oP->add_header('Access-Control-Allow-Origin: *');
		$oP->SetData(json_decode($sResponse, true));
		$oP->SetOutputDataOnly(true);
		$oP->Output();
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
		} catch (Exception $e) {
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
