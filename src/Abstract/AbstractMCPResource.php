<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Mcp\Schema\Annotations;

abstract class AbstractMCPResource
{
    protected const URI_SCHEME = 'itop';

    public function getName(): ?string //  a short identifier for this resource - defaults to the class name
    {
        $ref = new \ReflectionClass($this);
        return $ref->getShortName();
    }

    abstract public function getTitle(): ?string; //human-readable title for display in UI

    abstract public function getDescription(): ?string;

    abstract protected function getResourceNamespace(): string;

    abstract protected function getResourcePath(): string; // no {variables}

    abstract public function read(): mixed;

    final public function getUri(): string
    {
        return self::URI_SCHEME . '://' 
            . $this->getResourceNamespace() . '/' 
            . $this->getResourcePath();
    }

    public function getMimeType(): ?string //the MIME type, if known and constant for this resource
    {
        return 'application/json';
    }

    public function getSize(): ?int //the size in bytes, if known and constant
    {
        return null;
    }

    public function getAnnotations(): ?Annotations
    {
        return null; 
    }

    public function getIcons(): ?array //list of icon URLs representing the resource
    {
        return null;
    }

    public function getMeta(): ?array
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
