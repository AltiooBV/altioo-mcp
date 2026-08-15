<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
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
			->setServerInfo('Altioo iTop MCP Base', MCPHelper::VERSION, 'Altioo iTop MCP extension framework')
			->setLogger(new LogAPILogger(MCPLog::class))
			->setSession(new StatelessSessionStore());

		$aDisabled = MCPHelper::GetDisabledIdentifiers();

		$builder = self::registerResources($builder, $aDisabled);
		$builder = self::registerResourceTemplates($builder, $aDisabled);
		$builder = self::registerTools($builder, $aDisabled);
		$builder = self::registerPrompts($builder, $aDisabled);

		return $builder->build();
	}

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerResources(Builder $builder, array $aDisabled): Builder
	{
		foreach (MCPRegistry::GetResources() as $resource) {
			if (self::isHidden($resource->getUri(), $resource->isAvailable(), $resource->requiredProfiles(), $aDisabled)) {
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

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerResourceTemplates(Builder $builder, array $aDisabled): Builder
	{
		foreach (MCPRegistry::GetResourceTemplates() as $resourceTemplate) {
			if (self::isHidden($resourceTemplate->getUriTemplate(), $resourceTemplate->isAvailable(), $resourceTemplate->requiredProfiles(), $aDisabled)) {
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

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerTools(Builder $builder, array $aDisabled): Builder
	{
		foreach (MCPRegistry::GetTools() as $tool) {
			if (self::isHidden($tool->getName(), $tool->isAvailable(), $tool->requiredProfiles(), $aDisabled)) {
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

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerPrompts(Builder $builder, array $aDisabled): Builder
	{
		foreach (MCPRegistry::GetPrompts() as $prompt) {
			if (self::isHidden($prompt->getName(), $prompt->isAvailable(), $prompt->requiredProfiles(), $aDisabled)) {
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

	/**
	 * Whether an element is kept out of the server being built.
	 *
	 * Everything registered goes through the same three filters, whatever its
	 * kind: what the element says about itself (isAvailable), what the caller
	 * holds (requiredProfiles), and what the operator turned off
	 * (mcp_disabled_tools). Not being registered means it is neither listed nor
	 * callable - the SDK can only route to what the builder was given.
	 *
	 * @param string             $sIdentifier Tool/prompt name, or resource URI.
	 * @param array<int, string> $aRequiredProfiles
	 * @param array<int, string> $aDisabled
	 */
	private static function isHidden(string $sIdentifier, bool $bAvailable, array $aRequiredProfiles, array $aDisabled): bool
	{
		if (!$bAvailable) {
			return true;
		}

		if (in_array($sIdentifier, $aDisabled, true)) {
			return true;
		}

		return self::lacksRequiredProfiles($aRequiredProfiles);
	}

	/**
	 * requiredProfiles() is an AND: the caller must hold every profile listed.
	 *
	 * That is the opposite of the endpoint gate mcp_allowed_profiles, which is
	 * an OR - and deliberately so: "allowed" means any of these lets you in,
	 * "required" means all of these are needed. For any-of semantics on an
	 * element, override isAvailable().
	 *
	 * @param array<int, string> $aProfiles
	 */
	private static function lacksRequiredProfiles(array $aProfiles): bool
	{
		foreach ($aProfiles as $sProfile) {
			if (!UserRights::HasProfile($sProfile)) {
				return true;
			}
		}

		return false;
	}
}
