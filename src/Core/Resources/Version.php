<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use utils;
use MetaModel;
use RunTimeEnvironment;

/**
 * @since 1.0.0
 */
class Version extends AbstractMCPResource
{

	protected function defaultTitle(): string
	{
		return 'iTop Version';
	}

	public function getDescription(): ?string
	{
		return 'Read the iTop version information.';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	protected function getResourcePath(): string
	{
		return 'version';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::Assistant],
			0.7,
		);
	}

	public function read(): mixed
	{
		return json_encode([
			'version' => ITOP_CORE_VERSION,
			'build' => ITOP_REVISION,
			'buildDate' => ITOP_BUILD_DATE,
			'edition' => $this->getiTopEdition(),
		]);
	}

	protected function getiTopEdition(): string
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
