<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Core\ServerInstructions;
use Altioo\iTop\Extension\MCP\Helper\LogAPILogger;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
use Altioo\iTop\Extension\MCP\Helper\MCPLog;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\Session\StatelessSessionStore;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

/**
 * @internal
 * @since 1.0.0
 */
final class MCPService
{

	/**
	 * @param AccessPolicy $oPolicy What this caller is served, decided by the
	 *                              controller before the credential was dropped.
	 */
	public static function run(AccessPolicy $oPolicy): array
	{
		MCPExtensionCollector::CollectAll();

		// A locator, not an implementation: it finds a PSR-17 factory on the
		// autoloader and throws when there is none. The implementation is
		// nyholm/psr7, required by composer.json and named nowhere else in this
		// module - see Psr17AvailabilityTest for why that dependency has a test
		// guarding it, and why it is not Guzzle.
		$factory = new Psr17Factory();
		$request = $factory->createServerRequestFromGlobals();

		// Before anything can write: every change made from here on is
		// attributed to the tool the request is calling, whichever extension
		// registered that tool and whether or not its author knew this existed.
		ChangeTracking::ForRequest($request);

		ServedElements::RememberServed();

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
	 * CORS handling along with it, and which the SDK logs a warning about for
	 * exactly that reason.
	 *
	 * When the hostname cannot be known - see Settings::GetAllowedHosts() -
	 * the middleware is left out rather than given a list that matches nothing.
	 * The SDK documents that as the supported answer for a deployment fronted
	 * by a proxy that validates Host itself.
	 *
	 * Since SDK 0.9 it checks Origin separately, against a list of origins of
	 * its own - scheme, host and port - rather than against the hostnames.
	 * That list is mcp_allowed_origins, the one setting that already says
	 * which browser origins may read a response here; a request from the
	 * endpoint's own origin passes without being listed. The argument is new
	 * and second, so the factories are passed by name, as the SDK asks.
	 *
	 * ProtocolVersionMiddleware is deliberately absent, and adding it back
	 * would break the endpoint rather than harden it. Since SDK 0.8 one URL
	 * answers two protocol eras: the transport reads the body, classifies the
	 * request and routes it to the handshake leg or to the stateless
	 * 2026-07-28 one. Everything in this list runs *before* that
	 * classification, and this middleware recognises handshake revisions only
	 * - so from out here it would answer 400 to every modern-era call. The
	 * transport applies it itself, to handshake traffic only, via
	 * StreamableHttpTransport::handshakeMiddleware(). Nothing is lost by
	 * leaving it out, and the SDK logs a warning when it is left in.
	 *
	 * @return array<int, \Psr\Http\Server\MiddlewareInterface>
	 */
	private static function middleware(Psr17Factory $factory): array
	{
		$aAllowedHosts = Settings::GetAllowedHosts();

		$aMiddleware = [new CorsMiddleware(Settings::GetAllowedOrigins())];

		if (!in_array(MCPHttp::ANY_HOST, $aAllowedHosts, true)) {
			$aMiddleware[] = new DnsRebindingProtectionMiddleware(
				$aAllowedHosts,
				allowedOrigins: Settings::GetAllowedOrigins(),
				responseFactory: $factory,
				streamFactory: $factory,
			);
		}

		return $aMiddleware;
	}

	private static function createServer(AccessPolicy $oPolicy): Server
	{
		$builder = Server::builder()
			->setServerInfo('Altioo iTop MCP Base', MCPModule::VERSION, 'Altioo iTop MCP extension framework')
			->setInstructions(ServerInstructions::ForCaller($oPolicy))
			->setPaginationLimit(Settings::GetPaginationLimit())
			->setLogger(new LogAPILogger(MCPLog::class))
			->setSession(new StatelessSessionStore());

		$aDisabled = Settings::GetDisabledIdentifiers();

		$builder = self::registerResources($builder, $aDisabled, $oPolicy);
		$builder = self::registerResourceTemplates($builder, $aDisabled, $oPolicy);
		$builder = self::registerTools($builder, $aDisabled, $oPolicy);
		$builder = self::registerPrompts($builder, $aDisabled, $oPolicy);

		SettingsWarnings::warnAboutSettingsThatMatchNothing($aDisabled);
		SettingsWarnings::warnAboutBulkSiblingsLeftOn($aDisabled);
		SettingsWarnings::warnAboutEscalationClassesThatMatchNothing();

		return $builder->build();
	}

	/**
	 * @param array<int, string> $aDisabled
	 */
	private static function registerResources(Builder $builder, array $aDisabled, AccessPolicy $oPolicy): Builder
	{
		foreach (MCPRegistry::GetResources() as $sUri => $resource) {
			if (ServedElements::isHidden($sUri, $resource, $aDisabled, $oPolicy)) {
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
			if (ServedElements::isHidden($sUriTemplate, $resourceTemplate, $aDisabled, $oPolicy)) {
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
			if (ServedElements::isHidden($sName, $tool, $aDisabled, $oPolicy)) {
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
			if (ServedElements::isHidden($sName, $prompt, $aDisabled, $oPolicy)) {
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

}
