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
	 * The surface is everything under src/Api, plus these classes. ADR 0016,
	 * which replaces ADR 0015's rule 3: a class outside Api is on the surface
	 * when an element under src/Core depends on it, or when a public or
	 * protected signature of a surface class names it (or it is a parent or an
	 * interface of one), so a pack can build with what the core tools use and
	 * call what it is handed.
	 *
	 * Adding a class here is promising to keep it stable across minors, a
	 * decision with a major version attached to it (§11.2), which is why the
	 * list is written out and not derived: the tests below fail when src/Core
	 * starts to depend on a class missing from it, and when a class in it is
	 * used by neither src/Core nor a surface signature.
	 */
	public const EXTRA_SURFACE = [
		'src/Exception/MCPDocumentException.php',
		'src/Exception/MCPPortalException.php',
		'src/Helper/ClassRefusal.php',
		'src/Helper/ErrorReport.php',
		'src/Helper/JsonPayload.php',
		'src/Helper/ObjectId.php',
		'src/Helper/PortalMode.php',
		'src/Helper/SettingNames.php',
		'src/Helper/Settings.php',
		'src/Service/Access/DocumentFetch.php',
		'src/Service/Access/ObjectAccess.php',
		'src/Service/Audit/ReadSummary.php',
		'src/Service/Portal/PortalAware.php',
		'src/Service/Portal/PortalBricks.php',
		'src/Service/Portal/PortalDescription.php',
		'src/Service/Portal/PortalPolicy.php',
		'src/Service/Portal/PortalRows.php',
		'src/Service/Read/AttributeDescription.php',
		'src/Service/Read/ClassCatalog.php',
		'src/Service/Read/CurrentUserReader.php',
		'src/Service/Read/DatamodelReader.php',
		'src/Service/Read/DocumentAccess.php',
		'src/Service/Read/ObjectGrades.php',
		'src/Service/Read/ObjectHistory.php',
		'src/Service/Read/ObjectQuery.php',
		'src/Service/Read/ObjectSerializer.php',
		'src/Service/Read/OutputFields.php',
		'src/Service/Read/UntrustedMarker.php',
		'src/Service/Rights/AccessGrants.php',
		'src/Service/Rights/AccessPolicy.php',
		'src/Service/Rights/ChangeLogReservation.php',
		'src/Service/Rights/ExclusionPolicy.php',
		'src/Service/Rights/ExclusionSearches.php',
		'src/Service/Rights/GateGrades.php',
		'src/Service/Rights/ServedAccess.php',
		'src/Service/Rights/WriteCap.php',
		'src/Service/Write/DeletionCascade.php',
		'src/Service/Write/DryRun.php',
		'src/Service/Write/EditPolicy.php',
		'src/Service/Write/MentionPolicy.php',
		'src/Service/Write/RestValue.php',
		'src/Service/Write/WriteOutcome.php',
		'src/Service/Write/WritePlan.php',
		'src/Service/Write/WriteValues.php',
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
		$aNamed = self::examplePackImports();

		$this->assertNotEmpty($aNamed, 'the example pack imports nothing of this extension; the scan has stopped working');
		foreach ($aNamed as $sName => $sFile) {
			$this->assertContains($sName, $aSurface, $sFile.' imports '.$sName.', which is not on the surface (src/Api or EXTRA_SURFACE)');
		}
	}

	/**
	 * ADR 0016: what a core tool, resource, template or prompt depends on is
	 * on the surface, so a pack may build with the same classes. Read from the
	 * tokens by LayerDependencyTest's own reader, so a mention in a comment
	 * counts for nothing.
	 */
	public function testEveryClassACoreElementUsesIsOnTheSurface(): void
	{
		$aSurface = array_flip(self::surfaceClasses());
		foreach (LayerDependencyTest::Dependencies() as $sClass => $aTargets) {
			if (!str_starts_with($sClass, 'Core\\')) {
				continue;
			}
			foreach (array_keys($aTargets) as $sTarget) {
				if (str_starts_with($sTarget, 'Core\\')) {
					continue;
				}
				$this->assertArrayHasKey($sTarget, $aSurface, $sClass.' depends on '.$sTarget.', which is not on the surface; add it to EXTRA_SURFACE and tag it @api (ADR 0016)');
			}
		}
	}

	/**
	 * A surface class that hands a pack a type the pack may not use is a
	 * surface with a hole in it: the types a public or protected signature
	 * names, and the parents and interfaces of a surface class, are on it too.
	 *
	 * @dataProvider surfaceProvider
	 */
	public function testASurfaceSignatureNamesOnlySurfaceTypes(string $sPath): void
	{
		$aSurface = array_flip(self::surfaceClasses());
		foreach (self::signatureTypes($sPath) as $sType) {
			if (str_starts_with($sType, 'Core\\')) {
				continue;
			}
			$this->assertArrayHasKey($sType, $aSurface, $sPath.' names '.$sType.' in a public or protected signature, or extends it, but it is not on the surface (ADR 0016)');
		}
	}

	/**
	 * And no class is on the list for nothing: each is used by an element
	 * under src/Core, named by a surface class's signature, or called by the
	 * example pack (RestValue, on the surface since 1.x, is the one only the
	 * pack calls).
	 */
	public function testEveryExtraIsUsedByACoreElementOrASurfaceSignature(): void
	{
		$aUsed = [];
		foreach (array_keys(self::examplePackImports()) as $sName) {
			$aUsed[substr($sName, strlen('Altioo\\iTop\\Extension\\MCP\\'))] = true;
		}
		foreach (LayerDependencyTest::Dependencies() as $sClass => $aTargets) {
			if (str_starts_with($sClass, 'Core\\')) {
				$aUsed += $aTargets;
			}
		}
		foreach (self::surfaceFiles() as $sPath) {
			foreach (self::signatureTypes($sPath) as $sType) {
				$aUsed[$sType] = true;
			}
		}
		foreach (self::EXTRA_SURFACE as $sPath) {
			$sClass = self::classOf($sPath);
			$this->assertArrayHasKey($sClass, $aUsed, $sPath.' is on EXTRA_SURFACE but no core element uses it, no surface signature names it and the example pack does not call it; take it off (ADR 0016)');
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
			$this->assertStringContainsString('`'.self::classOf($sPath).'`', $sGuide, 'doc/extending.md does not list '.self::classOf($sPath).' in its surface table');
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
	 * What the example pack imports of this module: fully-qualified name => the
	 * pack file that imports it.
	 *
	 * @return array<string, string>
	 */
	private static function examplePackImports(): array
	{
		$sPack = realpath(self::ROOT.'/doc/example-pack');
		self::assertNotFalse($sPack);
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

		return $aNamed;
	}

	/**
	 * A surface path as a class name relative to the root namespace, the form
	 * LayerDependencyTest::Dependencies() answers in.
	 */
	private static function classOf(string $sPath): string
	{
		return str_replace('/', '\\', substr($sPath, strlen('src/'), -strlen('.php')));
	}

	/** @return array<int, string> */
	private static function surfaceClasses(): array
	{
		return array_map(static fn (string $s): string => self::classOf($s), self::surfaceFiles());
	}

	/**
	 * The classes of src/ a file's public and protected method signatures name
	 * (parameter and return types), and the ones its type declaration extends or
	 * implements, relative to the root namespace. Read from the tokens; a
	 * docblock type counts for nothing, as for LayerDependencyTest.
	 *
	 * @return array<int, string>
	 */
	private static function signatureTypes(string $sPath): array
	{
		$sRoot = 'Altioo\\iTop\\Extension\\MCP\\';
		$aTokens = token_get_all((string)file_get_contents(self::ROOT.'/'.$sPath), TOKEN_PARSE);
		$sOwn = self::classOf($sPath);
		$iPos = strrpos($sOwn, '\\');
		$sNamespace = $iPos === false ? '' : substr($sOwn, 0, $iPos + 1);

		$aImports = [];
		$iDepth = 0;
		$iCount = count($aTokens);
		$aFound = [];
		$fResolve = static function (string $sName) use (&$aImports, $sRoot, $sNamespace): ?string {
			if (str_starts_with($sName, '\\')) {
				$sFull = ltrim($sName, '\\');
			} elseif (isset($aImports[$sName])) {
				$sFull = $aImports[$sName];
			} elseif (!str_contains($sName, '\\')) {
				$sFull = $sRoot.$sNamespace.$sName;
			} else {
				return null;
			}
			if (!str_starts_with($sFull, $sRoot)) {
				return null;
			}
			$sRelative = substr($sFull, strlen($sRoot));

			return is_file(self::ROOT.'/src/'.str_replace('\\', '/', $sRelative).'.php') ? $sRelative : null;
		};

		for ($i = 0; $i < $iCount; $i++) {
			$mToken = $aTokens[$i];
			if (!is_array($mToken)) {
				if ($mToken === '{') {
					$iDepth++;
				} elseif ($mToken === '}') {
					$iDepth--;
				}
				continue;
			}
			if (in_array($mToken[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
				$iDepth++;
				continue;
			}
			if ($mToken[0] === T_USE && $iDepth === 0) {
				$sName = '';
				$sAlias = null;
				for ($j = $i + 1; $j < $iCount && $aTokens[$j] !== ';'; $j++) {
					if (is_array($aTokens[$j]) && in_array($aTokens[$j][0], [T_FUNCTION, T_CONST], true)) {
						$sName = '';
						break;
					}
					if (is_array($aTokens[$j]) && in_array($aTokens[$j][0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
						if ($sName === '') {
							$sName = ltrim($aTokens[$j][1], '\\');
						} else {
							$sAlias = $aTokens[$j][1];
						}
					}
				}
				if ($sName !== '') {
					$aImports[$sAlias ?? substr($sName, (int)strrpos('\\'.$sName, '\\'))] = $sName;
				}
				$i = $j;
				continue;
			}
			if (in_array($mToken[0], [T_EXTENDS, T_IMPLEMENTS], true) && $iDepth === 0) {
				for ($j = $i + 1; $j < $iCount && $aTokens[$j] !== '{'; $j++) {
					if (is_array($aTokens[$j]) && in_array($aTokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
						$sType = $fResolve($aTokens[$j][1]);
						if ($sType !== null) {
							$aFound[$sType] = true;
						}
					}
				}
				continue;
			}
			if ($mToken[0] !== T_FUNCTION || $iDepth !== 1) {
				continue;
			}
			// The modifiers before `function`: private hides it; an interface's
			// method, with none, is public.
			$bPrivate = false;
			for ($j = $i - 1; $j >= 0; $j--) {
				$mPrev = $aTokens[$j];
				if (is_array($mPrev) && in_array($mPrev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC, T_ABSTRACT, T_FINAL, T_PUBLIC, T_PROTECTED], true)) {
					continue;
				}
				if (is_array($mPrev) && $mPrev[0] === T_PRIVATE) {
					$bPrivate = true;
					continue;
				}
				break;
			}
			if ($bPrivate) {
				continue;
			}
			// The parameter list and the return type, up to the body or the `;`.
			$iParen = 0;
			$bInDefault = false;
			for ($j = $i + 1; $j < $iCount; $j++) {
				$mNext = $aTokens[$j];
				if ($mNext === '(') {
					$iParen++;
				} elseif ($mNext === ')') {
					$iParen--;
					$bInDefault = false;
				} elseif (($mNext === '{' || $mNext === ';') && $iParen === 0) {
					break;
				} elseif ($mNext === '=' && $iParen === 1) {
					$bInDefault = true;
				} elseif ($mNext === ',' && $iParen === 1) {
					$bInDefault = false;
				} elseif (is_array($mNext) && !$bInDefault && in_array($mNext[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
					$mAfter = $aTokens[$j + 1] ?? null;
					if ($mAfter === '(' && $j === $i + 2) {
						continue; // the method's own name
					}
					$sType = $fResolve($mNext[1]);
					if ($sType !== null) {
						$aFound[$sType] = true;
					}
				}
			}
			$i = $j - 1;
		}
		unset($aFound[$sOwn]);

		return array_keys($aFound);
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
