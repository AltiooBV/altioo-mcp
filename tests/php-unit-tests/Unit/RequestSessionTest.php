<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Controller\RequestSession;
use Altioo\iTop\Extension\MCP\Test\Support\RequestPipelineSource;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-1: a request refused before the login ran, carrying a console
 * user's session cookie, destroyed that session. Any web page can make a browser send
 * one (a top-level GET), so any web page could log a console user out.
 */
class RequestSessionTest extends TestCase
{
	private const NAME = 'itop-0123456789abcdef';
	private const ID = 'abcdefghijklmnopqrstuvwxyz012345';

	public function testASessionTheCookieNamedAndThatHoldsSomethingIsResumed(): void
	{
		$this->assertTrue(RequestSession::IsResumed([self::NAME => self::ID], self::NAME, self::ID, ['auth_user' => 'admin']));
	}

	public function testASessionTheRequestCreatedIsNotResumed(): void
	{
		// No cookie: PHP made a fresh identifier for this request.
		$this->assertFalse(RequestSession::IsResumed([], self::NAME, self::ID, []));
		$this->assertFalse(RequestSession::IsResumed(['other' => self::ID], self::NAME, self::ID, ['auth_user' => 'admin']));
		// A cookie that names another session than the one open.
		$this->assertFalse(RequestSession::IsResumed([self::NAME => 'someotheridentifier'], self::NAME, self::ID, ['auth_user' => 'admin']));
	}

	public function testAnInventedIdentifierIsNotResumed(): void
	{
		// PHP adopts any identifier a client sends and opens an empty session under it: nothing to protect,
		// and a file per invented cookie to leave behind if it were kept.
		$this->assertFalse(RequestSession::IsResumed([self::NAME => self::ID], self::NAME, self::ID, []));
	}

	public function testNothingIsResumedWithoutASession(): void
	{
		$this->assertFalse(RequestSession::IsResumed([self::NAME => ''], self::NAME, '', ['a' => 'b']));
		$this->assertFalse(RequestSession::IsResumed([self::NAME => self::ID], '', self::ID, ['a' => 'b']));
		$this->assertFalse(RequestSession::IsResumed([self::NAME => ['x']], self::NAME, self::ID, ['a' => 'b']));
	}

	/**
	 * The behaviour the review reproduced: a session kept is neither emptied nor destroyed.
	 *
	 * In a process of its own because it opens a real session and calls header_remove().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testAKeptSessionSurvivesTheRefusal(): void
	{
		$sDir = sys_get_temp_dir().'/mcp-session-'.bin2hex(random_bytes(4));
		mkdir($sDir);
		ini_set('session.save_path', $sDir);
		session_id(self::ID);
		session_start();
		$_SESSION = ['auth_user' => 'admin', 'login_state' => 'connected'];
		session_write_close();
		session_start();

		(new ReflectionMethod(MCPController::class, 'discardRequestSession'))->invoke(null, true);

		$this->assertSame(self::ID, session_id(), 'the console session was ended by a request that never logged in');
		$this->assertSame(['auth_user' => 'admin', 'login_state' => 'connected'], $_SESSION);
		$this->assertSame(PHP_SESSION_ACTIVE, session_status());
		$this->assertFileExists($sDir.'/sess_'.self::ID);

		session_write_close();
		$this->assertStringContainsString('auth_user', (string)file_get_contents($sDir.'/sess_'.self::ID), 'the stored session lost what the console put in it');
		unlink($sDir.'/sess_'.self::ID);
		rmdir($sDir);
	}

	/**
	 * Where the decision is taken: before the first gate, cleared as the login begins, and
	 * handed to every path that ends the session. A source contract because serve() boots
	 * iTop and cannot be run here.
	 */
	public function testTheControllerKeepsTheSessionUntilTheLoginRuns(): void
	{
		$oSource = SourceCalls::OfClass(MCPController::class);
		$sServe = $oSource->Code('serve');
		$sDiscard = $oSource->Code('discardRequestSession');

		// Code() is the source with its comments and whitespace removed.
		$this->assertStringContainsString('$bKeepSession=RequestSession::CurrentIsResumed();', $sServe, 'serve() no longer records whose session this is');
		$iDecided = (int)strpos($sServe, '$bKeepSession=RequestSession::CurrentIsResumed();');
		$iGates = (int)strpos($sServe, 'foreach(self::GATESas');
		$iCleared = (int)strpos($sServe, '$bKeepSession=false;');
		$this->assertGreaterThan(0, $iGates, 'serve() no longer runs the gates in a loop');
		$this->assertLessThan($iGates, $iDecided, 'whose session this is must be decided before any gate runs');
		$this->assertGreaterThan($iGates, $iCleared, 'the session is the request\'s only once the login has run');
		$this->assertStringContainsString('if($sGate===LoginGate::class){$bKeepSession=false;}', $sServe, 'the flag must be cleared at the login gate and nowhere before it');
		$this->assertSame(1, substr_count($sServe, '$bKeepSession=false;'), 'the flag is cleared in one place only');

		$this->assertSame(3, substr_count($sServe, 'self::discardRequestSession($bKeepSession)'), 'the served, batch and error paths must each pass the flag');
		$this->assertStringContainsString("if(!\$bKeepSession&&session_id()!==''){", $sDiscard, 'the session is destroyed only when it is not being kept');
	}

	public function testTheSessionDiscardStillComesBeforeTheAnswer(): void
	{
		// The argument must not change what RequestPipelineOrderTest reads.
		$this->assertTrue(
			SourceCalls::OfClass(MCPController::class)->CallsInSequence(
				[RequestPipelineSource::Spec('MCPService::run'), RequestPipelineSource::Spec('discardRequestSession'), RequestPipelineSource::Spec('Emit')],
				'serve'
			)
		);
	}
}
