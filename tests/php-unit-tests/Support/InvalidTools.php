<?php
/**
 * Tools that each break one clause of the registration contract.
 *
 * Grouped in a single file because none of them is meaningful on its own and
 * none is autoloadable by name from MCPRegistryTest anyway - they exist only
 * to be rejected.
 *
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;

/** Name carries a character the MCP schema rejects. */
class BadlyNamedTool extends FixtureTool
{
	public function getName(): ?string
	{
		return 'bad name!';
	}
}

/** No description: the model is given nothing to decide on. */
class UndescribedTool extends FixtureTool
{
	public function getDescription(): ?string
	{
		return '   ';
	}
}

/** Input schema is not an object schema. */
class UnschematisedTool extends FixtureTool
{
	public function getInputSchema(): ?array
	{
		return ['type' => 'string'];
	}
}

/** Requires a property it never declares. */
class InconsistentSchemaTool extends FixtureTool
{
	public function getInputSchema(): ?array
	{
		return [
			'type' => 'object',
			'properties' => ['id' => ['type' => 'integer']],
			'required' => ['id', 'ghost'],
		];
	}

	public function execute(int $id = 0, mixed $ghost = null): mixed
	{
		return 'executed';
	}
}

/** Declares an argument execute() cannot receive: bound by name, so dropped. */
class UnboundArgumentTool extends FixtureTool
{
	public function getInputSchema(): ?array
	{
		return [
			'type' => 'object',
			'properties' => ['identifier' => ['type' => 'integer']],
		];
	}

	public function execute(int $id = 0): mixed
	{
		return 'executed';
	}
}

/**
 * execute() needs an argument the schema never asks the client for.
 *
 * Extends the abstract rather than FixtureTool: a mandatory parameter cannot
 * be added to an inherited no-argument execute().
 */
class UnsatisfiableTool extends AbstractMCPTool
{
	public function getDescription(): ?string
	{
		return 'A tool whose mandatory argument is never requested.';
	}

	public function getInputSchema(): ?array
	{
		return ['type' => 'object', 'properties' => []];
	}

	public function execute(string $class): mixed
	{
		return $class;
	}
}

/** No handler at all. */
class HandlerlessTool extends AbstractMCPTool
{
	public function getDescription(): ?string
	{
		return 'A tool with no execute().';
	}

	public function getInputSchema(): ?array
	{
		return ['type' => 'object', 'properties' => []];
	}
}

/** requiredProfiles() returns something that is not a list of names. */
class BadProfilesTool extends FixtureTool
{
	public function requiredProfiles(): array
	{
		return [42];
	}
}

/** Same name as FixtureTool, different class: a deliberate override. */
class OverridingTool extends AbstractMCPTool
{
	public function getName(): ?string
	{
		return 'FixtureTool';
	}

	public function getDescription(): ?string
	{
		return 'Replaces the fixture tool.';
	}

	public function getInputSchema(): ?array
	{
		return ['type' => 'object', 'properties' => []];
	}

	public function execute(): mixed
	{
		return 'overridden';
	}
}

/** A fixed resource whose URI carries a variable. */
class TemplatedResource extends FixtureResource
{
	protected function getResourcePath(): string
	{
		return 'fixture/{id}';
	}
}

/** A template whose URI carries no variable. */
class UnvaryingResourceTemplate extends FixtureResourceTemplate
{
	protected function getResourcePath(): string
	{
		return 'fixture';
	}
}

/** A template whose {variable} matches no parameter of read(). */
class UnboundResourceTemplate extends AbstractMCPResourceTemplate
{
	public function getTitle(): ?string
	{
		return 'Unbound Resource Template';
	}

	public function getDescription(): ?string
	{
		return 'Its {ticket} variable matches nothing.';
	}

	protected function getResourceNamespace(): string
	{
		return 'test';
	}

	protected function getResourcePath(): string
	{
		return 'fixture/{ticket}';
	}

	public function read(string $uri, string $id): mixed
	{
		return $id;
	}
}

/** A prompt with no get(). */
class HandlerlessPrompt extends AbstractMCPPrompt
{
	public function getTitle(): ?string
	{
		return 'Handlerless Prompt';
	}

	public function getDescription(): ?string
	{
		return 'A prompt with no get().';
	}
}

/** A tool whose execute() the SDK could never reach. */
class PrivateHandlerTool extends AbstractMCPTool
{
	public function getDescription(): ?string
	{
		return 'A tool whose execute() is private.';
	}

	public function getInputSchema(): ?array
	{
		return ['type' => 'object', 'properties' => []];
	}

	private function execute(): mixed
	{
		return 'unreachable';
	}
}
