<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use Altioo\iTop\Extension\MCP\Server\ServerInstructions;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use Altioo\iTop\Extension\MCP\Server\Session\StatelessSessionStore;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use UserRights;

final class MCPService
{

	/**
	 * @param AccessPolicy $oPolicy What this caller is served, decided by the
	 *                              controller before the credential was dropped.
	 */
	public static function run(AccessPolicy $oPolicy): array
	{
		MCPExtensionCollector::CollectAll();

		$factory = new Psr17Factory();
		$request = $factory->createServerRequestFromGlobals();

		$server = self::createServer($oPolicy);

		$transport = new StreamableHttpTransport($request, $factory, middleware: self::middleware($factory));
		$response = $server->run($transport);

		return ['request' => $request, 'response' => $response];
	}

	/**
	 * The SDK's own stack, with the one piece that cannot be left at its
	 * default replaced.
	 *
	 * DnsRebindingProtectionMiddleware allows localhost and nothing else unless
	 * told otherwise, which answers 403 to every request a production instance
	 * ever receives. The fix is to hand it the hostnames this instance is
	 * served under - not to pass an empty middleware list, which would drop
	 * CORS handling and protocol-version validation along with it, and which
	 * the SDK logs a warning about for exactly that reason.
	 *
	 * When the hostname cannot be known - see MCPHelper::GetAllowedHosts() -
	 * the middleware is left out rather than given a list that matches nothing.
	 * The SDK documents that as the supported answer for a deployment fronted
	 * by a proxy that validates Host itself.
	 *
	 * @return array<int, \Psr\Http\Server\MiddlewareInterface>
	 */
	private static function middleware(Psr17Factory $factory): array
	{
		$aAllowedHosts = MCPHelper::GetAllowedHosts();

		$aMiddleware = [new CorsMiddleware(MCPHelper::GetAllowedOrigins())];

		if (!in_array(MCPHttp::ANY_HOST, $aAllowedHosts, true)) {
			$aMiddleware[] = new DnsRebindingProtectionMiddleware($aAllowedHosts, $factory, $factory);
		}

		$aMiddleware[] = new ProtocolVersionMiddleware();

		return $aMiddleware;
	}

	private static function createServer(AccessPolicy $oPolicy): Server
	{
		$builder = Server::builder()
			->setServerInfo('Altioo iTop MCP Base', MCPHelper::VERSION, 'Altioo iTop MCP extension framework')
			->setInstructions(ServerInstructions::Text())
			->setPaginationLimit(MCPHelper::GetPaginationLimit())
			->setLogger(new LogAPILogger(MCPLog::class))
			->setSession(new StatelessSessionStore());

		$aDisabled = MCPHelper::GetDisabledIdentifiers();

		$builder = self::registerResources($builder, $aDisabled, $oPolicy);
		$builder = self::registerResourceTemplates($builder, $aDisabled, $oPolicy);
		$builder = self::registerTools($builder, $aDisabled, $oPolicy);
		$builder = self::registerPrompts($builder, $aDisabled, $oPolicy);

		return $builder->build();
	}

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerResources(Builder $builder, array $aDisabled, AccessPolicy $oPolicy): Builder
	{
		foreach (MCPRegistry::GetResources() as $sUri => $resource) {
			if (self::isHidden($sUri, $resource, $aDisabled, $oPolicy)) {
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
	private static function registerResourceTemplates(Builder $builder, array $aDisabled, AccessPolicy $oPolicy): Builder
	{
		foreach (MCPRegistry::GetResourceTemplates() as $sUriTemplate => $resourceTemplate) {
			if (self::isHidden($sUriTemplate, $resourceTemplate, $aDisabled, $oPolicy)) {
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
	private static function registerTools(Builder $builder, array $aDisabled, AccessPolicy $oPolicy): Builder
	{
		foreach (MCPRegistry::GetTools() as $sName => $tool) {
			if (self::isHidden($sName, $tool, $aDisabled, $oPolicy)) {
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
	private static function registerPrompts(Builder $builder, array $aDisabled, AccessPolicy $oPolicy): Builder
	{
		foreach (MCPRegistry::GetPrompts() as $sName => $prompt) {
			if (self::isHidden($sName, $prompt, $aDisabled, $oPolicy)) {
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
	 * Everything registered goes through the same filters, whatever its kind:
	 * what the element says about itself (isAvailable), what the caller holds
	 * (requiredProfiles), which toolsets this instance serves
	 * (mcp_enabled_toolsets) and what the operator turned off
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
	private static function isHidden(string $sIdentifier, object $oElement, array $aDisabled, AccessPolicy $oPolicy): bool
	{
		if (!$oElement->isAvailable()) {
			return true;
		}

		if (!$oPolicy->allowsToolset($oElement->getToolset())) {
			return true;
		}

		if ($oElement instanceof AbstractMCPTool) {
			[$bReadOnly, $bDestructive] = self::annotatedHints($oElement);
			if (!$oPolicy->allowsTool($bReadOnly, $bDestructive)) {
				return true;
			}
		}

		if (in_array($sIdentifier, $aDisabled, true) || in_array(get_class($oElement), $aDisabled, true)) {
			return true;
		}

		return self::lacksRequiredProfiles($oElement->requiredProfiles());
	}

	/**
	 * What this caller is served: the instance configuration, narrowed by the
	 * scopes of the token it authenticated with.
	 *
	 * A token that presented itself but whose scopes cannot be read is graded
	 * read-only. The alternative - assuming the widest policy when the
	 * narrowing information is missing - turns a failure to read into a
	 * privilege escalation.
	 *
	 * Decided by the controller rather than here, and decided early: it is the
	 * last thing that needs the raw credential, so computing it up front is
	 * what lets the credential be dropped before the request object exists.
	 */
	public static function AccessPolicyOfCurrentRequest(): AccessPolicy
	{
		$oConfigured = AccessPolicy::Of(MCPHelper::GetCapabilities(), MCPHelper::GetEnabledToolsets());

		if (!TokenScopes::RequestCarriesAToken()) {
			// Basic authentication or a reverse proxy: no token, no scopes,
			// and nothing to narrow with.
			return $oConfigured;
		}

		$aScopes = TokenScopes::OfCurrentRequest();
		if ($aScopes === null) {
			return $oConfigured->narrowedBy(AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], []));
		}

		return $oConfigured->narrowedBy(AccessPolicy::FromScopes($aScopes));
	}

	/**
	 * What a tool claims about itself, as [readOnlyHint, destructiveHint].
	 *
	 * Null for either means the tool makes no claim - which is the state of
	 * every tool whose author never annotated it, and is why AccessPolicy
	 * grades that case as the harshest one rather than the mildest.
	 *
	 * @return array{0: bool|null, 1: bool|null}
	 */
	private static function annotatedHints(AbstractMCPTool $oTool): array
	{
		$oAnnotations = $oTool->getAnnotations();
		if ($oAnnotations === null) {
			return [null, null];
		}

		$aSerialized = $oAnnotations->jsonSerialize();
		if (!is_array($aSerialized)) {
			return [null, null];
		}

		return [
			array_key_exists('readOnlyHint', $aSerialized) ? (bool)$aSerialized['readOnlyHint'] : null,
			array_key_exists('destructiveHint', $aSerialized) ? (bool)$aSerialized['destructiveHint'] : null,
		];
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
