<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Abstract\AbstractBulkTool;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The log names the bulk sibling that a kill-switch entry left callable.
 *
 * A red-team pass listed core_object_delete in mcp_disabled_tools and then
 * planned a deletion through core_object_bulk_delete. The setting worked as
 * written; the operator's reading of it did not. These pin the pairing that
 * the warning is built on, using the real core tools, so a renamed tool or a
 * bulk tool that stops extending AbstractBulkTool breaks a test rather than
 * silently ending the hint.
 */
class BulkSiblingHintTest extends TestCase
{
	public function testDisablingASingleToolNamesItsBulkSibling(): void
	{
		$this->assertSame(
			['core_object_delete' => 'core_object_bulk_delete'],
			self::leftOn(['core_object_delete'])
		);
	}

	public function testEveryCorePairIsFound(): void
	{
		$this->assertSame(
			[
				'core_object_create' => 'core_object_bulk_create',
				'core_object_update' => 'core_object_bulk_update',
				'core_object_delete' => 'core_object_bulk_delete',
			],
			self::leftOn(['core_object_create', 'core_object_update', 'core_object_delete'])
		);
	}

	public function testItWorksTheOtherWayRound(): void
	{
		$this->assertSame(['core_object_bulk_delete' => 'core_object_delete'], self::leftOn(['core_object_bulk_delete']));
	}

	public function testAClassEntryCountsAsTheTool(): void
	{
		$this->assertSame(['core_object_delete' => 'core_object_bulk_delete'], self::leftOn([ObjectDelete::class]));
		$this->assertSame([], self::leftOn([ObjectDelete::class, ObjectBulkDelete::class]));
	}

	public function testListingBothSilencesIt(): void
	{
		$this->assertSame([], self::leftOn(['core_object_delete', 'core_object_bulk_delete']));
		$this->assertSame([], self::leftOn([]));
	}

	/**
	 * A name that merely contains "bulk_" is not a pair: the bulk side has to
	 * declare itself by extending AbstractBulkTool.
	 */
	public function testANameAloneDoesNotMakeAPair(): void
	{
		$aTools = [
			'acme_report'      => ['class' => 'Acme\\Report', 'bulk' => false],
			'acme_bulk_report' => ['class' => 'Acme\\BulkReport', 'bulk' => false],
		];

		$this->assertSame([], self::invoke(['acme_report'], $aTools));
	}

	/**
	 * @param array<int, string> $aDisabled
	 *
	 * @return array<string, string>
	 */
	private static function leftOn(array $aDisabled): array
	{
		$aTools = [];
		foreach ([new ObjectCreate(), new ObjectBulkCreate(), new ObjectUpdate(), new ObjectBulkUpdate(), new ObjectDelete(), new ObjectBulkDelete()] as $oTool) {
			/** @var AbstractMCPTool $oTool */
			$aTools[$oTool->getQualifiedName()] = ['class' => get_class($oTool), 'bulk' => $oTool instanceof AbstractBulkTool];
		}

		return self::invoke($aDisabled, $aTools);
	}

	/**
	 * @param array<int, string>                               $aDisabled
	 * @param array<string, array{class: string, bulk: bool}> $aTools
	 *
	 * @return array<string, string>
	 */
	private static function invoke(array $aDisabled, array $aTools): array
	{
		return (new ReflectionMethod(MCPService::class, 'bulkSiblingsLeftOn'))->invoke(null, $aDisabled, $aTools);
	}
}
