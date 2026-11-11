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
 * The surface a tool pack may depend on is src/Api and EXTRA_SURFACE below, and
 * the source says so: a class on the surface carries @api on its class
 * docblock (§4.6, §11.2), every other class carries @internal, and the prose
 * - the changelog's header, the README's versioning section, a paragraph
 * inside the extending guide - says "the classes marked @api" rather than
 * naming them.
 *
 * Enumerated in prose, that would be three lists of the same class names, none
 * of them read by anything, with the first class added to the surface having to
 * be remembered into all three - the drift the guide's §12.1 describes for
 * version numbers, applied to a contract. So there is one table, in
 * doc/extending.md, and testTheExtendingGuideNamesTheExtraSurface() holds it.
 *
 * This test is what makes the tag mean something, from both ends - a surface
 * class that lost its tag, and an internal class that gained one or has none.
 */
class PublicSurfaceTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The surface is everything under src/Api, plus these classes. ADR 0015,
	 * rule 3: a pack extends or calls what is under Api, and calls these four
	 * directly because the example pack does and no facade wraps them.
	 *
	 * Adding a class here is promising to keep it stable across minors, a
	 * decision with a major version attached to it (§11.2), which is why the
	 * list is written out and not derived from whatever carries a tag. Each
	 * entry has a pack that calls it:
	 *
	 *  - ObjectQuery: ById() builds the search a pack tool resolves an object
	 *    from, the same way the core tools do (doc/example-pack).
	 *  - ObjectSerializer: DEFAULT_LIST_FIELDS is the default a search tool
	 *    offers for its output_fields parameter.
	 *  - RestValue: a case-log value reaches a tool decoded by the SDK, and
	 *    RestUtils::MakeValue() takes it only wrapped by FromDecodedJson().
	 *  - DryRun: a write tool offers a dry run (SIMULATE_BY_DEFAULT,
	 *    SimulateSchemaProperty(), Simulated()) and validates the object it built (Check()).
	 */
	public const EXTRA_SURFACE = [
		'src/Service/Read/ObjectQuery.php',
		'src/Service/Read/ObjectSerializer.php',
		'src/Service/Write/DryRun.php',
		'src/Service/Write/RestValue.php',
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
	public function testAnInternalClassIsMarkedInternalAndNotApi(string $sPath): void
	{
		$sBlock = self::classDocBlock($sPath);
		$this->assertStringNotContainsString(
			'@api',
			$sBlock,
			$sPath.' is marked @api but is not under src/Api or in EXTRA_SURFACE in '.self::class
			.'; @api is a commitment to keep it stable across minors'
		);
		$this->assertStringContainsString(
			'@internal',
			$sBlock,
			$sPath.' is neither on the surface nor tagged @internal; a class says which it is'
		);
	}

	/**
	 * The list names files that are there, and none that Api already covers.
	 */
	public function testTheExtraSurfaceNamesRealFilesOutsideApi(): void
	{
		foreach (self::EXTRA_SURFACE as $sEntry) {
			$this->assertFileExists(self::ROOT.'/'.$sEntry, $sEntry.' is on the extra surface list but does not exist');
			$this->assertFalse(str_starts_with($sEntry, 'src/Api/'), $sEntry.' is under src/Api, which is on the surface already');
		}
	}

	/**
	 * The example pack is the proof that the surface is enough: whatever it
	 * names of this namespace is under src/Api or in EXTRA_SURFACE, and
	 * nothing else. A pack that needs an internal class is a reason to widen
	 * the list on purpose, not a quiet import.
	 */
	public function testTheExamplePackCallsOnlyTheSurface(): void
	{
		$aSurface = array_map(
			static fn (string $s): string => 'Altioo\\iTop\\Extension\\MCP\\'.str_replace('/', '\\', substr($s, strlen('src/'), -strlen('.php'))),
			self::surfaceFiles()
		);
		$sPack = realpath(self::ROOT.'/doc/example-pack');
		$this->assertNotFalse($sPack);

		$aNamed = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sPack, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() !== 'php' || str_contains($oFile->getPathname(), '/vendor/')) {
				continue;
			}
			preg_match_all('/^use\s+('.preg_quote('Altioo\\iTop\\Extension\\MCP\\', '/').'[\w\\\\]+);/m', (string)file_get_contents($oFile->getPathname()), $aMatches);
			foreach ($aMatches[1] as $sName) {
				$aNamed[$sName] = substr($oFile->getPathname(), strlen($sPack) + 1);
			}
		}

		$this->assertNotEmpty($aNamed, 'the example pack imports nothing of this extension; the scan has stopped working');
		foreach ($aNamed as $sName => $sFile) {
			$this->assertContains($sName, $aSurface, $sFile.' imports '.$sName.', which is not on the surface (src/Api or EXTRA_SURFACE)');
		}
		// And the four extras are not there for nothing: the pack calls each.
		foreach (self::EXTRA_SURFACE as $sPath) {
			$sName = 'Altioo\\iTop\\Extension\\MCP\\'.str_replace('/', '\\', substr($sPath, strlen('src/'), -strlen('.php')));
			$this->assertArrayHasKey($sName, $aNamed, $sName.' is on EXTRA_SURFACE because the example pack calls it, and it no longer does');
		}
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

	/**
	 * The one place the prose lists the surface outside src/Api, so it has to
	 * say the same as EXTRA_SURFACE.
	 */
	public function testTheExtendingGuideNamesTheExtraSurface(): void
	{
		$sGuide = (string)file_get_contents(self::ROOT.'/doc/extending.md');
		foreach (self::EXTRA_SURFACE as $sPath) {
			$sName = basename($sPath, '.php');
			$this->assertStringContainsString('| `'.str_replace('/', '\\', substr(dirname($sPath), strlen('src/'))).'\\'.$sName.'` |', $sGuide, 'doc/extending.md does not list '.$sName.' in its surface table');
		}
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
	 * Repository-relative paths of every PHP file on the surface: all of
	 * src/Api, and the named extras.
	 *
	 * @return array<int, string>
	 */
	private static function surfaceFiles(): array
	{
		$aFiles = array_values(array_filter(
			self::sourceFiles(),
			static fn (string $s): bool => str_starts_with($s, 'src/Api/')
		));
		self::assertNotEmpty($aFiles, 'src/Api holds no PHP files; the scan has stopped working');
		$aFiles = array_merge($aFiles, self::EXTRA_SURFACE);
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

		$aLines = explode("\n", $sSource);
		foreach ($aLines as $i => $sLine) {
			if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+\w+/', $sLine) !== 1) {
				continue;
			}
			$j = $i - 1;
			while ($j >= 0 && str_starts_with(trim($aLines[$j]), '#[')) {
				$j--;
			}
			self::assertTrue($j >= 0 && trim($aLines[$j]) === '*/', $sPath.' has no docblock immediately above its type declaration');
			$iEnd = $j;
			while ($j >= 0 && !str_starts_with(trim($aLines[$j]), '/**')) {
				$j--;
			}

			return implode("\n", array_slice($aLines, $j, $iEnd - $j + 1));
		}
		self::fail($sPath.' declares no class, interface, trait or enum');
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
