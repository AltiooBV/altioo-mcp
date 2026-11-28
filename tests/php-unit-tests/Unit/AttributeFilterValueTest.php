<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Read\AttributeDescription;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/** Retest of 2026-11-28, B12: a filter takes a code or its label, and nothing else. The list is iTop's; these are the rules. */
class AttributeFilterValueTest extends TestCase
{
	private const STATUS = ['new' => 'New', 'assigned' => 'Assigned', 'escalated_tto' => 'TTO escalation'];

	/** A stopwatch's `passed`, as GetSubItemAllowedValues() answers it: PHP keys it 0 and 1. */
	private const YES_NO = [0 => 'no', 1 => 'yes'];

	/** @return array<string, array{0: array<array-key, string>, 1: mixed, 2: mixed}> */
	public static function named(): array
	{
		return [
			'a code'                        => [self::STATUS, 'new', 'new'],
			'a label'                       => [self::STATUS, 'TTO escalation', 'escalated_tto'],
			'a label in another case'       => [self::STATUS, 'tto ESCALATION', 'escalated_tto'],
			'a code in another case'        => [self::STATUS, 'NEW', 'new'],
			'spaces around it'              => [self::STATUS, ' assigned ', 'assigned'],
			'a number that is a code'       => [self::YES_NO, 1, 1],
			'a string that is a code'       => [self::YES_NO, '0', '0'],
			'the label yes'                 => [self::YES_NO, 'yes', 1],
			'the label No'                  => [self::YES_NO, 'No', 0],
			'JSON true'                     => [self::YES_NO, true, 1],
			'JSON false'                    => [self::YES_NO, false, 0],
			'a priority by its label'       => [[1 => 'critical', 4 => 'low'], 'Low', 4],
			'a code that is another label'  => [['Change' => 'Normal change', 'CMDBChange' => 'Change'], 'Change', 'Change'],
		];
	}

	/**
	 * @dataProvider named
	 *
	 * @param array<array-key, string> $aValues
	 */
	public function testACodeOrItsLabelNamesOneValue(array $aValues, mixed $mSent, mixed $mCode): void
	{
		$this->assertSame($mCode, AttributeDescription::MatchingValue($aValues, $mSent));
	}

	/** @return array<string, array{0: array<array-key, string>, 1: mixed}> */
	public static function unnamed(): array
	{
		return [
			'a word'                            => [self::STATUS, 'nonsense'],
			'a part of a label'                 => [self::STATUS, 'escalation'],
			'nothing'                           => [self::STATUS, ''],
			'blank'                             => [self::STATUS, '   '],
			'a number that is no code'          => [self::YES_NO, 2],
			'a fraction'                        => [self::YES_NO, 0.5],
			'the word true'                     => [self::YES_NO, 'true'],
			'JSON true where no yes/no is'      => [self::STATUS, true],
			'a list'                            => [self::STATUS, ['new']],
			'null'                              => [self::STATUS, null],
			'a word that stands for two values' => [['a' => 'Same', 'b' => 'same'], 'SAME'],
		];
	}

	/**
	 * @dataProvider unnamed
	 *
	 * @param array<array-key, string> $aValues
	 */
	public function testAnythingElseNamesNone(array $aValues, mixed $mSent): void
	{
		$this->assertNull(AttributeDescription::MatchingValue($aValues, $mSent));
	}

	public function testTheRefusalNamesEachCodeAndTheLabelThatIsAnotherWord(): void
	{
		$this->assertSame(': new, assigned, escalated_tto (TTO escalation).', AttributeDescription::NamingValues(self::STATUS));
		$this->assertSame(': 0 (no), 1 (yes).', AttributeDescription::NamingValues(self::YES_NO));
		$this->assertSame(': a, b.', AttributeDescription::NamingValues(['a' => '', 'b' => 'B']));
	}

	public function testAListTooLongToNameSaysWhereToReadIt(): void
	{
		$aValues = [];
		for ($i = 0; $i <= AttributeDescription::MAX_ALLOWED_VALUES; $i++) {
			$aValues['c'.$i] = 'L'.$i;
		}

		$sEnd = AttributeDescription::NamingValues($aValues);

		$this->assertStringContainsString('core_class_schema', $sEnd);
		$this->assertStringContainsString((string)count($aValues), $sEnd);
		$this->assertStringNotContainsString('c1', $sEnd);
	}
}
