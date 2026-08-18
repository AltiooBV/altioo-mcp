<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The order the entry point does things in, which is load-bearing.
 *
 * Nothing here checks behaviour - it checks a sequence, because the sequence is
 * the security property and every step in it looks removable on its own.
 *
 * A real request cannot be driven from a unit test: handleRequest() boots iTop,
 * authenticates, and writes to the wire. What it *can* be held to is the shape
 * of its own source, which is where the ordering lives. The alternative was to
 * leave these three facts asserted nowhere.
 */
class RequestPipelineOrderTest extends TestCase
{
	/**
	 * The body of handleRequest(), comments stripped.
	 *
	 * Stripped because the comments in that method explain the very ordering
	 * being asserted, and would otherwise satisfy every one of these searches
	 * on their own.
	 */
	private function pipeline(): string
	{
		$oMethod = new ReflectionMethod(MCPController::class, 'handleRequest');
		$aLines = file($oMethod->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}

	private function assertComesBefore(string $sFirst, string $sSecond, string $sWhy): void
	{
		$sCode = $this->pipeline();

		$iFirst = strpos($sCode, $sFirst);
		$iSecond = strpos($sCode, $sSecond);

		$this->assertIsInt($iFirst, "handleRequest() no longer calls {$sFirst}");
		$this->assertIsInt($iSecond, "handleRequest() no longer calls {$sSecond}");
		$this->assertLessThan($iSecond, $iFirst, $sWhy);
	}

	/**
	 * The session reset is what stops a browser cookie being a credential for
	 * this endpoint, for every request that gets as far as it. Without it a
	 * logged-in user's session would authenticate the call, so it must run
	 * before the login that would otherwise accept that cookie.
	 *
	 * It reads as redundant next to the credential check that now guards it,
	 * which is exactly why it needs a test: the two are belt and braces, and
	 * either one alone leaves a hole - see
	 * testNothingIsResetForARequestThatBroughtNoCredential().
	 */
	public function testTheSessionIsResetBeforeAnythingCanAuthenticateWithIt(): void
	{
		$this->assertComesBefore(
			'ResetSession',
			'DoLogin',
			'ResetSession() must run before DoLogin(), or a browser cookie authenticates the call'
		);
	}

	/**
	 * ResetSession() is unauthenticated: reaching it is enough to end the
	 * caller's iTop session. Any website can make a browser issue this
	 * request, so the host has to be checked before the reset rather than
	 * inside the SDK, which only runs much later.
	 */
	public function testTheHostIsCheckedBeforeTheSessionIsReset(): void
	{
		$this->assertComesBefore(
			'rejectUnlessHostIsServed',
			'ResetSession',
			'the host check must run before ResetSession(), or a cross-origin request can log a user out'
		);
	}

	/**
	 * The Content-Type check is what keeps the endpoint from being reachable
	 * as a CORS simple request. Same reasoning: it is worth nothing after the
	 * side effect it is meant to prevent.
	 */
	public function testTheContentTypeIsCheckedBeforeTheSessionIsReset(): void
	{
		$this->assertComesBefore(
			'rejectUnlessBodyIsJson',
			'ResetSession',
			'the Content-Type check must run before ResetSession()'
		);
	}

	/**
	 * The reset only happens for a request that brought a credential of its
	 * own, and the check that decides it runs first.
	 *
	 * Order is the whole of it. After the reset the check is a comment: the
	 * session it was protecting is already gone, and an <img src> on any
	 * website is still a logout for every console user who loads that page.
	 */
	public function testNothingIsResetForARequestThatBroughtNoCredential(): void
	{
		$this->assertComesBefore(
			'rejectUnlessACredentialWasPresented',
			'ResetSession',
			'the credential check must run before ResetSession(), or a credential-less request still ends a console session'
		);
	}

	/**
	 * The check reads the headers the promotion writes, so it cannot run
	 * before it: a bearer that has not been promoted yet is still only an
	 * Authorization header, and the two must agree on what counts.
	 */
	public function testTheCredentialIsPromotedBeforeItIsCheckedFor(): void
	{
		$this->assertComesBefore(
			'PromoteBearerToAuthToken',
			'rejectUnlessACredentialWasPresented',
			'the bearer must be promoted before the credential check reads for one'
		);
	}

	/**
	 * The credential is dropped once the login and the scope read are done
	 * with it, and that has to happen before MCPService::run() builds a PSR-7
	 * request out of $_SERVER - which would otherwise copy the raw token into
	 * the request's server parameters for the rest of the call.
	 */
	public function testTheCredentialIsDroppedBeforeTheRequestObjectIsBuilt(): void
	{
		$this->assertComesBefore(
			'ForgetAuthToken',
			'MCPService::run',
			'the token must be dropped before the PSR-7 request is built out of $_SERVER'
		);
	}

	/**
	 * Reading the scopes needs the credential, so it has to happen before the
	 * credential is dropped. The two lines are adjacent and either order
	 * compiles; only one of them works.
	 */
	public function testTheScopesAreReadBeforeTheCredentialIsDropped(): void
	{
		$this->assertComesBefore(
			'AccessPolicyOfCurrentRequest',
			'ForgetAuthToken',
			'the token scopes must be read while the credential still exists'
		);
	}
}
