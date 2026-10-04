<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A token iTop accepts and this module never saw.
 *
 * authent-token reads Auth-Token and, without it, the auth_token request
 * parameter. This module read the header only. Where allowed_login_types
 * consults token before basic, a token sent as ?auth_token=... beside any
 * non-Bearer Authorization header - enough to pass the credential check -
 * logged in, was taken for a request without a token,
 * and was served the instance-wide policy: a read-only or single-toolset token
 * could write, and the audit row named no token.
 *
 * Two refusals close it, and each is pinned here: the parameter is refused
 * before the login, and a token login this module cannot account for is
 * refused after it, from what iTop itself records.
 */
class TokenOutsideTheHeadersTest extends TestCase
{
	/** @var array<string, mixed> */
	private array $aServerBackup = [];

	/** @var array<string, mixed> */
	private array $aGetBackup = [];

	/** @var array<string, mixed> */
	private array $aPostBackup = [];

	/** @var array<string, mixed> */
	private array $aRequestBackup = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->aServerBackup = $_SERVER;
		$this->aGetBackup = $_GET;
		$this->aPostBackup = $_POST;
		$this->aRequestBackup = $_REQUEST;
		unset($_SERVER['HTTP_AUTH_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
		$_GET = [];
		$_POST = [];
		$_REQUEST = [];
		MCPHttp::ForgetAuthToken();
	}

	protected function tearDown(): void
	{
		MCPHttp::ForgetAuthToken();
		$_SERVER = $this->aServerBackup;
		$_GET = $this->aGetBackup;
		$_POST = $this->aPostBackup;
		$_REQUEST = $this->aRequestBackup;
		parent::tearDown();
	}

	public function testATokenInTheQueryStringIsSeen(): void
	{
		$_GET['auth_token'] = 'abc123';
		$_REQUEST['auth_token'] = 'abc123';

		$this->assertTrue(MCPHttp::CarriesATokenAsAParameter());
	}

	public function testATokenInTheFormBodyIsSeen(): void
	{
		$_POST['auth_token'] = 'abc123';

		$this->assertTrue(MCPHttp::CarriesATokenAsAParameter());
	}

	/**
	 * $_REQUEST is what utils::ReadParam() reads, and request_order decides
	 * what goes into it - a cookie, on a php.ini that says so.
	 */
	public function testWhateverReadParamWouldReadIsSeen(): void
	{
		$_REQUEST['auth_token'] = 'abc123';

		$this->assertTrue(MCPHttp::CarriesATokenAsAParameter());
	}

	/**
	 * An empty value is refused as well. iTop would not log it in, but the
	 * rule is "not there", and "not there unless empty" is a rule someone has
	 * to re-derive.
	 */
	public function testAnEmptyParameterStillCounts(): void
	{
		$_GET['auth_token'] = '';

		$this->assertTrue(MCPHttp::CarriesATokenAsAParameter());
	}

	public function testARequestWithoutTheParameterIsLeftAlone(): void
	{
		$_GET['other'] = 'x';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';

		$this->assertFalse(MCPHttp::CarriesATokenAsAParameter());
	}

	/**
	 * The case itself: iTop logged a token in, and the headers this module
	 * reads held none.
	 *
	 * @dataProvider tokenModeProvider
	 */
	public function testATokenLoginWithoutAHeaderTokenIsNotAccountedFor(string $sMode): void
	{
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertFalse(TokenScopes::LoginIsAccountedFor($sMode));
	}

	/**
	 * @dataProvider tokenModeProvider
	 */
	public function testATokenLoginWithAHeaderTokenIsAccountedFor(string $sMode): void
	{
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertTrue(TokenScopes::LoginIsAccountedFor($sMode));
	}

	/**
	 * Both names authent-token connects under. rest-token is the same class
	 * registered a second time, and reads the parameter the same way.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function tokenModeProvider(): array
	{
		return [
			'token'      => ['token'],
			'rest-token' => ['rest-token'],
		];
	}

	/**
	 * Basic, form, external: no token, nothing to narrow with, nothing to
	 * account for. A session that recorded no mode at all is the same.
	 *
	 * @dataProvider otherModeProvider
	 */
	public function testOtherLoginsNeedNoToken(mixed $mMode): void
	{
		MCPHttp::PromoteBearerToAuthToken();

		$this->assertTrue(TokenScopes::LoginIsAccountedFor($mMode));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function otherModeProvider(): array
	{
		return [
			'basic'    => ['basic'],
			'form'     => ['form'],
			'external' => ['external'],
			'none'     => [null],
		];
	}

	/**
	 * The parameter has to be refused before the session reset, like every
	 * other refusal that needs no login: reaching ResetSession() is enough to
	 * end a console user's session.
	 */
	public function testTheParameterIsRefusedBeforeTheSessionIsReset(): void
	{
		$this->assertComesBefore('rejectATokenSentAsAParameter', 'ResetSession');
	}

	/**
	 * After the login, because the mode it reads is what the login recorded;
	 * before the policy, because the policy is what it protects.
	 */
	public function testTheLoginIsAccountedForBeforeThePolicyIsDecided(): void
	{
		$this->assertComesBefore('DoLogin', 'rejectATokenLoginNotAccountedFor');
		$this->assertComesBefore('rejectATokenLoginNotAccountedFor', 'AccessPolicyOfCurrentRequest');
	}

	private function assertComesBefore(string $sFirst, string $sSecond): void
	{
		$oMethod = new ReflectionMethod(MCPController::class, 'handleRequest');
		$aLines = file((string)$oMethod->getFileName());
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

		$iFirst = strpos($sCode, $sFirst);
		$iSecond = strpos($sCode, $sSecond);

		$this->assertIsInt($iFirst, "handleRequest() no longer calls {$sFirst}");
		$this->assertIsInt($iSecond, "handleRequest() no longer calls {$sSecond}");
		$this->assertLessThan($iSecond, $iFirst, "{$sFirst} must run before {$sSecond}");
	}
}
