<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;

class CoreExtensions implements iMCPServiceProvider
{
	public static function RegisterServiceProvider(): void
	{
		//Resources
		MCPRegistry::RegisterResource(new Resources\iTopVersion());
		MCPRegistry::RegisterResource(new Resources\CurrentUser());
		MCPRegistry::RegisterResource(new Resources\ClassList());

		//ResourceTemplates
		MCPRegistry::RegisterResourceTemplate(new ResourceTemplates\ClassDetail());

		//Tools
		MCPRegistry::RegisterTool(new Tools\ObjectSearchByOQL());
		MCPRegistry::RegisterTool(new Tools\ObjectSearchByClass());
		MCPRegistry::RegisterTool(new Tools\ObjectGet());
		MCPRegistry::RegisterTool(new Tools\ObjectCreate());
		MCPRegistry::RegisterTool(new Tools\ObjectUpdate());
		MCPRegistry::RegisterTool(new Tools\ObjectDelete());

		//Prompts
		MCPRegistry::RegisterPrompt(new Prompts\MyOpenTickets());
	}
}
