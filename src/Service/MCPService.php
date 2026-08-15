<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use Altioo\iTop\Extension\MCP\Server\Session\StatelessSessionStore;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Transport\StreamableHttpTransport;
use UserRights;

final class MCPService
{

	public static function run(): array
	{
		MCPExtensionCollector::CollectAll();

		$factory = new Psr17Factory();
		$request = $factory->createServerRequestFromGlobals();

		$server = self::createServer();

		$transport = new StreamableHttpTransport($request, $factory);
		$response = $server->run($transport);

		return ['request' => $request, 'response' => $response];
	}

	private static function createServer(): Server
	{
		$builder = Server::builder()
			->setServerInfo('Altioo iTop MCP Base', '1.0.0', 'Altioo iTop MCP extension framework')
			->setLogger(new LogAPILogger(MCPLog::class))
			->setSession(new StatelessSessionStore());

		$builder = self::registerResources($builder);
		$builder = self::registerResourceTemplates($builder);
		$builder = self::registerTools($builder);
		$builder = self::registerPrompts($builder);

		return $builder->build();
	}

	private static function registerResources(Builder $builder): Builder
	{
		foreach (MCPRegistry::GetResources() as $resource) {
			if (!$resource->isAvailable()) {
				continue;
			}

			$builder = $builder->addResource(
				\Closure::fromCallable([$resource, 'read']),
				$resource->getUri(),
				$resource->getName(),
				$resource->getTitle(),
				$resource->getDescription(),
				$resource->getMimeType(),
				$resource->getSize(),
				$resource->getAnnotations(),
				$resource->getIcons(),
				$resource->getMeta(),
			);
		}

		return $builder;
	}

	private static function registerResourceTemplates(Builder $builder): Builder
	{
		foreach (MCPRegistry::GetResourceTemplates() as $resourceTemplate) {
			if (!$resourceTemplate->isAvailable()) {
				continue;
			}

			$builder = $builder->addResourceTemplate(
				\Closure::fromCallable([$resourceTemplate, 'read']),
				$resourceTemplate->getUriTemplate(),
				$resourceTemplate->getName(),
				$resourceTemplate->getTitle(),
				$resourceTemplate->getDescription(),
				$resourceTemplate->getMimeType(),
				$resourceTemplate->getAnnotations(),
				$resourceTemplate->getMeta(),
			);
		}

		return $builder;
	}

	private static function registerTools(Builder $builder): Builder
	{
		foreach (MCPRegistry::GetTools() as $tool) {
			if (!$tool->isAvailable() || self::isBlocked($tool->requiredProfiles())) {
				continue;
			}

			$builder = $builder->addTool(
				\Closure::fromCallable([$tool, 'execute']),
				$tool->getName(),
				$tool->getTitle(),
				$tool->getDescription(),
				$tool->getAnnotations(),
				$tool->getInputSchema(),
				$tool->getIcons(),
				$tool->getMeta(),
				$tool->getOutputSchema(),
			);
		}

		return $builder;
	}

	private static function registerPrompts(Builder $builder): Builder
	{
		foreach (MCPRegistry::GetPrompts() as $prompt) {
			if (!$prompt->isAvailable()) {
				continue;
			}

			$builder = $builder->addPrompt(
				\Closure::fromCallable([$prompt, 'get']),
				$prompt->getName(),
				$prompt->getTitle(),
				$prompt->getDescription(),
				$prompt->getIcons(),
				$prompt->getMeta(),
			);
		}

		return $builder;
	}

	private static function isBlocked(array $profiles): bool
	{
		if (!empty($profiles)) {
			foreach ($profiles as $profile) {
				if (!UserRights::HasProfile($profile)) {
					return true;
				}
			}
		}

		return false;
	}
}
