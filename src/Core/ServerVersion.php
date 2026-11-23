<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use MetaModel;
use RunTimeEnvironment;
use utils;

/**
 * What is running: iTop's version and edition, and this extension's identity,
 * licence and source.
 *
 * Served twice, as itop://core/version and as core_version, and built here so
 * that the two are one answer (ADR 0025): both call Payload() and encode it
 * with the shared encoder, and neither has a field the other lacks.
 *
 * The `extension` block is the AGPL 13 source offer. A client interacting with
 * this instance over a network is interacting with a work derived from iTop,
 * and 13 entitles it to the corresponding source. An MCP session has no page
 * to put a footer on, and a link in a README is not reachable from a client
 * that only ever speaks JSON-RPC to one endpoint, so this is where the offer is
 * made and found. The tool form is what makes it found by a client that never
 * reads a resource.
 *
 * @internal
 * @since 2.2.0
 */
final class ServerVersion
{
	/**
	 * @return array{version: string, build: string, buildDate: string, edition: string, extension: array{name: string, version: string, license: string, source: string}}
	 */
	public static function Payload(): array
	{
		return [
			'version' => ITOP_CORE_VERSION,
			'build' => ITOP_REVISION,
			'buildDate' => ITOP_BUILD_DATE,
			'edition' => self::Edition(),
			'extension' => [
				'name' => MCPModule::MODULE_NAME,
				'version' => MCPModule::VERSION,
				'license' => MCPModule::LICENSE,
				'source' => Settings::GetSourceUrl(),
			],
		];
	}

	private static function Edition(): string
	{
		require_once(APPROOT.'setup/runtimeenv.class.inc.php');
		$sCurrEnv = utils::GetCurrentEnvironment();
		$oRuntimeEnv = new RunTimeEnvironment($sCurrEnv);
		// Where the datamodel was compiled from, e.g. 'datamodels/2.x'; the
		// setup writes it to the config and iTop reads it back the same way
		// (see setup/compiler.class.inc.php).
		$aSearchDirs = array(APPROOT.MetaModel::GetConfig()->Get('source_dir'));
		$sExtraDir = APPROOT.'data/'.$sCurrEnv.'-modules/';
		if (file_exists($sExtraDir)) {
			$aSearchDirs[] = $sExtraDir;
		}
		$aAvailableModules = $oRuntimeEnv->AnalyzeInstallation(MetaModel::GetConfig(), $aSearchDirs);

		foreach ($aAvailableModules as $sModuleId => $aModuleData) {
			if ($sModuleId === '_Root_') {
				continue;
			}
			if (utils::IsNullOrEmptyString($aModuleData['version_db'])) {
				continue;
			}
			if ($sModuleId === 'itop-hub-connector') { // The presence of the Hub Connector module is a good indication that we are running the Community edition
				return 'community';
			}
		}

		return 'professional';
	}
}
