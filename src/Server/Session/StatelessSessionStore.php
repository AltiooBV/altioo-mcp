<?php

namespace Altioo\iTop\Extension\MCP\Server\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

class StatelessSessionStore implements SessionStoreInterface
{

    // In-memory only — survives this request, gone on next
    private static array $sessions = [];

    public function exists(Uuid $id): bool
    {
        return true; // accept any ID
    }

    public function read(Uuid $id): string|false
    {
        return self::$sessions[$id->toRfc4122()] ?? false;
    }

    public function write(Uuid $id, string $data): bool
    {
        self::$sessions[$id->toRfc4122()] = $data;
        return true;
    }

    public function destroy(Uuid $id): bool
    {
        unset(self::$sessions[$id->toRfc4122()]);
        return true;
    }

    public function gc(): array
    {
        return [];
    }
}