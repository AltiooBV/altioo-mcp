<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, S1: an update is not idempotent while a case log's add_item can be one of its values.
 *
 * A client that retries an idempotent call freely (the MCP annotation's whole meaning) added the same work note twice.
 */
class UpdateToolsIdempotenceTest extends TestCase
{
	/** @return array<string, array{0: object}> */
	public static function updateTools(): array
	{
		return ['core_object_update' => [new ObjectUpdate()], 'core_object_bulk_update' => [new ObjectBulkUpdate()]];
	}

	/** @dataProvider updateTools */
	public function testAnUpdateIsNotAnnotatedIdempotent(object $oTool): void
	{
		$aAnnotations = $oTool->getAnnotations()->jsonSerialize();

		$this->assertFalse($aAnnotations['idempotentHint'], 'a retry adds another case-log entry');
		$this->assertFalse($aAnnotations['readOnlyHint']);
		$this->assertFalse($aAnnotations['destructiveHint']);
	}
}
