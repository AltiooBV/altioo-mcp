<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
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
		return 'Read the iTop version information, and the identity, licence and source of the MCP extension serving this session.';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	/** What is running, rather than what is in it. */
	public function getToolset(): string
	{
		return 'server';
	}

	protected function getResourcePath(): string
	{
		return 'version';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::Assistant],
			1,
		);
	}

	/**
	 * The `extension` block is the AGPL 13 source offer.
	 *
	 * A client interacting with this instance over a network is interacting
	 * with a work derived from iTop, and 13 entitles it to the corresponding
	 * source. This resource is the only place in an MCP session where that
	 * offer can be made and found: there is no page to put a footer on, and a
	 * link in a README is not reachable from a client that only ever speaks
	 * JSON-RPC to one endpoint.
	 *
	 * It costs one field on a resource a client reads once, at the start of a
	 * session, to make the offer answerable rather than theoretical.
	 */
	public function read(): mixed
	{
		return ResourceOutput::Json([
			'version' => ITOP_CORE_VERSION,
			'build' => ITOP_REVISION,
			'buildDate' => ITOP_BUILD_DATE,
			'edition' => $this->getiTopEdition(),
			'extension' => [
				'name' => MCPHelper::MODULE_NAME,
				'version' => MCPHelper::VERSION,
				'license' => MCPHelper::LICENSE,
				'source' => MCPHelper::GetSourceUrl(),
			],
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
