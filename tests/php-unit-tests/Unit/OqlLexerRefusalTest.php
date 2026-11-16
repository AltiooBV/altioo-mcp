<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * iTop's OQL lexer refuses a character outside the grammar with a plain Exception ("Unexpected input
 * at line 1: ;"), because its own translation of that message never matches. That is the caller's
 * mistake and is told as one; every other exception stays an opaque failure (ObjectQuery::CheckQuery).
 */
class OqlLexerRefusalTest extends TestCase
{
	private function classify(string $sMessage): ?string
	{
		return (new ReflectionMethod(ObjectQuery::class, 'unexpectedCharacterIn'))->invoke(null, new Exception($sMessage));
	}

	/** @dataProvider lexerMessages */
	public function testTheLexersRefusalIsAnInvalidQueryNamingTheCharacter(string $sMessage, string $sExpected): void
	{
		$this->assertSame($sExpected, $this->classify($sMessage));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function lexerMessages(): array
	{
		return [
			'semicolon, as in "; DELETE FROM Organization"' => ['Unexpected input at line 1: ;', '";"'],
			'with the space iTop left in' => ['Unexpected input at line 1:  ;', '";"'],
			'hash' => ['Unexpected input at line 2: #', '"#"'],
			'backtick' => ['Unexpected input at line 1: `', '"`"'],
			'a control character is not echoed' => ["Unexpected input at line 1: \x01", '(outside the grammar)'],
		];
	}

	/** @dataProvider otherFailures */
	public function testAnythingElseStaysOpaque(string $sMessage): void
	{
		$this->assertNull($this->classify($sMessage));
	}

	/** @return array<string, array{0: string}> */
	public function otherFailures(): array
	{
		return [
			'a database failure' => ['Could not connect to database itop at 10.0.0.5 as admin'],
			'the lexer text inside something else' => ['Query failed. Unexpected input at line 1: ;'],
			'no character named' => ['Unexpected input at line 1:'],
			'empty' => [''],
		];
	}
}
