<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Registry;

use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;

final class MCPExtensionCollector
{
	/**
	 * @var array<string, string>
	 */
	private static array $aExtensionClasses = [];

	public static function RegisterServiceProvider(string $sClass): void
	{
		if (!in_array(iMCPServiceProvider::class, class_implements($sClass), true)) {
			throw new \InvalidArgumentException(sprintf('Class "%s" must implement %s', $sClass, iMCPServiceProvider::class));
		}

		self::$aExtensionClasses[$sClass] = $sClass;
	}

	public static function CollectAll(): void
	{
		foreach (get_declared_classes() as $sClass) {
			if (in_array(iMCPServiceProvider::class, class_implements($sClass), true)) {
				$sClass::RegisterServiceProvider();
			}
		}

		foreach (self::$aExtensionClasses as $sClass) {
			if (!class_exists($sClass)) {
				continue;
			}

			$sClass::RegisterServiceProvider();
		}
	}
}
