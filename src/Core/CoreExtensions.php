<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;

/**
 * @since 1.0.0
 */
class CoreExtensions implements iMCPServiceProvider
{
	public static function RegisterServiceProvider(): void
	{
		//Resources
		MCPRegistry::RegisterResource(new Resources\Version());
		MCPRegistry::RegisterResource(new Resources\CurrentUser());
		MCPRegistry::RegisterResource(new Resources\ClassList());

		//ResourceTemplates
		MCPRegistry::RegisterResourceTemplate(new ResourceTemplates\ClassDetail());
		MCPRegistry::RegisterResourceTemplate(new ResourceTemplates\ObjectDocument());

		//Tools
		MCPRegistry::RegisterTool(new Tools\CurrentUser());
		MCPRegistry::RegisterTool(new Tools\ClassList());
		MCPRegistry::RegisterTool(new Tools\ClassSchema());
		MCPRegistry::RegisterTool(new Tools\ObjectSearchByOQL());
		MCPRegistry::RegisterTool(new Tools\ObjectSearchByClass());
		MCPRegistry::RegisterTool(new Tools\ObjectFindByName());
		MCPRegistry::RegisterTool(new Tools\ObjectGet());
		MCPRegistry::RegisterTool(new Tools\ObjectGetDocument());
		MCPRegistry::RegisterTool(new Tools\ObjectAttach());
		MCPRegistry::RegisterTool(new Tools\ObjectCreate());
		MCPRegistry::RegisterTool(new Tools\ObjectUpdate());
		MCPRegistry::RegisterTool(new Tools\ObjectDelete());
		MCPRegistry::RegisterTool(new Tools\ObjectBulkCreate());
		MCPRegistry::RegisterTool(new Tools\ObjectBulkUpdate());
		MCPRegistry::RegisterTool(new Tools\ObjectBulkDelete());
		MCPRegistry::RegisterTool(new Tools\ObjectApplyStimulus());
		MCPRegistry::RegisterTool(new Tools\ObjectGetRelated());

		//Prompts
		MCPRegistry::RegisterPrompt(new Prompts\MyOpenTickets());
	}
}
