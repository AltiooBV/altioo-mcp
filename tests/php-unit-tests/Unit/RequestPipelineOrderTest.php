<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Controller\MCPResponse;
use Altioo\iTop\Extension\MCP\Test\Support\RequestPipelineSource;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
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
	 * What a request runs, in order: the gates of MCPController::GATES, each behind a
	 * marker, then the body of handleRequest(), comments stripped.
	 *
	 * Stripped because the comments in that method explain the very ordering
	 * being asserted, and would otherwise satisfy every one of these searches
	 * on their own.
	 */
	private function assertComesBefore(string $sFirst, string $sSecond, string $sWhy): void
	{
		$sFirstSpec = RequestPipelineSource::Spec($sFirst);
		$sSecondSpec = RequestPipelineSource::Spec($sSecond);

		$this->assertTrue(RequestPipelineSource::Has($sFirstSpec), "the request pipeline no longer calls {$sFirst}");
		$this->assertTrue(RequestPipelineSource::Has($sSecondSpec), "the request pipeline no longer calls {$sSecond}");
		$this->assertTrue(RequestPipelineSource::InOrder([$sFirstSpec, $sSecondSpec]), $sWhy);
	}

	public function testTheSessionIsResetBeforeAnythingCanAuthenticateWithIt(): void
	{
		$this->assertComesBefore(
			'ResetSession',
			'DoLogin',
			'ResetSession() must run before DoLogin(), or a browser cookie authenticates the call'
		);
	}

	/**
	 * The audit row names the credential that ran the request, and it can only
	 * learn which one while the credential is still in hand.
	 *
	 * ForgetAuthToken() drops the raw token as soon as the scopes have been
	 * read - deliberately, so that what follows cannot copy it into a PSR-7
	 * request or a stack trace - and the row is written at the very end. The
	 * first version of this feature resolved the token when writing the row,
	 * which is after the drop: both external keys shipped, both were always
	 * empty, and nothing failed to say so.
	 *
	 * Asserted as an ordering rather than as behaviour because that is what it
	 * is. The call looks freely movable, and moving it one line down makes the
	 * audit trail quietly anonymous again.
	 */
	public function testTheCredentialIsIdentifiedBeforeItIsDropped(): void
	{
		$this->assertComesBefore(
			'RequestScope::Open',
			'ForgetAuthToken',
			'RequestScope::Open() (which remembers the identity) must run before ForgetAuthToken(), or the audit row cannot name the credential'
		);
	}

	/**
	 * And the drop still happens. The fix for the above is one line before it;
	 * the failure mode of a careless merge is keeping the new line and losing
	 * the old one, which trades an empty column for a token living on in a
	 * PSR-7 request's server parameters.
	 */
	public function testTheCredentialIsStillDroppedBeforeTheSdkRuns(): void
	{
		$this->assertComesBefore(
			'ForgetAuthToken',
			'MCPService::run',
			'ForgetAuthToken() must run before the SDK sees the request, or the raw token is copied into it'
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
			'[[HostIsServedGate]]',
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
			'[[BodyIsJsonGate]]',
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
			'[[CredentialPresentedGate]]',
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
			'MCPHttp::CarriesACredential',
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

	/**
	 * The session the login created is destroyed, and its cookies withdrawn,
	 * after the tools have run and before a byte of the answer is written.
	 * Earlier and a tool loses the session iTop keeps its rights cache in;
	 * later and both the headers and the session are out of reach, so the
	 * call does nothing and the eight Set-Cookie headers are back.
	 */
	public function testTheSessionIsDiscardedBetweenTheToolsAndTheAnswer(): void
	{
		// In serve() itself, and in sequence rather than at the first occurrence: the batch
		// refusal, which runs no tool, discards the session in a branch of its own.
		$aSpecs = [RequestPipelineSource::Spec('MCPService::run'), RequestPipelineSource::Spec('discardRequestSession'), RequestPipelineSource::Spec('Emit')];
		$this->assertTrue(
			SourceCalls::OfClass(MCPController::class)->CallsInSequence($aSpecs, 'serve'),
			'the session must outlive the tools, and go before the answer is written'
		);
	}

	/**
	 * A refused login or a failed call answers through the exception path,
	 * and leaves the same session behind unless that path discards it too.
	 */
	public function testTheErrorPathDiscardsItAsWell(): void
	{
		$aCatch = RequestPipelineSource::ErrorPath();
		$sDiscard = strtolower(RequestPipelineSource::Spec('discardRequestSession'));
		$sAnswer = strtolower(RequestPipelineSource::Spec('PrepareException'));
		$aLowered = array_map('strtolower', $aCatch);

		$this->assertContains($sDiscard, $aLowered, 'the exception path no longer discards the session');
		$this->assertContains($sAnswer, $aLowered, 'the exception path no longer prepares the answer');
		$this->assertLessThan(array_search($sAnswer, $aLowered, true), array_search($sDiscard, $aLowered, true), 'the session must go before the error is answered');
	}

	/**
	 * The audit row is written before the answer is, whichever way the request ends.
	 *
	 * PHP learns that a client has hung up only when it writes output, and then ends the
	 * script. A write that is answered first and recorded after is therefore committed and
	 * never recorded for a caller that sends it and closes the socket: no row, so no count
	 * against `mcp_max_writes_per_hour` (which counts from the rows, ADR 0019) and nothing in
	 * the trail. ignore_user_abort() would be a global switch (guide section 6.1); the order
	 * is the fix, so the order is what is pinned.
	 *
	 * serve() has one tail for every path - served, refused as a batch, failed - and these
	 * three tests say what it is made of: one row, then one answer, and nothing else that
	 * writes.
	 */
	public function testTheAuditRowIsWrittenBeforeTheAnswerIsWritten(): void
	{
		$this->assertComesBefore(
			'CallLog::LogIfConfigured',
			'Emit',
			'the audit row must be written before the answer is, or a client that hangs up after sending a write leaves the write with no row and no count against the write cap'
		);
	}

	public function testEveryPathEndsInOneRowAndThenOneAnswer(): void
	{
		$aUses = array_map('strtolower', RequestPipelineSource::ServeUses());
		$sRow = strtolower(RequestPipelineSource::Spec('CallLog::LogIfConfigured'));
		$sEmit = strtolower(RequestPipelineSource::Spec('Emit'));

		$this->assertSame(1, count(array_keys($aUses, $sRow, true)), 'serve() must write the audit row in one place, after every path has decided its answer');
		$this->assertSame(1, count(array_keys($aUses, $sEmit, true)), 'serve() must write the answer in one place, after the audit row');

		// The tail is the last two things serve() does.
		$this->assertSame([$sRow, $sEmit], array_slice($aUses, -2), 'the audit row and then the answer must be the last things serve() does, so no path writes after the answer');

		// The paths that decide an answer: each prepares it, none writes it.
		foreach (['PrepareResponse', 'PrepareException', 'BatchRefusal::Refuse'] as $sName) {
			$this->assertContains(strtolower(RequestPipelineSource::Spec($sName)), $aUses, "serve() no longer builds its answer with {$sName}");
		}
		$this->assertNotContains(
			$sEmit,
			array_map('strtolower', RequestPipelineSource::ErrorPath()),
			'the exception path must not write the answer: the row is written after it, by the tail'
		);
	}

	public function testServeWritesNothingToTheWireItself(): void
	{
		$sCode = RequestPipelineSource::ServeCode();

		foreach (['echo', 'print(', 'print$', 'exit', 'die', 'header(', 'http_response_code(', 'header_remove(', 'flush(', 'ob_flush(', 'readfile(', 'fwrite(', 'fputs(', 'file_put_contents('] as $sToken) {
			$this->assertStringNotContainsStringIgnoringCase($sToken, $sCode, "serve() must not write to the wire or end the script itself ({$sToken}): the answer goes out through PreparedAnswer::Emit(), after the audit row");
		}
		foreach (RequestPipelineSource::ServeUses() as $sUse) {
			$this->assertDoesNotMatchRegularExpression('/::(emit|output)[a-z]*$/i', $sUse, "serve() calls {$sUse}, which writes before the audit row");
		}
	}

	/**
	 * What builds an answer builds it and writes nothing: the output calls are inside the
	 * closure handed to PreparedAnswer, which only Emit() runs. A header(), echo or
	 * ->Output() ahead of `new PreparedAnswer` is a write before the row.
	 */
	public function testPreparingAnAnswerWritesNothing(): void
	{
		$oSource = SourceCalls::OfClass(MCPResponse::class);

		foreach (['PrepareResponse', 'PrepareException', 'PrepareBatchRefusal'] as $sMethod) {
			$sCode = $oSource->Code($sMethod);
			$iBuilt = strpos($sCode, 'newPreparedAnswer(');
			$this->assertNotFalse($iBuilt, "{$sMethod} no longer returns a PreparedAnswer");

			$sBefore = substr($sCode, 0, $iBuilt);
			foreach (['echo', 'print(', 'print$', 'exit', 'die', 'header(', 'http_response_code(', '->Output(', 'flush('] as $sToken) {
				$this->assertStringNotContainsStringIgnoringCase($sToken, $sBefore, "{$sMethod} writes ({$sToken}) before it hands the answer back: that is a write ahead of the audit row");
			}
		}
	}

	/**
	 * The gates, and the order a request passes them in. Every other test in this
	 * file asks one pair of them; this one pins the list, so a gate cannot be
	 * dropped, added or moved without a test saying so.
	 */
	public function testTheGatesAreTheseAndRunInThisOrder(): void
	{
		$sNamespace = 'Altioo\\iTop\\Extension\\MCP\\Controller\\Gate\\';

		$this->assertSame(
			array_map(static fn (string $sName): string => $sNamespace.$sName, [
				'PreflightGate',
				'HostIsServedGate',
				'BodyIsJsonGate',
				'CredentialPresentedGate',
				'TokenNotAParameterGate',
				'LoginGate',
				'McpProfileGate',
				'TokenLoginAccountedForGate',
				'TokenAudienceGate',
				'ConsoleTokenLifetimeGate',
			]),
			RequestPipelineSource::Gates()
		);
	}

	/**
	 * Under the CLI there is no session and iTop's helper declines to start
	 * one; the discard must be a quiet no-op there rather than a warning
	 * from session_destroy().
	 */
	public function testWithNoSessionItDoesNothing(): void
	{
		(new ReflectionMethod(MCPController::class, 'discardRequestSession'))->invoke(null);

		$this->assertSame('', session_id());
	}
}
