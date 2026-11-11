<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Api;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;

/**
 * This module's identity - its name, version, licence and source - and the version check a pack
 * calls first thing in RegisterServiceProvider().
 *
 * @api
 * @since 2.0.0
 */
class MCPModule
{
	const MODULE_NAME = 'altioo-mcp';

	/**
	 * Single source of truth for the module version.
	 *
	 * extension.xml and module.altioo-mcp.php are read by the iTop setup before
	 * this module's autoloader exists, so they carry the same string literally;
	 * ModuleMetadataTest pins the three together.
	 *
	 * Semver applies to what a downstream tool pack can touch: the abstracts
	 * (AbstractMCPTool / Resource / ResourceTemplate / Prompt), MCPRegistry,
	 * MCPExtensionCollector, iMCPServiceProvider and the helpers under
	 * Helper/. A breaking change to any of those is a major bump; a new
	 * optional hook with a default implementation is a minor one.
	 */
	const VERSION = '2.0.0';

	/**
	 * The mcp/sdk release line this module vendors, loads and is tested
	 * against.
	 *
	 * A tool pack has a real runtime dependency on the SDK - it references
	 * ToolAnnotations and throws ToolCallException, both of which have to
	 * resolve when a client calls it - and must nevertheless not ship a copy,
	 * because two copies in one PHP process resolve to whichever autoloader
	 * answered first and there is no way to predict which. The dependency is
	 * therefore real and satisfied by this module rather than by the pack;
	 * this constant is the constraint a pack declares against so that the two
	 * cannot drift apart unnoticed. See the README, Extending.
	 *
	 * @since 1.0.0
	 */
	const SDK_CONSTRAINT = '^0.9.0';

	/**
	 * The licence this module is distributed under, and where its source is.
	 *
	 * Served to remote callers by the core/version resource, which is what
	 * makes them AGPL 13's "prominent offer": this module is the network-facing
	 * surface of the combined work, and the people interacting with it over a
	 * network are the ones entitled to ask for the corresponding source. An
	 * offer nobody can find from the running instance is not an offer, and a
	 * link in a README on GitHub is not reachable from an MCP session.
	 *
	 * SOURCE_URL is upstream, which is correct for an unmodified install. An
	 * operator who modifies this module and lets third parties reach it owes
	 * *their* source, not ours - `mcp_source_url` overrides this for exactly
	 * that case. See the README, License.
	 *
	 * @since 1.0.0
	 */
	const LICENSE = 'AGPL-3.0-or-later';
	const SOURCE_URL = 'https://github.com/AltiooBV/altioo-mcp';

	/**
	 * Whether the running base extension is at least $sVersion.
	 *
	 * For a pack that wants to degrade rather than refuse: hide the one
	 * element that needs a newer hook through isAvailable() and serve the rest.
	 *
	 * @since 1.0.0
	 */
	public static function AtLeast(string $sVersion): bool
	{
		return version_compare(self::VERSION, $sVersion, '>=');
	}

	/**
	 * Stops a provider that needs a newer base extension than this one.
	 *
	 * iTop's module dependencies already express "altioo-mcp/1.0.0 or later"
	 * and are the right place to say it: the setup refuses the install and the
	 * administrator reads why. This is for the case that gets past them - an
	 * instance where the base was downgraded after the pack was installed, or
	 * a pack shipped as files rather than through the setup - where the
	 * alternative is a fatal error on an undefined method, inside a request,
	 * with the endpoint down for every other pack too.
	 *
	 * Call it first thing in RegisterServiceProvider(). The collector catches
	 * what a provider throws, logs it and skips that provider alone, so the
	 * failure mode becomes "this pack is missing and the log says why".
	 *
	 * @param string $sVersion  Minimum base version, e.g. '1.0.0'.
	 * @param string $sRequires What needs it, named in the log entry.
	 *
	 * @throws MCPRegistrationException When this module is older than that.
	 *
	 * @since 1.0.0
	 */
	public static function RequireVersion(string $sVersion, string $sRequires = ''): void
	{
		if (self::AtLeast($sVersion)) {
			return;
		}

		throw new MCPRegistrationException(sprintf(
			'%s requires %s %s or later; this instance runs %s. Upgrade the base extension, or install a release of %s built for it.',
			$sRequires === '' ? 'This tool pack' : $sRequires,
			self::MODULE_NAME,
			$sVersion,
			self::VERSION,
			$sRequires === '' ? 'the pack' : $sRequires
		));
	}
}
