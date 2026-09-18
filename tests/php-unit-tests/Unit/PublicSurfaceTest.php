<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * What semver applies to, held in one place that can fail.
 *
 * The surface a tool pack may depend on is defined in the source: a class on
 * the surface carries @api on its class docblock (§4.6, §11.2), and the prose
 * - the changelog's header, the README's versioning section, a paragraph
 * inside the extending guide - says "the classes marked @api" rather than
 * naming them.
 *
 * Enumerated in prose instead, that would be three lists of the same class
 * names, none of them read by anything, with the first class added to the
 * surface having to be remembered into all three - the drift AGENTS.md §12.1
 * describes for version numbers, applied to a contract.
 *
 * This test is what makes the tag mean something,
 * from both ends - a surface class that lost its tag, and an internal class
 * that gained one.
 */
class PublicSurfaceTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The surface, as policy rather than as an inventory: whole directories
	 * wherever the rule is "everything here is for packs", and named files
	 * where it is not.
	 *
	 * Changing this list is changing what the extension promises. That is a
	 * decision with a major version attached to it (§11.2), which is the
	 * reason it is written here and not derived from whatever happens to
	 * carry a tag.
	 */
	private const SURFACE = [
		'src/Abstract',
		'src/Helper',
		'src/Testing',
		'src/Registry/MCPRegistry.php',
		'src/Registry/MCPExtensionCollector.php',
		'src/Contract/iMCPServiceProvider.php',
	];

	/**
	 * @dataProvider surfaceProvider
	 */
	public function testASurfaceClassIsMarkedApi(string $sPath): void
	{
		$this->assertStringContainsString(
			'@api',
			self::classDocBlock($sPath),
			$sPath.' is on the surface a tool pack depends on but its class docblock does not say @api;'
			.' either tag it or take it off the surface list in '.self::class
		);
	}

	/**
	 * A pack that extends an untagged class is depending on something this
	 * project never promised, and will find that out at a minor upgrade. The
	 * tag is the promise, so an accidental one costs a major version to undo.
	 *
	 * @dataProvider internalProvider
	 */
	public function testAnInternalClassIsNotMarkedApi(string $sPath): void
	{
		$this->assertStringNotContainsString(
			'@api',
			self::classDocBlock($sPath),
			$sPath.' is marked @api but is not on the surface list in '.self::class
			.'; @api is a commitment to keep it stable across minors'
		);
	}

	/**
	 * Every @api carries an @since beside it. "This is stable" without "since
	 * when" is not answerable by a pack deciding whether the base it is
	 * installed next to has the thing it needs.
	 *
	 * @dataProvider surfaceProvider
	 */
	public function testASurfaceClassSaysWhenItAppeared(string $sPath): void
	{
		$this->assertSame(
			1,
			preg_match('/@since\s+[0-9]+\.[0-9]+\.[0-9]+/', self::classDocBlock($sPath)),
			$sPath.' is @api with no @since <x.y.z>; tools/reconcile-since.py derives the right one from git'
		);
	}

	/**
	 * The prose describes the surface rather than listing it. A file that names
	 * the classes instead is a fourth copy in the making, and one nothing
	 * reads.
	 *
	 * Written as "points at the tag" rather than "does not name a class",
	 * because a document is allowed to mention MCPRegistry in a sentence about
	 * MCPRegistry - what it may not do is present a list as the definition.
	 *
	 * @dataProvider proseProvider
	 */
	public function testTheProsePointsAtTheTagRatherThanAtAList(string $sFile): void
	{
		$this->assertStringContainsString(
			'@api',
			file_get_contents(self::ROOT.'/'.$sFile),
			$sFile.' describes what semver applies to without pointing at the @api tags that define it'
		);
	}

	/** @return array<string, array{0: string}> */
	public static function proseProvider(): array
	{
		$aFiles = ['CHANGELOG.md', 'README.md', 'doc/extending.md'];

		return array_combine($aFiles, array_map(static fn (string $s): array => [$s], $aFiles));
	}

	/** @return array<string, array{0: string}> */
	public static function surfaceProvider(): array
	{
		return self::asCases(self::surfaceFiles());
	}

	/** @return array<string, array{0: string}> */
	public static function internalProvider(): array
	{
		$aSurface = self::surfaceFiles();
		$aInternal = array_values(array_filter(
			self::sourceFiles(),
			static fn (string $s): bool => !in_array($s, $aSurface, true)
		));

		self::assertNotEmpty($aInternal, 'every source file is on the surface; the scan has stopped working');

		return self::asCases($aInternal);
	}

	/**
	 * Repository-relative paths of every PHP file the surface list covers.
	 *
	 * @return array<int, string>
	 */
	private static function surfaceFiles(): array
	{
		$aFiles = [];
		foreach (self::SURFACE as $sEntry) {
			if (str_ends_with($sEntry, '.php')) {
				self::assertFileExists(self::ROOT.'/'.$sEntry, $sEntry.' is on the surface list but does not exist');
				$aFiles[] = $sEntry;
				continue;
			}
			self::assertDirectoryExists(self::ROOT.'/'.$sEntry, $sEntry.' is on the surface list but does not exist');
			foreach (self::sourceFiles() as $sPath) {
				if (str_starts_with($sPath, $sEntry.'/')) {
					$aFiles[] = $sPath;
				}
			}
		}
		sort($aFiles);

		return $aFiles;
	}

	/**
	 * Repository-relative paths of every PHP file under src/.
	 *
	 * @return array<int, string>
	 */
	private static function sourceFiles(): array
	{
		$sRoot = realpath(self::ROOT);
		$aPaths = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sRoot.'/src'));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() === 'php') {
				// substr and not str_replace: the root is a prefix, and
				// str_replace would strip every later occurrence of it too -
				// a checkout at /src turns /src/src/Foo.php into Foo.php and
				// every path in the set stops existing.
				$aPaths[] = ltrim(substr($oFile->getPathname(), strlen($sRoot)), '/');
			}
		}
		sort($aPaths);

		self::assertNotEmpty($aPaths, 'no PHP files found under src/');

		return $aPaths;
	}

	/**
	 * The docblock immediately above the class, interface or trait - not the
	 * file header, which carries the copyright and would match @api written
	 * anywhere in it, and not a member's, which may legitimately differ from
	 * the type's.
	 */
	private static function classDocBlock(string $sPath): string
	{
		$sSource = file_get_contents(self::ROOT.'/'.$sPath);
		self::assertNotFalse($sSource, $sPath.' cannot be read');

		self::assertSame(
			1,
			preg_match('#(/\*\*.*?\*/)\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s#s', $sSource, $aMatch),
			$sPath.' has no docblock immediately above its type declaration'
		);

		return $aMatch[1];
	}

	/**
	 * @param array<int, string> $aPaths
	 *
	 * @return array<string, array{0: string}>
	 */
	private static function asCases(array $aPaths): array
	{
		return array_combine($aPaths, array_map(static fn (string $s): array => [$s], $aPaths));
	}
}
