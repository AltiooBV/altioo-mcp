<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A mistyped security setting fails safe, not open.
 *
 * secure_mcp_services, mcp_read_only and log_mcp_service were compared
 * against true, so 'true' or 1 - what a hand-edited config file holds as often
 * as not - turned the profile check off, left the instance writable, and
 * stopped the audit, with nothing logged. mcp_capabilities or
 * mcp_enabled_toolsets written as a string instead of an array served
 * everything. Each is now read so that a value it cannot take as meant is
 * logged and answered with whichever reading refuses more.
 */
class SettingsFailSafeTest extends TestCase
{
	/**
	 * @dataProvider booleanProvider
	 */
	public function testABooleanIsTakenAsWritten(bool $bValue, bool $bSafe): void
	{
		$this->assertSame($bValue, MCPHelper::SwitchFrom('a_setting', $bValue, $bSafe));
	}

	/**
	 * @return array<string, array{0: bool, 1: bool}>
	 */
	public function booleanProvider(): array
	{
		return [
			'true, safe true'   => [true, true],
			'true, safe false'  => [true, false],
			'false, safe true'  => [false, true],
			'false, safe false' => [false, false],
		];
	}

	/**
	 * Every one of these was read as false by `=== true`, and 'false' and 0
	 * as true by `!== false`. Neither guess is made any more.
	 *
	 * @dataProvider mistypedProvider
	 */
	public function testAnythingElseIsReadAsTheSafeAnswer(mixed $mValue): void
	{
		$this->assertTrue(MCPHelper::SwitchFrom('a_setting', $mValue, true));
		$this->assertFalse(MCPHelper::SwitchFrom('a_setting', $mValue, false));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function mistypedProvider(): array
	{
		return [
			"'true'"  => ['true'],
			"'false'" => ['false'],
			'1'       => [1],
			'0'       => [0],
			"'yes'"   => ['yes'],
			"''"      => [''],
			'null'    => [null],
			'array'   => [[]],
		];
	}

	/**
	 * Which answer is the safe one, per switch. Pinned, because the safe
	 * answer is the whole of the fix and one flipped argument undoes it.
	 *
	 * @dataProvider switchProvider
	 */
	public function testEachSwitchFailsTowardsRefusing(string $sMethod, string $sSetting, string $sSafe): void
	{
		$sBody = $this->bodyOf(MCPHelper::class, $sMethod);

		$this->assertMatchesRegularExpression(
			'/ReadSwitch\(\s*self::'.$sSetting.'\s*,[^,]+,\s*'.$sSafe.'\s*\)/',
			$sBody,
			"MCPHelper::{$sMethod}() must read {$sSetting} through ReadSwitch() with {$sSafe} as the safe answer"
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function switchProvider(): array
	{
		return [
			'profile check on'     => ['IsAccessRestricted', 'MODULE_SETTING_SECURE', 'true'],
			'read-only'            => ['IsReadOnly', 'MODULE_SETTING_READ_ONLY', 'true'],
			'audit written'        => ['LogsCalls', 'MODULE_SETTING_LOG', 'true'],
			'no access admin'      => ['AllowsAccessAdministration', 'MODULE_SETTING_ALLOW_ACCESS_ADMINISTRATION', 'false'],
			'no escalation'        => ['AllowsPrivilegeEscalation', 'MODULE_SETTING_ALLOW_PRIVILEGE_ESCALATION', 'false'],
		];
	}

	/**
	 * The pattern the bug was made of: a setting read and compared against a
	 * boolean literal on the spot. Nothing in src/ may do it again.
	 */
	public function testNoSettingIsComparedToALiteralWhereItIsRead(): void
	{
		$sSrc = dirname(__DIR__, 3).'/src';
		$aOffenders = [];

		$oFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sSrc, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($oFiles as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sCode = $this->withoutComments((string)file_get_contents($oFile->getPathname()));
			if (preg_match('/GetModuleSetting\((?:[^;]*?)\)\s*[!=]==\s*(?:true|false)\b/i', $sCode) === 1) {
				$aOffenders[] = substr($oFile->getPathname(), strlen($sSrc) + 1);
			}
		}

		$this->assertSame([], $aOffenders, 'read a boolean setting through MCPHelper::ReadSwitch() instead');
	}

	public function testAnUnreadableAccessSettingServesNothing(): void
	{
		$oNothing = AccessPolicy::Nothing();

		foreach (AccessPolicy::CAPABILITIES as $sCapability) {
			$this->assertFalse($oNothing->allowsCapability($sCapability), "{$sCapability} must not be served");
		}
		$this->assertFalse($oNothing->allowsToolset('objects'));
	}

	/**
	 * A token holding everything still cannot widen it: narrowing is the only
	 * thing a token does to a policy.
	 */
	public function testATokenCannotWidenNothing(): void
	{
		$oNarrowed = AccessPolicy::Nothing()->narrowedBy(AccessPolicy::FromScopes(['MCP']));

		$this->assertFalse($oNarrowed->allowsCapability(AccessPolicy::CAPABILITY_READ));
		$this->assertFalse($oNarrowed->allowsToolset('objects'));
	}

	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((string)$oMethod->getFileName());

		return $this->withoutComments(implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		)));
	}

	private function withoutComments(string $sCode): string
	{
		$bOpen = str_starts_with($sCode, '<?php');
		$sStripped = '';
		foreach (token_get_all($bOpen ? $sCode : '<?php '.$sCode) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sStripped .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sStripped;
	}
}
