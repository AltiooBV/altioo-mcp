<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use \Mcp\Schema\ToolAnnotations;

abstract class AbstractMCPTool
{
    public function getName(): ?string //a short identifier for this tool - defaults to the class name
    {
        $ref = new \ReflectionClass($this);
        return $ref->getShortName();    
    }  

    abstract public function getDescription(): ?string; //A human-readable description of the tool This can be used by clients to improve the LLM's understanding of available tools. It can be thought of like a "hint" to the model.
    
    abstract public function getInputSchema(): ?array; // JSON Schema object (as a PHP array) defining the expected input structure for the tool. This allows clients to validate inputs before calling the tool and can also be used by LLMs to better understand how to use the tool.

    public function getAnnotations(): ?ToolAnnotations
    {
        return null; // By default, not open to the world
    }

    public function getTitle(): ?string //human-readable title for display in UI
    {
        return $this->getName();
    }

    public function getIcons(): ?array //list of icon URLs representing the tool
    {
        return null;
    }

    public function getOutputSchema(): ?array // JSON Schema object (as a PHP array) defining the expected output structure
    {
        return null;
    }

    public function getMeta(): ?array //metadata
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function requiredProfiles(): array //list of profiles required to use the tool
    {
        return [];
    }
}
