<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Exception\MCPRequestRejectedException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Helper\OAuthResource;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Psr\Http\Message\RequestInterface;
use Combodo\iTop\Application\Helper\Session;
use Mcp\Server\Stateless\RequestMeta;
use DBObject;
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
	/**
	 * The profiles the datamodel ships in mcp_allowed_profiles, repeated here
	 * as the fallback for an instance whose configuration block is missing.
	 * Kept in step with datamodel.altioo-mcp.xml, which is what a normal
	 * install actually reads.
	 */
	private const DEFAULT_ALLOWED_PROFILES = ['Administrator', 'MCP Services User'];

	public static function handleRequest(): void
	{
		MCPLog::EnableForModule();

		// Before anything that needs a user: a preflight carries no credential
		// by definition, so answering it after DoLogin() would answer 401 and
		// the browser would never send the real request.
		if (self::isPreflight()) {
			MCPResponse::EmitPreflight();

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
			self::rejectATokenSentAsAParameter();

			LoginWebPage::ResetSession();
			$iRet = LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_RETURN);
			$oKPI->ComputeAndReport('User login');

			// Only ever from the console refusal, so a console login reaches
			// none of it and goes on exactly as before portal mode existed.
			if ($iRet === LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED) {
				$iRet = self::admitInPortalMode();
			}

			if ($iRet === LoginWebPage::EXIT_CODE_OK && Settings::IsAccessRestricted() && !self::userHasMCPProfile()) {
				$iRet = LoginWebPage::EXIT_CODE_NOTAUTHORIZED;
			}

			if ($iRet !== LoginWebPage::EXIT_CODE_OK) {
				throw self::createAuthException($iRet);
			}

			// Before the policy is decided, because the policy is what this
			// protects: a token login whose token this module did not see
			// would be graded as no token at all.
			self::rejectATokenLoginNotAccountedFor();
			// While the token is still at hand: one a person granted for
			// another URL of this endpoint - another portal's - is not theirs
			// to use here (doc/adr/0007).
			self::rejectATokenGrantedForAnotherUrl();

			// The scopes are the last thing that needs the raw credential, so
			// they are read here and the credential dropped immediately after.
			// What follows builds a PSR-7 request out of $_SERVER, and a token
			// still sitting there would be copied into its server parameters
			// and live for the rest of the call.
			$oPolicy = MCPService::AccessPolicyOfCurrentRequest();
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
			$oResult = self::getMCPInfoFromRequest($aRequestResponse['request'], $oResult);
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
	 * A CORS preflight, which is the one request that reaches this endpoint
	 * without a credential and must still be answered.
	 */
	private static function isPreflight(): bool
	{
		return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
			&& ($_SERVER['HTTP_ORIGIN'] ?? '') !== '';
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

		if (MCPHttp::IsAllowedHost($sOrigin, $sHost, Settings::GetAllowedHosts())) {
			return;
		}

		// The name that was refused goes to the log, not to the caller: an
		// operator whose endpoint answers 403 needs to see it, and a caller
		// probing for the configured hostnames does not.
		ErrorReport::Log(sprintf(
			"Refused an MCP request: neither its Origin (%s) nor its Host (%s) is in '%s'. "
			.'Set that module parameter to the hostname this instance is served under.',
			is_string($sOrigin) && $sOrigin !== '' ? $sOrigin : '-',
			is_string($sHost) && $sHost !== '' ? $sHost : '-',
			Settings::MODULE_SETTING_ALLOWED_HOSTS
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
			'No credential was sent. The MCP endpoint needs one on every request: a bearer token in the '
			.'Authorization header, an Auth-Token header, or a login. A browser session does not count.',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a token sent as a request parameter, before it can log in.
	 *
	 * See MCPHttp::CarriesATokenAsAParameter() for why. Refused before the
	 * session reset for the same reason as the checks above it: nothing that
	 * is going to be refused anyway should cost a console user their session.
	 *
	 * @throws MCPAuthException
	 */
	private static function rejectATokenSentAsAParameter(): void
	{
		if (!MCPHttp::CarriesATokenAsAParameter()) {
			return;
		}

		ErrorReport::Log('Refused an MCP request carrying auth_token as a request parameter. The endpoint reads a token from the Authorization or Auth-Token header only.');

		throw new MCPAuthException(
			'Send the token in the Authorization header (Bearer) or the Auth-Token header. '
			.'A token passed as the auth_token request parameter is refused: a URL is written to access logs.',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a token login whose token this module was not shown.
	 *
	 * The backstop behind rejectATokenSentAsAParameter(): that one closes the
	 * hole as it is known today, this one closes it as iTop reports it. See
	 * TokenScopes::LoginIsAccountedFor().
	 *
	 * @throws MCPAuthException
	 */
	private static function rejectATokenLoginNotAccountedFor(): void
	{
		$mLoginMode = Session::Get('login_mode');
		if (TokenScopes::LoginIsAccountedFor($mLoginMode)) {
			return;
		}

		ErrorReport::Log(sprintf(
			"Refused an MCP request: iTop logged it in under login mode '%s' with a token this module was not shown, so the token's scopes could not be applied.",
			is_string($mLoginMode) ? $mLoginMode : gettype($mLoginMode)
		));

		throw new MCPAuthException(
			'Send the token in the Authorization header (Bearer) or the Auth-Token header.',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Lets a user with no console in under portal mode, or leaves the console
	 * refusal as it was.
	 *
	 * Reached only after DoLogin() returned EXIT_CODE_PORTALUSERNOTAUTHORIZED,
	 * which iTop returns from ChangeLocation(), after the login itself has
	 * succeeded - so the user is authenticated here, and UserRights answers
	 * for them. Taking portal mode from that one refusal, rather than logging
	 * in with DoLoginEx(null) and sorting users afterwards, is what keeps
	 * every console login on the path it has always taken.
	 *
	 * With mcp_portals empty nothing is read and the refusal stands; its
	 * default, "*", serves every portal, so a portal-only user holding the
	 * MCP profile is let in by that profile. A user in none of the listed
	 * portals gets the refusal unchanged, with a log line saying why. The other failures are portal mode's own and are told to
	 * the caller as what to change, on the usual split: the caller learns what
	 * to fix, the log names who.
	 *
	 * @throws MCPAuthException For a refusal that is portal mode's own.
	 */
	private static function admitInPortalMode(): int
	{
		$aServed = Settings::GetServedPortals();
		if ($aServed === []) {
			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		$sRequested = PortalMode::Requested();
		$sPortal = PortalMode::Choose(array_column(UserRights::GetAllowedPortals(), 'id'), $aServed, $sRequested);
		$iMaxDays = Settings::GetPortalTokenMaxDays();
		$sRefusal = PortalMode::RefusalOf(
			$sPortal,
			TokenScopes::LoginWasAToken(Session::Get('login_mode')),
			PortalMode::TokenIsBounded(self::presentedTokenExpiration(), time(), $iMaxDays),
			(int)UserRights::GetContactId() > 0,
			self::userHasMCPProfile()
		);

		if ($sRefusal === null) {
			PortalAccess::Enter($sPortal);

			return LoginWebPage::EXIT_CODE_OK;
		}

		CallLog::AuditAdmissionRefusal($sRefusal);

		if ($sRefusal === PortalMode::REFUSED_NO_SERVED_PORTAL && $sRequested !== null) {
			// The id is the request's text: quoted only when it is shaped like one.
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: the URL names %s, which '%s' may not enter or '%s' does not serve (%s).",
				$sRequested === '' ? 'a malformed '.PortalMode::PARAMETER : "the portal '{$sRequested}'",
				UserRights::GetUser(),
				Settings::MODULE_SETTING_PORTALS,
				implode(', ', $aServed)
			));

			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		if ($sRefusal === PortalMode::REFUSED_NO_SERVED_PORTAL) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' is allowed into none of the portals in '%s' (%s).",
				UserRights::GetUser(),
				Settings::MODULE_SETTING_PORTALS,
				implode(', ', $aServed)
			));

			return LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED;
		}

		if ($sRefusal === PortalMode::REFUSED_NOT_A_TOKEN) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' did not log in with a token.",
				UserRights::GetUser()
			));

			throw new MCPAuthException(
				'A user without console access is served with a token only. Send it in the '
				.'Authorization header (Bearer) or the Auth-Token header.',
				MCPResult::UNAUTHORIZED
			);
		}

		if ($sRefusal === PortalMode::REFUSED_TOKEN_UNBOUNDED) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' presented a token that never expires, or expires more than %d days from now ('%s').",
				UserRights::GetUser(),
				$iMaxDays,
				Settings::MODULE_SETTING_PORTAL_TOKEN_MAX_DAYS
			));

			throw new MCPAuthException(sprintf(
				'In portal mode a token must expire within %d days. Ask for a personal token with an '
				.'expiry date no later than %s, or set one on this token.',
				$iMaxDays,
				date('Y-m-d', time() + $iMaxDays * 86400)
			), MCPResult::UNAUTHORIZED);
		}

		if ($sRefusal === PortalMode::REFUSED_NO_CONTACT) {
			ErrorReport::Log(sprintf(
				"Refused an MCP request in portal mode: '%s' is linked to no contact.",
				UserRights::GetUser()
			));

			throw new MCPAuthException(
				'This account is linked to no contact, which an iTop portal requires. '
				.'Ask an administrator to link it to the person it belongs to.',
				MCPResult::UNAUTHORIZED
			);
		}

		// REFUSED_NO_MCP_PROFILE, answered and logged as it is for a console user.
		return LoginWebPage::EXIT_CODE_NOTAUTHORIZED;
	}

	/**
	 * Refuses a token a person granted, through altioo-oauth-server, for
	 * another URL of this endpoint: with one OAuth resource per portal, a
	 * consent for one portal does not reach another (doc/adr/0007). A token
	 * no connection owns - one an administrator minted - has no such URL and
	 * is held by the other rules only; so is every token when that module is
	 * not installed.
	 *
	 * @throws MCPAuthException When the token was granted for another URL; the
	 *     401's challenge sends the client to consent for this one.
	 */
	private static function rejectATokenGrantedForAnotherUrl(): void
	{
		$sAudienceClass = OAuthResource::TOKEN_AUDIENCE_CLASS;
		if (!class_exists($sAudienceClass)) {
			return;
		}

		$oToken = TokenScopes::OfCurrentRequestObject();
		if (!$oToken instanceof DBObject || !is_a($oToken, 'PersonalToken')) {
			return;
		}

		$oAudience = call_user_func([$sAudienceClass, 'Of'], (int)$oToken->GetKey());
		if (!is_object($oAudience) || !method_exists($oAudience, 'IsFor')) {
			return;
		}

		$sCalled = OAuthResource::CalledEndpointUrl();
		if ($oAudience->IsFor($sCalled) === true) {
			return;
		}

		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' presented a token granted for the OAuth resource '%s', not for the URL called ('%s').",
			UserRights::GetUser(),
			property_exists($oAudience, 'sResourceKey') ? (string)$oAudience->sResourceKey : '?',
			$sCalled
		));

		throw new MCPAuthException(
			'This token was granted for another URL of this endpoint, such as another portal\'s. '
			.'Connect again from this URL to consent to it.',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * The expiry of the token this request presented, as iTop stores it, or
	 * null when it has none - including a token class that carries no expiry
	 * at all, which portal mode therefore cannot bound and refuses.
	 */
	private static function presentedTokenExpiration(): ?string
	{
		$oToken = TokenScopes::OfCurrentRequestObject();
		if ($oToken === null || !MetaModel::IsValidAttCode(get_class($oToken), 'expiration_date')) {
			return null;
		}

		$mExpiration = $oToken->Get('expiration_date');

		return is_string($mExpiration) && $mExpiration !== '' ? $mExpiration : null;
	}

	private static function userHasMCPProfile(): bool
	{
		/** @var \User $oUser */
		$oUser = UserRights::GetUserObject();

		if (is_null($oUser)) {
			return false;
		}

		foreach (self::GetAuthorizedProfiles() as $sProfile) {
			if (UserRights::HasProfile($sProfile, $oUser)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The profiles this instance lets through, as configured.
	 *
	 * The fallback is the list the datamodel ships rather than an empty one,
	 * and the difference is the whole behaviour of an instance whose
	 * configuration block is not there. Falling back to nobody, combined with
	 * secure_mcp_services independently falling back to true, refuses every
	 * caller - an Administrator included - for a reason no message names. A
	 * missing block is a config file a hand edit went wrong in, not a decision
	 * anyone made.
	 *
	 * An operator who writes an empty list is still obeyed: an explicit
	 * array() is what GetModuleSetting() returns, and it means what it has
	 * always meant, which is nobody.
	 *
	 * @return array<int, string>
	 */
	private static function GetAuthorizedProfiles() : array
	{
		$aProfiles = utils::GetConfig()->GetModuleSetting(MCPModule::MODULE_NAME, 'mcp_allowed_profiles', self::DEFAULT_ALLOWED_PROFILES);
		if (is_array($aProfiles)) {
			return $aProfiles;
		}

		$sType = gettype($aProfiles);
		ErrorReport::Log("Itop configuration parameter 'mcp_allowed_profiles' should be an array instead of $sType");
		return [];
	}

	private static function createAuthException(int $iRet): MCPAuthException
	{
		switch ($iRet) {
			case LoginWebPage::EXIT_CODE_WRONGCREDENTIALS:
				return self::createWrongCredentialsException();
			case LoginWebPage::EXIT_CODE_NOTAUTHORIZED:
				return self::createProfileException();
			case LoginWebPage::EXIT_CODE_PORTALUSERNOTAUTHORIZED:
				return self::createNoConsoleAccessException();
			default:
				return new MCPAuthException('Unknown authentication error (retCode='.$iRet.')', MCPResult::UNAUTHORIZED);
		}
	}

	/**
	 * Refuses a caller who authenticated but holds none of the profiles this
	 * instance lets through.
	 *
	 * The message says "by default" because there is no fixed answer: the gate
	 * is mcp_allowed_profiles, an operator may set it to anything, and naming
	 * one profile as though it were the rule sends an administrator looking for
	 * a grant that is not what their instance checks. It names Administrator
	 * too, which is half of the shipped default.
	 *
	 * What this instance actually requires goes to the log rather than into the
	 * response, for the same reason the refused hostname does in
	 * rejectUnlessHostIsServed(): it is operator configuration, the person who
	 * needs it can read log/error.log, and a caller collecting 401s cannot.
	 */
	private static function createProfileException(): MCPAuthException
	{
		$aProfiles = self::GetAuthorizedProfiles();

		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' holds none of the profiles in '%s' (%s).",
			UserRights::GetUser(),
			'mcp_allowed_profiles',
			empty($aProfiles) ? 'the list is empty, so nobody can be let through' : implode(', ', $aProfiles)
		));

		return new MCPAuthException(
			'This user is not authorized to use the MCP services. '
			.'(By default, the profile "MCP Services User" or "Administrator" is required; '
			.'the profiles this instance accepts are set by the mcp_allowed_profiles module parameter.)',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a caller whose credentials are valid but whose profiles give them
	 * no console.
	 *
	 * DoLogin(false, false, ...) asks DoLoginEx() for the 'backoffice' portal,
	 * so a user who only reaches the end-user portal authenticates and is then
	 * turned away by ChangeLocation() with EXIT_CODE_PORTALUSERNOTAUTHORIZED.
	 * That is the intended boundary - this endpoint serves what the console
	 * serves - but it is reached by an ordinary mistake: granting "MCP Services
	 * User" to a portal user and issuing them a token. Nothing else about that
	 * setup looks wrong, and without this case the operator got the default
	 * branch's "Unknown authentication error (retCode=5)" and no log line.
	 *
	 * Same split as everywhere else on this path: the caller is told what to
	 * change, the log names who it was.
	 */
	private static function createNoConsoleAccessException(): MCPAuthException
	{
		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' authenticated but holds no profile granting access to the "
			.'console, so there is no interface for this endpoint to serve. A portal-only user cannot '
			.'use the MCP services whatever their token scopes or MCP profile are, unless portal mode '
			."serves one of their portals ('%s').",
			UserRights::GetUser(),
			Settings::MODULE_SETTING_PORTALS
		));

		return new MCPAuthException(
			'This user has no access to the iTop console. The MCP services serve what the console '
			.'serves, so a portal-only user cannot reach them - the account needs a profile granting '
			.'console access in addition to whatever lets it through the MCP gate, or an administrator '
			.'has to serve its portal in portal mode (the mcp_portals module parameter).',
			MCPResult::UNAUTHORIZED
		);
	}

	/**
	 * Refuses a credential that did not authenticate, saying which kind.
	 *
	 * A token is the case a client can recover from on its own, so it is the
	 * one the challenge names as invalid_token: an OAuth client refreshes or
	 * sends the person through authorize again, where "Invalid login" alone
	 * read like a wrong password. iTop answers this same exit code for a token
	 * that is unknown, expired or revoked, and for one whose scopes include no
	 * MCP scope (see TokenScopes), and it does not say which, so the message
	 * names all of them.
	 */
	private static function createWrongCredentialsException(): MCPAuthException
	{
		if (MCPHttp::CurrentAuthToken() === null) {
			return new MCPAuthException('The login or password was not accepted.', MCPResult::UNAUTHORIZED);
		}

		return new MCPAuthException(
			'The token was not accepted: it is unknown, has expired or been revoked, or carries no MCP scope. '
			.'Refresh it, or connect again.',
			MCPResult::UNAUTHORIZED,
			'invalid_token'
		);
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

	private static function getMCPInfoFromRequest(RequestInterface $request, MCPResult $oResult): MCPResult
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
