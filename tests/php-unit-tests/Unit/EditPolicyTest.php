<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\EditPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a write may change: doc/adr/0004.
 *
 * Most of EditPolicy reads the compiled lifecycle and is exercised by
 * EditPolicyIntegrationTest. Two things are not: which case log values are a
 * new entry, which is a decision about a shape, and that every write path asks
 * at all - a policy that one tool forgets is a policy with a way round it.
 */
class EditPolicyTest extends TestCase
{
	private const ROOT = __DIR__.'/../../..';

	/** Every tool that turns a caller's fields into attribute values. */
	private const WRITE_PATHS = [
		'src/Core/Tools/ObjectCreate.php',
		'src/Core/Tools/ObjectUpdate.php',
		'src/Core/Tools/ObjectApplyStimulus.php',
		'src/Abstract/AbstractBulkTool.php',
	];

	/** Where values are Set() and the external keys can then be judged. */
	private const SETTERS = [
		'src/Core/Tools/ObjectCreate.php',
		'src/Core/Tools/ObjectUpdate.php',
		'src/Core/Tools/ObjectApplyStimulus.php',
		'src/Core/Tools/ObjectBulkCreate.php',
		'src/Core/Tools/ObjectBulkUpdate.php',
	];

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function newEntries(): array
	{
		return [
			'text'                  => ['A reply'],
			'nothing'               => [null],
			'an add_item'           => [['add_item' => ['message' => 'A reply']]],
			'an add_item in a format' => [['add_item' => ['message' => 'A reply', 'format' => 'text']]],
			'as decoded objects'    => [(object) ['add_item' => (object) ['message' => 'A reply']]],
		];
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function rewrites(): array
	{
		return [
			'a backdated entry'          => [['add_item' => ['message' => 'A reply', 'date' => '2001-01-01 00:00:00']]],
			'an entry signed by another' => [['add_item' => ['message' => 'A reply', 'user_id' => 1, 'user_login' => 'admin']]],
			'the whole log replaced'     => [['entries' => [['message' => 'The only entry left']]]],
			'an add_item beside more'    => [['add_item' => ['message' => 'A reply'], 'entries' => []]],
			'a number'                   => [42],
		];
	}

	/**
	 * @dataProvider newEntries
	 */
	public function testANewEntryIsAccepted(mixed $value): void
	{
		$this->assertNull(self::caseLogRefusal($value));
	}

	/**
	 * @dataProvider rewrites
	 */
	public function testAnythingButANewEntryIsRefused(mixed $value): void
	{
		$sRefusal = self::caseLogRefusal($value);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('add_item', $sRefusal, 'the refusal says what would be accepted');
	}

	/**
	 * RefusalFor() before the value is converted, RefusalForLinkSet() after:
	 * in the same function as every RestUtils::MakeValue().
	 */
	public function testEveryWritePathAsksThePolicy(): void
	{
		foreach (self::WRITE_PATHS as $sPath) {
			$sSource = self::code($sPath);
			$iMake = strpos($sSource, 'RestUtils::MakeValue(');
			$this->assertIsInt($iMake, "{$sPath} no longer converts values; update this list");

			$sBefore = substr($sSource, 0, $iMake);
			$sFunction = substr($sBefore, (int) strrpos($sBefore, 'function '));
			$this->assertStringContainsString('EditPolicy::RefusalFor(', $sFunction, "{$sPath} converts a value without asking EditPolicy first");

			$sAfter = substr($sSource, $iMake, 1500);
			$this->assertStringContainsString('EditPolicy::RefusalForLinkSet(', $sAfter, "{$sPath} hands a link set to the ORM without asking EditPolicy");
		}
	}

	/**
	 * RefusalsForExternalKeys() after the values are Set() and before the
	 * object is checked or written.
	 */
	public function testEveryWritePathJudgesTheKeysOnceTheValuesAreSet(): void
	{
		foreach (self::SETTERS as $sPath) {
			$sSource = self::code($sPath);
			$iKeys = strpos($sSource, 'EditPolicy::RefusalsForExternalKeys(');
			$this->assertIsInt($iKeys, "{$sPath} never judges its external keys against their filter");

			$iSet = strpos($sSource, '->Set($sAttCode,');
			$iCheck = strpos($sSource, 'WritePlan::Check(');
			$this->assertIsInt($iSet);
			$this->assertIsInt($iCheck);
			$this->assertGreaterThan($iSet, $iKeys, "{$sPath} judges the keys before the values are Set()");
			$this->assertLessThan($iCheck, $iKeys, "{$sPath} judges the keys after the write was checked");
		}
	}

	private static function caseLogRefusal(mixed $value): ?string
	{
		return (new ReflectionMethod(EditPolicy::class, 'caseLogRefusal'))->invoke(null, 'public_log', $value);
	}

	/** The source without its comments, so a mention in a docblock is not a call. */
	private static function code(string $sPath): string
	{
		$sCode = '';
		foreach (token_get_all((string) file_get_contents(self::ROOT.'/'.$sPath)) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
