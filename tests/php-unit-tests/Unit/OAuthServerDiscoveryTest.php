<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\OAuthResource;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * altioo-oauth-server, optional in both directions (doc/adr/0003).
 *
 * Installed beside this module, it is advertised in the 401 without anyone
 * typing its URL; absent, nothing changes; and the setting an operator wrote
 * always wins. Declared to iTop's setup in a form that can never stop an
 * install.
 */
class OAuthServerDiscoveryTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	private const MODULES_ROOT = 'https://itop.example.com/env-production/';

	private function url(mixed $mConfigured, bool $bInstalled, string $sModulesRoot = self::MODULES_ROOT, ?string $sForCalledUrl = null): ?string
	{
		return (new ReflectionMethod(OAuthResource::class, 'metadataUrlFor'))
			->invoke(null, $mConfigured, $bInstalled, $sModulesRoot, $sForCalledUrl);
	}

	/**
	 * One OAuth resource per portal (doc/adr/0007): the module knows which
	 * resource the URL called is, and its document is the one advertised, so
	 * a client calling a portal's URL consents for that portal. The setting
	 * still wins, and a URL the module does not know falls back to its
	 * default resource.
	 */
	public function testTheResourceOfTheUrlCalledIsAdvertised(): void
	{
		$sPortal = 'https://itop.example.com/env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp-customer';
		$sProxy = 'https://sso.example.com/.well-known/oauth-protected-resource';

		$this->assertSame($sPortal, $this->url('', true, self::MODULES_ROOT, $sPortal));
		$this->assertSame($sProxy, $this->url($sProxy, true, self::MODULES_ROOT, $sPortal));
		$this->assertSame(
			'https://itop.example.com/env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp',
			$this->url('', true, self::MODULES_ROOT, null)
		);
	}

	/** The document the OAuth server's README tells an operator to set by hand. */
	public function testAnInstalledOAuthServerIsAdvertised(): void
	{
		$this->assertSame(
			'https://itop.example.com/env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp',
			$this->url('', true)
		);
	}

	public function testWithoutItNothingIsAdvertised(): void
	{
		$this->assertNull($this->url('', false));
		$this->assertNull($this->url(null, false));
	}

	/** A proxy in front of iTop, or a renamed resource, is the operator's to say. */
	public function testTheSettingAlwaysWins(): void
	{
		$sProxy = 'https://sso.example.com/.well-known/oauth-protected-resource';

		$this->assertSame($sProxy, $this->url($sProxy, true));
		$this->assertSame($sProxy, $this->url($sProxy, false));
	}

	/** No URL to build from is no pointer, rather than a relative one. */
	public function testNoModulesRootAdvertisesNothing(): void
	{
		$this->assertNull($this->url('', true, ''));
	}

	/**
	 * The module stays optional in the code too (doc/adr/0003): nothing here
	 * imports its namespace, so no file fails to load without it, and its
	 * API is asked only behind class_exists() - so an instance without it,
	 * or with a version before that API, serves exactly as before: the
	 * default resource advertised, and no token held to a URL.
	 */
	public function testItsApiIsOnlyCalledWhenItIsThere(): void
	{
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS)) as $oFile) {
			if ($oFile->getExtension() === 'php') {
				$this->assertDoesNotMatchRegularExpression(
					'#^use\s+\\\\?Altioo\\\\iTop\\\\Extension\\\\OAuthServer\\\\#m',
					(string)file_get_contents($oFile->getPathname()),
					$oFile->getFilename().' imports altioo-oauth-server, which is optional'
				);
			}
		}

		$sMetadata = $this->bodyOf(OAuthResource::class, 'MetadataUrl');
		$this->assertStringContainsString('class_exists(self::OAUTH_RESOURCE_METADATA_CLASS)', $sMetadata);
		$this->assertLessThan(
			strpos($sMetadata, "call_user_func([self::OAUTH_RESOURCE_METADATA_CLASS"),
			strpos($sMetadata, 'class_exists(self::OAUTH_RESOURCE_METADATA_CLASS)'),
			'the metadata API is called before asking whether it exists'
		);

		$sAudience = $this->bodyOf(\Altioo\iTop\Extension\MCP\Controller\Gate\TokenAudienceGate::class, 'Check');
		$this->assertMatchesRegularExpression(
			'/^[^{]*\{\s*\$sAudienceClass = OAuthResource::TOKEN_AUDIENCE_CLASS;\s*if \(!class_exists\(\$sAudienceClass\)\) \{\s*return true;\s*\}/',
			$sAudience,
			'the audience check does anything before asking whether the module is there'
		);
	}

	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((string)$oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}

	/**
	 * iTop has no optional dependency. The declaration is an OR with a module
	 * every installation has, so it is always satisfied and only orders the
	 * OAuth server first when it is being installed. A bare entry would make
	 * altioo-mcp uninstallable without it.
	 */
	public function testTheDependencyCanNeverStopAnInstall(): void
	{
		$sModule = (string)file_get_contents(self::ROOT.'/module.altioo-mcp.php');

		$this->assertMatchesRegularExpression(
			"#'itop-structure/[0-9.]+ \|\| altioo-oauth-server/[0-9.]+',#",
			$sModule
		);
		$this->assertSame(1, preg_match_all('#altioo-oauth-server/#', $sModule), 'named once, and only inside the OR');
	}
}
