<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
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
			->setInstructions(ServerInstructions::Text())
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
		foreach (MCPRegistry::GetResources() as $sUri => $resource) {
			if (self::isHidden($sUri, $resource, $aDisabled)) {
				continue;
			}

			$builder = $builder->addResource(
				\Closure::fromCallable([$resource, 'read']),
				$sUri,
				$resource->getQualifiedName(),
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
		foreach (MCPRegistry::GetResourceTemplates() as $sUriTemplate => $resourceTemplate) {
			if (self::isHidden($sUriTemplate, $resourceTemplate, $aDisabled)) {
				continue;
			}

			$builder = $builder->addResourceTemplate(
				\Closure::fromCallable([$resourceTemplate, 'read']),
				$sUriTemplate,
				$resourceTemplate->getQualifiedName(),
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
		foreach (MCPRegistry::GetTools() as $sName => $tool) {
			if (self::isHidden($sName, $tool, $aDisabled)) {
				continue;
			}

			$builder = $builder->addTool(
				\Closure::fromCallable([$tool, 'execute']),
				$sName,
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
		foreach (MCPRegistry::GetPrompts() as $sName => $prompt) {
			if (self::isHidden($sName, $prompt, $aDisabled)) {
				continue;
			}

			$builder = $builder->addPrompt(
				\Closure::fromCallable([$prompt, 'get']),
				$sName,
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
	 * The operator can name either the identifier or the class. The class is
	 * the only way to separate two extensions that picked the same identifier,
	 * which is exactly the case where one of them has to go.
	 *
	 * @param string             $sIdentifier Qualified tool/prompt name, or resource URI, as awarded by the registry.
	 * @param array<int, string> $aDisabled
	 */
	private static function isHidden(string $sIdentifier, object $oElement, array $aDisabled): bool
	{
		if (!$oElement->isAvailable()) {
			return true;
		}

		if (in_array($sIdentifier, $aDisabled, true) || in_array(get_class($oElement), $aDisabled, true)) {
			return true;
		}

		return self::lacksRequiredProfiles($oElement->requiredProfiles());
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
