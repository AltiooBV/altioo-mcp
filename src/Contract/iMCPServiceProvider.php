<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Contract;

interface iMCPServiceProvider
{
    /**
     * Called once at MCP server boot to register tools, resources, and prompts.
     */
    public static function RegisterServiceProvider(): void;
}
