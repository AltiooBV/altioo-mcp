<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * What the schema says a relation means comes from the datamodel's dictionary, and a custom relation may have no entry:
 * Dict::S() then answers with the key itself, "Relation:<code>/DownStream", which a model would read as a label.
 */
class RelationTextTest extends TestCase
{
	/** @dataProvider textProvider */
	public function testADictionaryKeyWithNoEntryIsNull(string $sText, ?string $sExpected): void
	{
		$oMethod = new ReflectionMethod(DatamodelReader::class, 'relationText');
		$oMethod->setAccessible(true);

		$this->assertSame($sExpected, $oMethod->invoke(null, $sText));
	}

	/** @return array<string, array{0: string, 1: ?string}> */
	public static function textProvider(): array
	{
		return [
			'a label'                     => ['Outbound flows...', 'Outbound flows...'],
			'the key of a missing label'  => ['Relation:custom_rel/DownStream', null],
			'the key of a missing text'   => ['Relation:custom_rel/Description', null],
			'empty'                       => ['', null],
			'a label naming a relation'   => ['Relations: what impacts this', 'Relations: what impacts this'],
		];
	}
}
