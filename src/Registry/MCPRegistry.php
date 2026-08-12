<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Registry;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;

final class MCPRegistry
{
    /** @var array<string, AbstractMCPTool> */
    private static array $aTools = [];

    /** @var array<string, AbstractMCPResource> */
    private static array $aResources = [];

    /** @var array<string, AbstractMCPResourceTemplate> */
    private static array $aResourceTemplates = [];

    /** @var array<string, AbstractMCPPrompt> */
    private static array $aPrompts = [];

    public static function RegisterTool(AbstractMCPTool $oTool): void
    {
        self::$aTools[$oTool->getName()] = $oTool;
    }

    public static function RegisterResource(AbstractMCPResource $oResource): void
    {
        self::$aResources[$oResource->getUri()] = $oResource;
    }

    public static function RegisterResourceTemplate(AbstractMCPResourceTemplate $oResourceTemplate): void
    {
        self::$aResourceTemplates[$oResourceTemplate->getUriTemplate()] = $oResourceTemplate;
    }

    public static function RegisterPrompt(AbstractMCPPrompt $oPrompt): void
    {
        self::$aPrompts[$oPrompt->getName()] = $oPrompt;
    }

    /** @return AbstractMCPTool[] */
    public static function GetTools(): array
    {
        return self::$aTools;
    }

    /** @return AbstractMCPResource[] */
    public static function GetResources(): array
    {
        return self::$aResources;
    }

     /** @return AbstractMCPResourceTemplate[] */
     public static function GetResourceTemplates(): array
     {
         return self::$aResourceTemplates;
     }

    /** @return AbstractMCPPrompt[] */
    public static function GetPrompts(): array
    {
        return self::$aPrompts;
    }

    public static function Clear(): void
    {
        self::$aTools = [];
        self::$aResources = [];
        self::$aResourceTemplates = [];
        self::$aPrompts = [];
    }
}
