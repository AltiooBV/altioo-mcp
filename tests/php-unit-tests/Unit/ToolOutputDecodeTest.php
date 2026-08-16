<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * Opening the envelope a core tool returned, so a subclass can add to it.
 *
 * The three shapes are the three a core tool actually returns today, and the
 * point of the helper is that a pack extending one of them does not have to
 * know which it got.
 */
class ToolOutputDecodeTest extends TestCase
{
	public function testAPlainArrayIsAlreadyThePayload(): void
	{
		$this->assertSame(['id' => 1], ToolOutput::Decode(['id' => 1]));
	}

	public function testATextContentIsDecoded(): void
	{
		$this->assertSame(['id' => 1, 'name' => 'x'], ToolOutput::Decode(ToolOutput::Json(['id' => 1, 'name' => 'x'])));
	}

	public function testAStructuredResultIsReadFromTheHalfAlreadyDecoded(): void
	{
		$this->assertSame(['id' => 1, 'ok' => true], ToolOutput::Decode(ToolOutput::Structured(['id' => 1, 'ok' => true])));
	}

	public function testARoundTripSurvivesTheCharactersJsonWouldEscape(): void
	{
		$aData = ['url' => 'https://example.test/a/b', 'text' => 'café — naïve'];

		$this->assertSame($aData, ToolOutput::Decode(ToolOutput::Json($aData)));
	}

	public function testAJsonScalarSaysWhyItCannotBeExtended(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('JSON scalar');

		ToolOutput::Decode(ToolOutput::Json('just a string'));
	}

	public function testTextThatIsNotJsonIsRefusedLegibly(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('not JSON');

		ToolOutput::Decode(new TextContent('plain prose, not a payload'));
	}

	public function testSomethingElseEntirelyNamesWhatItGot(): void
	{
		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('no readable payload');

		ToolOutput::Decode(42);
	}
}
