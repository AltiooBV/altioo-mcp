<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C18: core_object_get_document said "this is how that uri is read" and takes no uri.
 */
class DocumentToolDescriptionTest extends TestCase
{
	public function testGetDocumentSaysItTakesNoUri(): void
	{
		$oTool = new ObjectGetDocument();
		$sDescription = (string)$oTool->getDescription();

		$this->assertArrayNotHasKey('uri', $oTool->getInputSchema()['properties']);
		$this->assertStringNotContainsString('this is how that uri is read', $sDescription);
		$this->assertStringContainsString('there is no uri argument', $sDescription);
		$this->assertStringContainsString('{class}/{id}/{att_code}', $sDescription, 'it says which three arguments the uri stands for');
	}
}
