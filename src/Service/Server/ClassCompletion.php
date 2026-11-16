<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Server;

use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalDescription;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\ClassCatalog;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Mcp\Capability\Completion\ProviderInterface;
use Throwable;

/**
 * Completes a `class` variable of a resource template with the class names this caller may read.
 *
 * Asked the same question the class tools ask, so it cannot name more than they do: in the console the
 * classes the account may read minus the ones the operator excluded (ClassCatalog::ListClasses), in portal
 * mode the classes the portal offers this user. An excluded class is absent here, as everywhere (ADR 0012),
 * and a portal that cannot be read completes nothing rather than guessing. Prefix match, case-insensitive,
 * at most 100 names - the protocol's own cap.
 *
 * @internal
 * @since 2.1.0
 */
final class ClassCompletion implements ProviderInterface
{
	/** The protocol's cap on a completion list. */
	public const MAX = 100;

	/**
	 * @return array<int, string>
	 */
	#[\Override]
	public function getCompletions(string $currentValue): array
	{
		try {
			$aNames = PortalMode::Current() !== null
				? PortalDescription::OfferedClassNames(PortalPolicy::ForCurrentRequest())
				: array_column(ClassCatalog::ListClasses(), 'class');
		} catch (Throwable) {
			return [];
		}

		// Whatever the source, an excluded class is not named (ADR 0012).
		return self::matching(array_values(array_filter($aNames, static fn (string $sClass): bool => !ExclusionPolicy::ExcludesClass($sClass))), $currentValue);
	}

	/**
	 * The names that start with the prefix, sorted, capped.
	 *
	 * @param array<int, string> $aNames
	 *
	 * @return array<int, string>
	 */
	public static function matching(array $aNames, string $sPrefix): array
	{
		$aMatching = [];
		foreach ($aNames as $sName) {
			if ($sPrefix !== '' && stripos($sName, $sPrefix) !== 0) {
				continue;
			}
			$aMatching[] = $sName;
		}
		sort($aMatching);

		return array_slice($aMatching, 0, self::MAX);
	}
}
