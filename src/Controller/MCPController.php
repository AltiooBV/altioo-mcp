<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Controller\Gate\BodyIsJsonGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\CredentialPresentedGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\HostIsServedGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\LoginGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\McpProfileGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\PreflightGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\TokenAudienceGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\TokenLoginAccountedForGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\TokenNotAParameterGate;
use Altioo\iTop\Extension\MCP\Controller\Gate\iGate;
use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Combodo\iTop\Application\Helper\Session;
use ExecutionKPI;
use Throwable;
use ContextTag;

/**
 * The endpoint: the gates a request passes in order, the policy decided from
 * the credential while it is still at hand, the server run, and the answer.
 *
 * The checks are the gates' (Controller\Gate), the answer is MCPResponse's and
 * the audit row is CallLog's; this is the one place that says in what order.
 *
 * @internal
 * @since 1.0.0
 */
final class MCPController
{
	/**
	 * The gates a request passes, in order. A gate either lets it continue or
	 * answers it; see Gate\iGate.
	 *
	 * @var array<int, class-string<iGate>>
	 */
	private const GATES = [
		PreflightGate::class,
		HostIsServedGate::class,
		BodyIsJsonGate::class,
		CredentialPresentedGate::class,
		TokenNotAParameterGate::class,
		LoginGate::class,
		McpProfileGate::class,
		TokenLoginAccountedForGate::class,
		TokenAudienceGate::class,
	];

	public static function handleRequest(): void
	{
		MCPLog::EnableForModule();

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

			// Every check the request passes, in the order it passes them. The
			// order is the security property (each gate says what it must come
			// before), and a gate that answers the request ends it here.
			foreach (self::GATES as $sGate) {
				if (!(new $sGate())->Check($oKPI)) {
					return;
				}
			}

			// The scopes are the last thing that needs the raw credential, so
			// they are read here and the credential dropped immediately after.
			// What follows builds a PSR-7 request out of $_SERVER, and a token
			// still sitting there would be copied into its server parameters
			// and live for the rest of the call.
			$oPolicy = ServedElements::AccessPolicyOfCurrentRequest();
			// Remembered before the token is dropped, because deciding it again
			// later would need the token back. A write tool asks this to learn
			// whether it is rehearsing, and asking must not cost a query.
			AccessPolicy::Remember($oPolicy);
			// Same window, same reason: the audit row names the credential that
			// ran the request, and a row id cannot be recovered once the
			// credential it belongs to has been dropped. An identity is not a
			// secret; the token is, and it still goes on the next line.
			TokenScopes::RememberIdentity();
			MCPHttp::ForgetAuthToken();

			$oKPI->ComputeAndReport('Parameters validated');
			$aRequestResponse = MCPService::run($oPolicy);
			// Last moment the session can go: once the body is out, neither
			// the headers nor the session can be touched.
			self::discardRequestSession();
			// Emit response and extract info for logging
			$oResult = MCPResponse::EmitResponse($aRequestResponse['response']);
			$oKPI->ComputeAndReport('Operation finished');
			$oResult = CallRequest::GetMCPInfoFromRequest($aRequestResponse['request'], $oResult);
		} catch (Throwable $e) {
			// Throwable, not Exception: this is the single public entry point, and a
			// TypeError or a missing class here would otherwise escape uncaught -
			// producing a bare 500 with no audit entry.
			$oResult = self::buildErrorResult($e);
			$oResult->mcpMethod = MCPMethod::EXCEPTION;
			$oKPI->ComputeAndReport('Exception catched');
			self::discardRequestSession();
			MCPResponse::OutputJsonResultException($oResult);
		}

		$oResult->durationMs = (int)round((microtime(true) - $fStarted) * 1000);

		CallLog::LogIfConfigured($oResult);
	}

	/**
	 * Ends the PHP session the login opened for this request, and withdraws
	 * the cookies that announced it.
	 *
	 * The endpoint is stateless and never reads that session back - a
	 * request without its own credential is refused before it is consulted,
	 * and one with a credential resets it. But iTop's login still creates
	 * one, and Session::Set() reopens it for every value it stores, each
	 * session_start() sending a fresh Set-Cookie for a client that presented
	 * none: eight identical headers per call, and a session file per call left
	 * on the server for the garbage collector. Nothing in it is useful to
	 * anyone after this response.
	 *
	 * A deliberate exception to the guide's §6.1 "no session restarts": this
	 * reopens only the session this request itself created, in order to
	 * destroy it, and touches no session setting. Replaying the cookie against
	 * the console was not the reason - authent-token already refuses a
	 * token-mode session presented without its token - but there is no longer
	 * a cookie to replay either.
	 *
	 * Must run before anything is written to the wire. After that neither the
	 * headers nor the session can be changed, and this does nothing.
	 */
	private static function discardRequestSession(): void
	{
		if (headers_sent()) {
			return;
		}

		if (session_id() !== '') {
			// Reopened through iTop's own helper, so its bookkeeping agrees,
			// and destroyed through PHP, which runs iTop's session handler -
			// the one that also removes its session-tracking file.
			Session::Start();
			if (session_status() === PHP_SESSION_ACTIVE) {
				$_SESSION = [];
				session_destroy();
			}
			Session::WriteClose();
		}

		header_remove('Set-Cookie');
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
			$oResult = new MCPResult($e->getCode(), $e->getMessage());
			$oResult->challengeError = $e->challengeError;

			return $oResult;
		}

		if ($e instanceof MCPRequestRejectedException) {
			$oResult = new MCPResult(MCPResult::REQUEST_REJECTED, $e->getMessage());
			$oResult->httpStatus = $e->httpStatus();

			return $oResult;
		}

		$sReference = ErrorReport::NewReference();
		ErrorReport::Log(sprintf(
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


}
