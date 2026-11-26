<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\ResourceTemplates\ClassDetail;
use Altioo\iTop\Extension\MCP\Core\Resources\ClassList as ClassListResource;
use Altioo\iTop\Extension\MCP\Core\Resources\Version as VersionResource;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList as ClassListTool;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\Version as VersionTool;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A resource and its tool twin answer the same thing (ADR 0025).
 *
 * The unit suite holds that every resource has a twin and that the twin is
 * served wherever the resource is; this holds that the two are one answer, on
 * an instance, byte for byte where the tool takes no narrowing. The
 * instructions have their own comparison, with the handshake, in
 * CoreInstructionsTest.
 *
 * @group integration
 */
class ResourceToolTwinParityTest extends ItopDataTestCaseAlias
{
	public function testTheVersionToolAnswersWhatTheResourceDoes(): void
	{
		$sResource = (new VersionResource())->read();

		$this->assertStringContainsString('"extension"', $sResource, 'the version payload carries no source offer, so this would pass on nothing');
		$this->assertSame($sResource, VersionTool::execute()->content[0]->text);
	}

	public function testTheClassListToolUnnarrowedAnswersWhatTheResourceDoes(): void
	{
		$this->assertSame((new ClassListResource())->read(), ClassListTool::execute()->text);
	}

	public function testTheClassSchemaToolAnswersWhatTheTemplateDoes(): void
	{
		$aResource = json_decode((new ClassDetail())->read('itop://core/class/Organization', 'Organization'), true, 512, JSON_THROW_ON_ERROR);
		$aTool = json_decode(ClassSchema::execute('Organization')->text, true, 512, JSON_THROW_ON_ERROR);

		// The tool echoes the narrowing it applied, which a fixed URI has none of.
		unset($aTool['reported'], $aResource['reported']);
		$this->assertSame($aResource, $aTool);
	}
}
