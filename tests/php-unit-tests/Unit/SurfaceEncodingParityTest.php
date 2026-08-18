<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\JsonPayload;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ResourceReadException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * The same data, read through a tool and through a resource, comes back
 * spelled the same way.
 *
 * Both surfaces serve the datamodel - core_class_list and itop://core/classes
 * are the same list, core_class_schema and itop://core/class/{class} the same
 * description - and they used to encode it differently: the tool through
 * ToolOutput with four flags, the resource through a bare json_encode(). A
 * caller comparing the two saw escaped slashes on one side and not the other,
 * and a model told to prefer whichever surface its client supports would get
 * two different-looking answers to the same question.
 *
 * Guarding the parity here rather than in each element, because the thing that
 * must not drift is the encoder, and there is now only one.
 */
class SurfaceEncodingParityTest extends TestCase
{
	/** @return array<string, array{0: mixed}> */
	public function payloadProvider(): array
	{
		return [
			'a URL, which default json_encode would escape'   => [['source' => 'https://example.test/a/b']],
			'accented text, which default json_encode escapes' => [['label' => 'Équipement réseau — café']],
			'the class-list envelope'                          => [['category' => '', 'filter' => '', 'total' => 0, 'classes' => []]],
			'nested structure'                                 => [['a' => ['b' => [1, 2, 3]], 'c' => null, 'd' => true]],
		];
	}

	/**
	 * @dataProvider payloadProvider
	 *
	 * @param mixed $mData
	 */
	public function testBothSurfacesEmitTheSameBytes($mData): void
	{
		$this->assertSame(
			ToolOutput::Json($mData)->text,
			ResourceOutput::Json($mData),
			'A tool and a resource encoded the same payload differently.'
		);
	}

	public function testSlashesAndUnicodeSurviveAsThemselves(): void
	{
		$sJson = ResourceOutput::Json(['url' => 'https://example.test/a/b', 'label' => 'café']);

		$this->assertStringContainsString('https://example.test/a/b', $sJson);
		$this->assertStringContainsString('café', $sJson);
	}

	public function testNothingIsPrettyPrinted(): void
	{
		$this->assertStringNotContainsString("\n", ResourceOutput::Json(['a' => ['b' => 1]]));
	}

	/**
	 * The defect this replaced: json_encode() returns false on a value it
	 * cannot encode, and a resource returning false handed a boolean to the
	 * SDK, which answered "unhandled type: boolean" without naming the
	 * resource. A refusal that says what went wrong is the point.
	 */
	public function testAnUnencodableResourceIsRefusedRatherThanReturnedAsFalse(): void
	{
		$this->expectException(ResourceReadException::class);
		$this->expectExceptionMessage('could not be encoded');

		ResourceOutput::Json(['broken' => NAN]);
	}

	public function testInvalidUtf8IsSubstitutedRatherThanFailing(): void
	{
		// A label out of a hand-edited datamodel or a legacy dictionary. Bare
		// json_encode() answers false here; both surfaces substitute instead,
		// so one bad byte does not take the whole class list down.
		$sJson = ResourceOutput::Json(['label' => "caf\xE9"]);

		$this->assertSame($sJson, ToolOutput::Json(['label' => "caf\xE9"])->text);
		$this->assertNotSame('', $sJson);
	}

	public function testTheSharedEncoderIsWhatBothCall(): void
	{
		$aData = ['x' => 'a/b', 'y' => 'é'];

		$this->assertSame(JsonPayload::Encode($aData), ResourceOutput::Json($aData));
		$this->assertSame(JsonPayload::Encode($aData), ToolOutput::Json($aData)->text);
	}
}
