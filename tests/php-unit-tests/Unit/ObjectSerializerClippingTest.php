<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The ceilings on the attribute kinds that have no natural size.
 *
 * A five-year-old incident carries hundreds of case log entries, a rack
 * carries hundreds of devices, and one description can hold an entire email
 * thread. Read broadly, any of the three is larger than everything else in the
 * response put together.
 *
 * Cutting them is only defensible if the cut is visible and reversible, which
 * is what these tests pin: the value says what was left out, and the caller is
 * told how to read the rest.
 *
 * The three are pure functions over already-converted values, so no iTop.
 */
class ObjectSerializerClippingTest extends TestCase
{
	public function testShortValuesAreLeftExactlyAsTheyAre(): void
	{
		$this->assertSame('open', $this->clip('open'));
		$this->assertSame(42, $this->clip(42));
		$this->assertNull($this->clip(null));
		$this->assertSame(['a' => 'b'], $this->clip(['a' => 'b']));
	}

	public function testALongTextIsCutAndSaysSo(): void
	{
		$sText = str_repeat('x', ObjectSerializer::MAX_TEXT_CHARS + 500);

		$sClipped = $this->clip($sText);

		$this->assertStringStartsWith(str_repeat('x', ObjectSerializer::MAX_TEXT_CHARS), $sClipped);
		$this->assertStringContainsString('500 more characters', $sClipped);
		// The way out has to travel with the value: a model that only ever
		// sees the truncated text is the one that needs to know.
		$this->assertStringContainsString('output_fields', $sClipped);
	}

	public function testTextIsMeasuredInCharactersNotBytes(): void
	{
		// 'é' is two bytes; cutting on bytes would both cut early and risk
		// splitting the last character in half, which is not valid UTF-8 and
		// would take the whole json_encode down with it.
		$sText = str_repeat('é', ObjectSerializer::MAX_TEXT_CHARS + 10);

		$sClipped = $this->clip($sText);

		$this->assertStringContainsString('10 more characters', $sClipped);
		$this->assertSame(1, preg_match('//u', $sClipped), 'the cut produced invalid UTF-8');
	}

	public function testLongTextIsCutWhereverItSits(): void
	{
		$aValue = ['entries' => [['message' => str_repeat('x', ObjectSerializer::MAX_TEXT_CHARS + 1)]]];

		$aClipped = $this->clip($aValue);

		$this->assertStringContainsString('1 more characters', $aClipped['entries'][0]['message']);
	}

	public function testACaseLogKeepsItsMostRecentEntries(): void
	{
		// GetForJSON() returns them oldest first, so "recent" is the tail.
		$aEntries = [];
		for ($i = 1; $i <= ObjectSerializer::MAX_CASELOG_ENTRIES + 5; $i++) {
			$aEntries[] = ['message' => "entry {$i}"];
		}

		$aClipped = $this->recentEntries(['entries' => $aEntries]);

		$this->assertCount(ObjectSerializer::MAX_CASELOG_ENTRIES, $aClipped['entries']);
		$this->assertSame('entry '.(ObjectSerializer::MAX_CASELOG_ENTRIES + 5), end($aClipped['entries'])['message']);
		$this->assertSame(5, $aClipped['entries_omitted']);
		$this->assertSame(ObjectSerializer::MAX_CASELOG_ENTRIES + 5, $aClipped['entries_total']);
	}

	public function testAShortCaseLogIsUntouched(): void
	{
		$aValue = ['entries' => [['message' => 'the only entry']]];

		$this->assertSame($aValue, $this->recentEntries($aValue));
	}

	public function testALinkSetIsCappedAndCounted(): void
	{
		$aLinks = array_fill(0, ObjectSerializer::MAX_LINKS + 3, ['id' => 1]);

		$aClipped = $this->firstLinks($aLinks);

		$this->assertCount(ObjectSerializer::MAX_LINKS, $aClipped['links']);
		$this->assertSame(3, $aClipped['links_omitted']);
		$this->assertSame(ObjectSerializer::MAX_LINKS + 3, $aClipped['links_total']);
	}

	/**
	 * A link set that fits keeps its shape - a list, not an object with a
	 * 'links' key - because that is what every caller already parses.
	 */
	public function testAShortLinkSetKeepsItsShape(): void
	{
		$aLinks = [['id' => 1], ['id' => 2]];

		$this->assertSame($aLinks, $this->firstLinks($aLinks));
	}

	/** @return mixed */
	private function clip($value)
	{
		return (new ReflectionMethod(ObjectSerializer::class, 'clip'))->invoke(null, $value);
	}

	/** @return mixed */
	private function recentEntries($value)
	{
		return (new ReflectionMethod(ObjectSerializer::class, 'recentEntries'))->invoke(null, $value);
	}

	/** @return mixed */
	private function firstLinks($value)
	{
		return (new ReflectionMethod(ObjectSerializer::class, 'firstLinks'))->invoke(null, $value);
	}
}
