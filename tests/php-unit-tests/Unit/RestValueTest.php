<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\RestValue;
use PHPUnit\Framework\TestCase;
use stdClass;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The array/stdClass distinction RestUtils branches on.
 *
 * The MCP SDK decodes inbound JSON with `json_decode($input, true)`, which
 * flattens objects and arrays onto the same PHP type. iTop's REST layer tells
 * them apart - `is_object()` means "search criteria", `$json->add_item` means
 * "append to this caselog" - so it has to be told apart again before
 * RestUtils::MakeValue() sees the value.
 */
class RestValueTest extends TestCase
{
	/** @return array<string, array{0: mixed}> */
	public static function scalarProvider(): array
	{
		return [
			'null' => [null],
			'int' => [42],
			'float' => [1.5],
			'bool' => [true],
			'string' => ['New comment on the ticket'],
			'numeric string' => ['42'],
			'empty string' => [''],
		];
	}

	/**
	 * A plain-string caselog append and an ext-key given as an id must keep
	 * their exact type: MakeValue() dispatches on it.
	 *
	 * @dataProvider scalarProvider
	 */
	public function testScalarsPassThroughUnchanged(mixed $value): void
	{
		$this->assertSame($value, RestValue::FromDecodedJson($value));
	}

	/**
	 * `{"add_item": {"message": "..."}}` reaches AttributeCaseLog as an array,
	 * where `isset($json->add_item)` is false and the append is silently
	 * dropped.
	 */
	public function testAJsonObjectBecomesAnStdClass(): void
	{
		$value = RestValue::FromDecodedJson(['add_item' => ['message' => 'Investigating']]);

		$this->assertInstanceOf(stdClass::class, $value);
		$this->assertTrue(isset($value->add_item));
		$this->assertInstanceOf(stdClass::class, $value->add_item);
		$this->assertSame('Investigating', $value->add_item->message);
	}

	/**
	 * Ext-key search criteria: RestUtils::FindObjectFromKey() reads an object
	 * as criteria and anything else as an id or an OQL string.
	 */
	public function testSearchCriteriaBecomeAnObject(): void
	{
		$value = RestValue::FromDecodedJson(['name' => 'Demo', 'org_id' => 3]);

		$this->assertIsObject($value);
		$this->assertSame('Demo', $value->name);
		$this->assertSame(3, $value->org_id);
	}

	/**
	 * Link sets and tag sets are rejected by MakeValue() unless they arrive as
	 * PHP arrays, so lists must stay lists.
	 */
	public function testAJsonListStaysAnArray(): void
	{
		$value = RestValue::FromDecodedJson(['red', 'green']);

		$this->assertIsArray($value);
		$this->assertSame(['red', 'green'], $value);
	}

	/**
	 * A link set is a list of objects: the list stays an array, each entry
	 * becomes an object, which is what MakeObjectFromFields() iterates.
	 */
	public function testAListOfObjectsKeepsBothShapes(): void
	{
		$value = RestValue::FromDecodedJson([
			['role_id' => 1, 'contact_id' => 7],
			['role_id' => 2, 'contact_id' => 9],
		]);

		$this->assertIsArray($value);
		$this->assertCount(2, $value);
		$this->assertIsObject($value[0]);
		$this->assertSame(7, $value[0]->contact_id);
	}

	public function testNestingIsPreservedToTheBottom(): void
	{
		$value = RestValue::FromDecodedJson([
			'add_item' => [
				'message' => 'Nested',
				'links' => [['id' => 1]],
			],
		]);

		$this->assertIsObject($value->add_item);
		$this->assertIsArray($value->add_item->links);
		$this->assertIsObject($value->add_item->links[0]);
	}

	public function testAWholeFieldsMapIsConvertedKeysKept(): void
	{
		$aFields = RestValue::FromDecodedJsonFields([
			'title' => 'Printer down',
			'caller_id' => ['name' => 'Doe', 'first_name' => 'John'],
			'tags' => ['urgent'],
		]);

		$this->assertSame(['title', 'caller_id', 'tags'], array_keys($aFields));
		$this->assertSame('Printer down', $aFields['title']);
		$this->assertIsObject($aFields['caller_id']);
		$this->assertIsArray($aFields['tags']);
	}

	/**
	 * Callers wrap MakeValue() in a try/catch that reports the offending
	 * attribute, so a value that cannot round-trip must throw rather than
	 * quietly become null.
	 */
	public function testUnencodableValueThrows(): void
	{
		$this->expectException(\JsonException::class);

		RestValue::FromDecodedJson(['broken' => "\xB1\x31"]);
	}
}
