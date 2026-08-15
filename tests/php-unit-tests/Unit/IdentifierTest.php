<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\Identifier;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The shape of the names clients call.
 *
 * snake_case is what every MCP server in the ecosystem uses, so it is what a
 * model has seen thousands of examples of. Deriving it from the class name is
 * what keeps a pack author from having to think about it - which also means a
 * regression here renames somebody's tools silently.
 */
class IdentifierTest extends TestCase
{
	/**
	 * @dataProvider classNameProvider
	 */
	public function testAClassNameBecomesASnakeCaseIdentifier(string $sClassName, string $sExpected): void
	{
		$this->assertSame($sExpected, Identifier::SnakeCase($sClassName));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function classNameProvider(): array
	{
		return [
			'two words'            => ['ClassList', 'class_list'],
			'several words'        => ['ObjectApplyStimulus', 'object_apply_stimulus'],
			// The case that makes the naive regex wrong: split on every
			// upper-case character and OQL comes out as o_q_l.
			'trailing acronym'     => ['ObjectSearchByOQL', 'object_search_by_oql'],
			'leading acronym'      => ['OQLSearch', 'oql_search'],
			'embedded acronym'     => ['ParseOQLQuery', 'parse_oql_query'],
			'already lower'        => ['version', 'version'],
			'single word'          => ['Version', 'version'],
			'digits stay attached' => ['Rfc9728Metadata', 'rfc9728_metadata'],
		];
	}

	/**
	 * The convention is only a convention if nothing in the shipped surface
	 * quietly breaks it.
	 */
	public function testEveryCoreIdentifierFollowsTheConvention(): void
	{
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();

		$aNames = array_merge(
			array_keys(MCPRegistry::GetTools()),
			array_keys(MCPRegistry::GetPrompts())
		);

		foreach (MCPRegistry::GetResources() as $oResource) {
			$aNames[] = $oResource->getQualifiedName();
		}
		foreach (MCPRegistry::GetResourceTemplates() as $oTemplate) {
			$aNames[] = $oTemplate->getQualifiedName();
		}

		MCPRegistry::Clear();

		foreach ($aNames as $sName) {
			$this->assertMatchesRegularExpression(
				'/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/',
				$sName,
				"{$sName} is not snake_case"
			);
		}
	}
}
