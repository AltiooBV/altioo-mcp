<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use DBObject;
use Mcp\Schema\ToolAnnotations;

/**
 * Search iTop objects by class name with optional field filters.
 *
 * Simpler alternative to itop_object_search when you don't need full OQL.
 * Filters are combined with AND.
 *
 * Example:
 *   class: "UserRequest"
 *   filters: { "status": "open", "agent_id": 12 }
 */
abstract class AbstractObjectSearch extends AbstractMCPTool
{
	const MIN_LIMIT = 1;
	const DEFAULT_LIMIT = 50;
	const MAX_LIMIT = 1000;

	const MIN_OFFSET = 0;
	const DEFAULT_OFFSET = 0;


	public function getNamespace(): string
	{
		return 'core';
	}

	protected static function serializeObject(DBObject $oObject, string $sClass): array
	{
		return ObjectSerializer::Serialize($oObject, $sClass);
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Object Search',
			true,  // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}
}
