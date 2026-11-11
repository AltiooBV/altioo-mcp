<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\Gate\AuthRefusal;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Every way the login can refuse gets an answer written for the person who has
 * to fix it.
 *
 * handleRequest() calls DoLogin(false, false, EXIT_RETURN), so three of iTop's
 * exit codes are reachable: the credential was wrong, the user holds none of
 * the configured MCP profiles, or the user has no console at all. The last one
 * is the trap - it is what a portal-only account gets, granting "MCP Services
 * User" to one is an ordinary mistake, and left to the default branch it would
 * land as "Unknown authentication error (retCode=5)" with nothing in the log.
 *
 * A source scan rather than a behavioural test for the same reason as
 * RequestPipelineOrderTest: createAuthException() is private, its cases are
 * LoginWebPage constants that do not exist without iTop, and a unit suite that
 * boots neither can still hold the switch to naming them.
 */
class AuthRefusalCoverageTest extends TestCase
{
	/**
	 * The exit codes DoLogin(false, false, ...) can return, and what each one
	 * means to the operator reading the refusal.
	 *
	 * EXIT_CODE_MUSTBEADMIN is deliberately absent: it needs $bMustBeAdmin,
	 * which this endpoint does not pass. If that ever changes, this list is
	 * where it gets noticed.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function refusalProvider(): array
	{
		return [
			'wrong credentials' => [
				'EXIT_CODE_WRONGCREDENTIALS',
				'the token or password did not authenticate',
			],
			'no MCP profile' => [
				'EXIT_CODE_NOTAUTHORIZED',
				'the user holds none of the profiles in mcp_allowed_profiles',
			],
			'no console' => [
				'EXIT_CODE_PORTALUSERNOTAUTHORIZED',
				'the user is portal-only, so this endpoint has nothing to serve them',
			],
		];
	}

	/**
	 * @dataProvider refusalProvider
	 */
	public function testEveryReachableRefusalIsNamed(string $sCode, string $sMeaning): void
	{
		$this->assertTrue(
			SourceCalls::OfClass(AuthRefusal::class)->HasConstant('LoginWebPage::'.$sCode, 'CreateAuthException'),
			"createAuthException() no longer names {$sCode}, so ".$sMeaning
			.' is answered by the default branch: a retCode the caller cannot act on'
			.' and no log line for the operator.'
		);
	}

	/**
	 * The default branch stays, and stays last.
	 *
	 * It is the honest answer for an exit code iTop adds after this was
	 * written; what it must not become again is the answer for a code that was
	 * reachable all along.
	 */
	public function testTheDefaultBranchIsStillThere(): void
	{
		$this->assertStringContainsString(
			'default:',
			$this->switchBody(),
			'createAuthException() must still answer an exit code it has never seen.'
		);
	}

	/**
	 * The body of AuthRefusal::CreateAuthException(), comments stripped - otherwise a case
	 * mentioned only in a comment would satisfy the search.
	 */
	private function switchBody(): string
	{
		return SourceCalls::OfClass(AuthRefusal::class)->Code('CreateAuthException');
	}
}
