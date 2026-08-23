<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Registry;

use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Combodo\iTop\Service\InterfaceDiscovery\InterfaceDiscovery;
use LogAPI;
use Throwable;

/**
 * @api
 * @since 1.0.0
 */
final class MCPExtensionCollector
{
	/**
	 * @var array<string, string>
	 */
	private static array $aExtensionClasses = [];

	/**
	 * Declares a provider explicitly. This is the documented path: call it from
	 * a file listed in the `datamodel` array of your module declaration, the
	 * way register.php does for CoreExtensions.
	 *
	 * @throws \InvalidArgumentException When the class does not implement the contract.
	 * @since 1.0.0
	 */
	public static function RegisterServiceProvider(string $sClass): void
	{
		if (!in_array(iMCPServiceProvider::class, class_implements($sClass), true)) {
			throw new \InvalidArgumentException(sprintf('Class "%s" must implement %s', $sClass, iMCPServiceProvider::class));
		}

		self::$aExtensionClasses[$sClass] = $sClass;
	}

	/**
	 * Runs every provider once, at the start of an MCP request.
	 *
	 * Explicit registrations and discovered classes are merged before anything
	 * runs: a provider that is both - which CoreExtensions is, since
	 * register.php declares it and discovery finds it - must still be invoked
	 * exactly once. Registration is idempotent today, but a provider is free to
	 * do setup work in RegisterServiceProvider(), and running that twice per
	 * request is a trap that costs nothing to close.
	 *
	 * A provider that throws is logged and skipped: one broken tool pack must
	 * not take the endpoint down for the others.
	 *
	 * @since 1.0.0
	 */
	public static function CollectAll(): void
	{
		$aProviders = self::$aExtensionClasses;
		foreach (self::DiscoverProviders() as $sClass) {
			$aProviders[$sClass] = $sClass;
		}

		foreach ($aProviders as $sClass) {
			if (!class_exists($sClass)) {
				continue;
			}

			try {
				$sClass::RegisterServiceProvider();
			} catch (Throwable $e) {
				self::Log(sprintf(
					'MCP service provider %s failed to register and was skipped: %s: %s',
					$sClass,
					get_class($e),
					$e->getMessage()
				));
			}
		}

		foreach (MCPRegistry::GetOverrides() as $sIdentifier => $aClasses) {
			self::Log(sprintf('MCP %s: %s replaced %s, as it declares', $sIdentifier, $aClasses[1], $aClasses[0]));
		}

		foreach (MCPRegistry::GetClashes() as $sIdentifier => $aClasses) {
			self::Log(sprintf(
				'MCP %s is claimed by %s and is served to nobody. None of them declares an override, so this is a name clash between unrelated extensions, not a replacement. '
				.'Disable all but one through the mcp_disabled_tools module setting, which accepts a class name.',
				$sIdentifier,
				implode(' and ', $aClasses)
			));
		}
	}

	/**
	 * Providers visible to iTop's own interface discovery.
	 *
	 * InterfaceDiscovery (3.0+, cached, and the mechanism iTop uses for its own
	 * extension points) sees classes an autoloader knows about, whereas
	 * get_declared_classes() only sees what some earlier line happened to load.
	 * It deliberately ignores anything under /vendor/, /lib/, /test/, /tests/
	 * and /node_modules/, so a provider has to live in your module's src/ to be
	 * found this way - explicit registration stays the path that always works.
	 *
	 * @return array<int, string>
	 */
	private static function DiscoverProviders(): array
	{
		if (!class_exists(InterfaceDiscovery::class)) {
			// No iTop around (unit tests), or an iTop older than 3.0.
			return [];
		}

		try {
			return InterfaceDiscovery::GetInstance()->FindItopClasses(iMCPServiceProvider::class);
		} catch (Throwable $e) {
			self::Log('MCP provider discovery failed, falling back to explicit registrations only: '.$e->getMessage());

			return [];
		}
	}

	/**
	 * Collection runs before the server exists, so there is no PSR-3 logger to
	 * write to yet, and no iTop at all under the unit suite.
	 */
	private static function Log(string $sMessage): void
	{
		if (!class_exists(LogAPI::class)) {
			return;
		}

		MCPHelper::LogError($sMessage);
	}
}
