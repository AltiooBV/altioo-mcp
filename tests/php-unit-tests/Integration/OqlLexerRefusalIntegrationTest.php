<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Server\Exception\ToolCallException;

require_once __DIR__.'/../bootstrap.php';

/** The query that carries a stray ';' is answered as an invalid query, through iTop's real lexer. */
class OqlLexerRefusalIntegrationTest extends ItopDataTestCaseAlias
{
	public function testASemicolonIsAnInvalidQueryNotAnInternalError(): void
	{
		try {
			ObjectQuery::CheckQuery('SELECT Organization; DELETE FROM Organization');
			$this->fail('the query must be refused');
		} catch (ToolCallException $e) {
			$this->assertStringStartsWith('Invalid OQL query. Reason: ', $e->getMessage());
			$this->assertStringNotContainsString('server-side', $e->getMessage());
			$this->assertStringNotContainsString('reference', strtolower($e->getMessage()));
		}
	}

	public function testAValidQueryStillPasses(): void
	{
		ObjectQuery::CheckQuery('SELECT Organization');
		$this->addToAssertionCount(1);
	}
}
