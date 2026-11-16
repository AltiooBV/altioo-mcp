<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPResourceTemplate;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;

/**
 * A module resource template as the SDK's explicit handler, which is the registration that can carry
 * completion providers. The template's own read($uri, ...$variables) is called with the URI variables by name,
 * as the reflection-based registration does.
 *
 * @internal
 * @since 2.1.0
 */
final class ResourceTemplateAdapter implements ResourceTemplateHandlerInterface
{
	public function __construct(private readonly AbstractMCPResourceTemplate $oTemplate)
	{
	}

	#[\Override]
	public function read(string $uri, array $variables, ClientGateway $gateway): mixed
	{
		return \Closure::fromCallable([$this->oTemplate, 'read'])($uri, ...$variables);
	}
}
