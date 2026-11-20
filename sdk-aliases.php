<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

/**
 * The names `mcp/sdk` 0.9 gave its exceptions, for a tool pack written against it. Deprecated; removed in 3.0.
 *
 * SDK 0.10 moved `Mcp\Exception\*` to `Mcp\Server\Exception\*` and left no alias, so a pack that throws or
 * catches `Mcp\Exception\ToolCallException` - which doc/extending.md used to tell it to - would answer every
 * call with a missing class. This file gives the old names back. It is loaded by composer's `autoload.files`,
 * which `vendor/autoload.php` runs, and `vendor/autoload.php` is the first file of this module's datamodel, so
 * the names exist before a pack's own code does.
 *
 * Eager on purpose. A lazy autoloader would not do: PHP does not autoload a class for a `catch` clause or an
 * `instanceof`, so `catch (\Mcp\Exception\ToolCallException)` would never match an alias made on first `new`.
 * The price is that nothing can log a use of an old name; `Testing\ElementContract` reads a pack's source
 * for them instead.
 *
 * Only the server-side family is here (19 moved names, and the five base names a pack could have caught, to
 * their `Server` variants). Nothing else of the SDK's move is reachable from a pack: the module builds the server.
 * An SDK release that renames another name a pack can reach adds it here with its test
 * (doc/adr/0024-the-pack-contract-names-this-modules-exceptions.md).
 *
 * @see \Altioo\iTop\Extension\MCP\Api\ToolRefusal what a pack throws instead
 */

namespace Altioo\iTop\Extension\MCP\Compat;

// Written as a function-free loop over strings so that nothing here is declared in the module's namespace and a
// second inclusion, or a pack that ships its own SDK 0.9, finds the names taken and leaves them alone.
foreach ([
	// The family a pack throws, catches or type-hints.
	'BadMethodCallException',
	'ClientException',
	'ConfigurationException',
	'ContainerException',
	'InvalidCursorException',
	'MissingRequiredClientCapabilityException',
	'NotFoundExceptionInterface',
	'PromptGetException',
	'PromptNotFoundException',
	'RegistryException',
	'RequestStateException',
	'ResourceNotFoundException',
	'ResourceReadException',
	'ServiceNotFoundException',
	'SessionLockException',
	'SessionLockTimeoutException',
	'SessionStoreException',
	'ToolCallException',
	'ToolNotFoundException',
	// Split three ways by 0.10 (Schema, Client, Server): the Server variant is the one a pack caught or extended.
	'Exception',
	'ExceptionInterface',
	'InvalidArgumentException',
	'LogicException',
	'RuntimeException',
] as $sShortName) {
	$sOld = 'Mcp\\Exception\\'.$sShortName;
	$sNew = 'Mcp\\Server\\Exception\\'.$sShortName;
	// Only when the SDK's own class can be had, which it can once composer's autoloader is registered and which it cannot when this
	// file is requested on its own: there it does nothing and says nothing, instead of a warning naming the install path per name.
	if (!class_exists($sOld, false) && !interface_exists($sOld, false) && (class_exists($sNew) || interface_exists($sNew))) {
		class_alias($sNew, $sOld);
	}
}
