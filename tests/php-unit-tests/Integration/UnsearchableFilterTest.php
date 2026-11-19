<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-2: a filter on an attribute iTop's search cannot use (an image: `Person.picture`)
 * made DBObjectSearch::AddCondition() throw a CoreException outside any try, which the endpoint answered as a
 * server-side failure - with a reference, and, under PHP's default exception settings, a log line of 24 MB.
 * It is the caller's mistake and is answered as one, by the same words on both tools.
 */
class UnsearchableFilterTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidAttCode('Person', 'picture')) {
			$this->markTestSkipped('Needs Person.picture.');
		}
	}

	private function refusal(callable $fCall): string
	{
		try {
			$fCall();
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail('the filter was accepted');
	}

	public function testAFilterOnAnAttributeTheSearchCannotUseIsTheCallersError(): void
	{
		$sSearch = $this->refusal(fn () => ObjectSearchByClass::execute('Person', ['picture' => 'x']));
		$sCount = $this->refusal(fn () => ObjectCount::execute('Person', ['picture' => 'x']));

		$this->assertSame("Attribute 'picture' on class 'Person' cannot be used as a filter.", $sSearch);
		$this->assertSame($sSearch, $sCount);
		$this->assertStringNotContainsString('server-side', $sSearch);
		$this->assertStringNotContainsString('reference', $sSearch);
	}
}
