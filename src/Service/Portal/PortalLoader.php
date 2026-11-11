<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
// Same namespace, imported anyway: it extends a portal class, so it can be
// loaded only where the portal is, and nothing should load it to find out.
use Altioo\iTop\Extension\MCP\Service\Portal\PortalBrickTemplates;
use Combodo\iTop\Portal\Brick\BrickCollection;
use Combodo\iTop\Portal\DependencyInjection\SilexCompatBootstrap\PortalXmlConfiguration\Forms;
use Combodo\iTop\Portal\DependencyInjection\SilexCompatBootstrap\PortalXmlConfiguration\Lists;
use Combodo\iTop\Portal\Form\ObjectFormManager;
use Combodo\iTop\Portal\Helper\LifecycleValidatorHelper;
use Combodo\iTop\Portal\Helper\ScopeValidatorHelper;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesProviderService;
use Symfony\Component\DependencyInjection\Container;
use UserRights;

/**
 * Reads a portal's configuration and the bricks granted to the current user.
 *
 * Loading and compiling a portal, as PortalPolicy asks it once per request:
 * the portal's own scopes and lifecycle are compiled into a directory of this
 * module's, behind a lock, and its forms, lists and bricks are read through the
 * portal's own code. What the result lets a user do is PortalPolicy's to answer.
 *
 * Anything that cannot be read is an MCPPortalException, and portal mode then
 * serves nothing for that portal.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalLoader
{
	/**
	 * Reads a portal's configuration and the bricks granted to the current user.
	 *
	 * @return array{0: string, 1: object, 2: object, 3: object, 4: array<string, array<string, mixed>>, 5: array<string, array<int, string>>, 6: array<string, array<string, array<int, \DBSearch|null>>>, 7: array<int, array<string, mixed>>, 8: array<string, array<string, mixed>>, 9: array<int, array<string, mixed>>, 10: array<int, string>}
	 *               What PortalPolicy's constructor takes, in its order.
	 *
	 * @throws MCPPortalException
	 */
	public static function Load(string $sPortal): array
	{
		// The id comes from the datamodel, unchecked when the URL names no
		// portal, and everything below makes a file name of it.
		if (!PortalMode::IsPortalId($sPortal)) {
			throw self::Refusal(sprintf('Portal mode refuses to read a portal whose id cannot be a file name: %s.', json_encode($sPortal)));
		}

		foreach ([\ModuleDesign::class, BrickCollection::class, ScopeValidatorHelper::class, ObjectFormManager::class, Container::class] as $sNeeded) {
			if (!class_exists($sNeeded)) {
				throw self::Refusal(sprintf(
					"Portal mode cannot read portal '%s': %s is not available. The portal module (itop-portal-base) has to be installed.",
					$sPortal,
					$sNeeded
				));
			}
		}

		// The portal's own field merge is public from iTop 3.2.2. Before
		// that it is inlined in the method that renders, and portal mode
		// would have to restate it - which is how two copies of a security
		// rule start to disagree.
		if (!method_exists(ObjectFormManager::class, 'PrepareFields')) {
			throw self::Refusal(sprintf(
				"Portal mode cannot read portal '%s' on iTop %s: it needs iTop 3.2.2 or later to read the portal's forms.",
				$sPortal,
				defined('ITOP_VERSION') ? ITOP_VERSION : 'of unknown version'
			));
		}

		try {
			$oDesign = new \ModuleDesign($sPortal);

			// 3.2.0 bricks take no templates service; from 3.2.1 a brick
			// overriding a template cannot be read without one.
			$oBricks = class_exists(TemplatesProviderService::class)
				? new BrickCollection($oDesign, new PortalBrickTemplates())
				: new BrickCollection($oDesign);

			$sCompiled = self::compiledRulesPath();

			// iTop's helpers compile the portal's rules into $sCompiled when no
			// file is there yet, writing it in place; a second request starting at
			// the same moment could require a half-written file and fail with a
			// ParseError. Built one at a time, the second finds the first's file whole.
			// A portal shares these files with no other, so it waits for no other.
			$rLock = @fopen(self::CompileLockPath($sCompiled, $sPortal), 'c');
			if ($rLock !== false) {
				flock($rLock, LOCK_EX);
			}
			try {
				$oScopes = new ScopeValidatorHelper($oDesign, $sPortal, $sCompiled);
				$oLifecycle = new LifecycleValidatorHelper($oDesign, $sPortal, $sCompiled);
			} finally {
				if ($rLock !== false) {
					flock($rLock, LOCK_UN);
					fclose($rLock);
				}
			}

			// Forms and Lists write into a container parameter and read
			// nothing else from it, so a bare container stands in for the
			// portal's kernel.
			$oContainer = new Container();
			$oContainer->setParameter('combodo.portal.instance.conf', []);
			(new Forms($oDesign))->Process($oContainer);
			(new Lists($oDesign))->Process($oContainer);
			$aConf = $oContainer->getParameter('combodo.portal.instance.conf');

			[$aReach, $aBrickSets, $aLists, $aListDefs, $aCreates] = PortalBricks::Granted($oBricks->GetBricks() ?? []);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(ErrorReport::OpaqueFailure(sprintf("Could not read portal '%s'", $sPortal), $oException), 0, $oException);
		}

		return [
			$sPortal,
			$oDesign,
			$oScopes,
			$oLifecycle,
			is_array($aConf['forms'] ?? null) ? $aConf['forms'] : [],
			$aReach,
			$aBrickSets,
			$aLists,
			$aListDefs,
			$aCreates,
			array_values(array_map('strval', UserRights::ListProfiles())),
		];
	}

	/**
	 * Where the portal's scopes and lifecycle are compiled for this endpoint.
	 *
	 * Under iTop's cache directory, which setup clears with the portal's own
	 * cache, and in a directory of this module's, so that the portal's kernel
	 * cache is never written to from here.
	 *
	 * @throws MCPPortalException
	 */
	private static function compiledRulesPath(): string
	{
		$sPath = rtrim(\utils::GetCachePath(), '/').'/altioo-mcp/portals/';
		if (!is_dir($sPath) && !@mkdir($sPath, 0770, true) && !is_dir($sPath)) {
			throw self::Refusal(sprintf('Portal mode could not create %s to compile the portal rules in.', $sPath));
		}

		return $sPath;
	}

	/**
	 * The file a portal's compilation is locked on, beside the files it writes.
	 *
	 * @param string $sCompiled The directory the rules are compiled in, with its trailing slash.
	 */
	public static function CompileLockPath(string $sCompiled, string $sPortal): string
	{
		return $sCompiled.$sPortal.'.compile.lock';
	}

	/**
	 * A refusal this module composed: the detail - a path, a version, a
	 * missing module - goes to the log, and the caller is told only that the
	 * portal could not be read.
	 */
	public static function Refusal(string $sDetail): MCPPortalException
	{
		ErrorReport::Log($sDetail);

		return new MCPPortalException(
			'This portal\'s configuration could not be read, so nothing is served for it. '
			.'The administrator will find the reason in the iTop error log.'
		);
	}
}
