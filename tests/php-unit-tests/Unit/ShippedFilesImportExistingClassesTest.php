<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Every file the module or the example pack ships or runs, outside src/, names
 * only classes that src/ has.
 *
 * Moving a class edits the files that import it, and a file that is not under
 * src/ is the one a move forgets: the root register.php imported a class that
 * had moved, no unit test loads it, and the break shipped. This reads the
 * root *.php, doc/example-pack and tools/ci for each `use` and each
 * fully-qualified name in this module's namespace, from the PHP tokens (as
 * LayerDependencyTest does, so a mention in a comment or a string counts for
 * nothing), and checks that a file under src/ declares it.
 */
class ShippedFilesImportExistingClassesTest extends TestCase
{
	private const ROOT = 'Altioo\\iTop\\Extension\\MCP\\';

	/** The test namespace is the repository's own, not src/'s. */
	private const TESTS = 'Altioo\\iTop\\Extension\\MCP\\Test\\';

	public function testThereAreFilesToCheck(): void
	{
		$this->assertContains(dirname(__DIR__, 3).'/register.php', self::Files());
		$this->assertNotEmpty(array_filter(self::Files(), static fn (string $sFile): bool => str_contains($sFile, '/doc/example-pack/')));
		$this->assertNotEmpty(array_filter(self::Files(), static fn (string $sFile): bool => str_contains($sFile, '/tools/ci/')));
	}

	public function testEveryNameOfThisNamespaceInAShippedFileIsAClassOfSrc(): void
	{
		$sRoot = dirname(__DIR__, 3).'/';
		$aMissing = [];
		foreach (self::Files() as $sFile) {
			foreach (self::NamesOf($sFile) as $sName) {
				if (str_starts_with($sName, self::TESTS)) {
					continue;
				}
				$sPath = $sRoot.'src/'.str_replace('\\', '/', substr($sName, strlen(self::ROOT))).'.php';
				if (!is_file($sPath)) {
					$aMissing[] = substr($sFile, strlen($sRoot)).' names '.$sName.', which no file under src/ declares';
				}
			}
		}

		$this->assertSame([], $aMissing, "A moved or renamed class is still named by a file that ships:\n".implode("\n", $aMissing));
	}

	/**
	 * The checker sees what it is for: a moved class named in a `use`, in a group use and fully qualified.
	 */
	public function testTheReaderFindsEveryFormOfName(): void
	{
		$sFile = tempnam(sys_get_temp_dir(), 'mcp');
		file_put_contents($sFile, <<<'PHP'
<?php
namespace Somewhere;

use Altioo\iTop\Extension\MCP\Old\Moved;
use Altioo\iTop\Extension\MCP\Api\{One, Two as Other};
use function Altioo\iTop\Extension\MCP\Old\helper;
use Vendor\Package\NotOurs;

// use Altioo\iTop\Extension\MCP\Old\InAComment;
$s = 'Altioo\iTop\Extension\MCP\Old\InAString';
$o = new \Altioo\iTop\Extension\MCP\Old\Qualified();
PHP);
		try {
			$aNames = self::NamesOf($sFile);
		} finally {
			unlink($sFile);
		}
		sort($aNames);

		$this->assertSame([
			'Altioo\iTop\Extension\MCP\Api\One',
			'Altioo\iTop\Extension\MCP\Api\Two',
			'Altioo\iTop\Extension\MCP\Old\Moved',
			'Altioo\iTop\Extension\MCP\Old\Qualified',
		], $aNames);
	}

	/**
	 * @return list<string> absolute paths
	 */
	private static function Files(): array
	{
		static $aFiles = null;
		if ($aFiles === null) {
			$sRoot = dirname(__DIR__, 3);
			$aFiles = glob($sRoot.'/*.php') ?: [];
			foreach (['doc/example-pack', 'tools/ci'] as $sDirectory) {
				$sPath = $sRoot.'/'.$sDirectory;
				if (!is_dir($sPath)) {
					continue;
				}
				$oIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sPath, \FilesystemIterator::SKIP_DOTS));
				foreach ($oIterator as $oFile) {
					if ($oFile->getExtension() === 'php' && !str_contains($oFile->getPathname(), '/vendor/')) {
						$aFiles[] = $oFile->getPathname();
					}
				}
			}
			sort($aFiles);
		}

		return $aFiles;
	}

	/**
	 * @return list<string> the names of this module's namespace a file imports or writes fully qualified, with no leading backslash
	 */
	private static function NamesOf(string $sFile): array
	{
		$aNames = [];
		$aTokens = token_get_all((string) file_get_contents($sFile));
		$iCount = count($aTokens);
		for ($i = 0; $i < $iCount; $i++) {
			$mToken = $aTokens[$i];
			if (!is_array($mToken)) {
				continue;
			}
			if ($mToken[0] === T_USE) {
				// Imports only: `use function` and `use const` name no class.
				$j = $i + 1;
				while ($j < $iCount && is_array($aTokens[$j]) && $aTokens[$j][0] === T_WHITESPACE) {
					$j++;
				}
				if (is_array($aTokens[$j]) && in_array($aTokens[$j][0], [T_FUNCTION, T_CONST], true)) {
					while ($j < $iCount && $aTokens[$j] !== ';') {
						$j++;
					}
					$i = $j;
					continue;
				}
				$sPrefix = null;
				$bGroup = false;
				for (; $j < $iCount && $aTokens[$j] !== ';'; $j++) {
					$mNext = $aTokens[$j];
					if ($mNext === '{') {
						$bGroup = true;
					} elseif (is_array($mNext) && in_array($mNext[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
						$sName = ltrim($mNext[1], '\\');
						if ($bGroup) {
							// An item of a group use, until `as` or the next comma.
							if ($sPrefix !== null && !self::IsAliasName($aTokens, $j)) {
								$aNames[] = rtrim($sPrefix, '\\').'\\'.$sName;
							}
						} elseif ($sPrefix === null) {
							$sPrefix = $sName;
							if (!self::IsGroupPrefix($aTokens, $j)) {
								$aNames[] = $sName;
								$sPrefix = null;
							}
						}
					}
				}
				$i = $j;
				continue;
			}
			if ($mToken[0] === T_NAME_FULLY_QUALIFIED) {
				$aNames[] = ltrim($mToken[1], '\\');
			}
		}

		return array_values(array_unique(array_filter($aNames, static fn (string $sName): bool => str_starts_with($sName, self::ROOT))));
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $aTokens
	 */
	private static function IsGroupPrefix(array $aTokens, int $iPosition): bool
	{
		for ($j = $iPosition + 1; $j < count($aTokens); $j++) {
			if (is_array($aTokens[$j]) && $aTokens[$j][0] === T_WHITESPACE) {
				continue;
			}

			return is_array($aTokens[$j]) && $aTokens[$j][0] === T_NS_SEPARATOR;
		}

		return false;
	}

	/**
	 * Whether the name at this position is what follows `as` in a group use.
	 *
	 * @param list<array{0: int, 1: string, 2: int}|string> $aTokens
	 */
	private static function IsAliasName(array $aTokens, int $iPosition): bool
	{
		for ($j = $iPosition - 1; $j >= 0; $j--) {
			if (is_array($aTokens[$j]) && $aTokens[$j][0] === T_WHITESPACE) {
				continue;
			}

			return is_array($aTokens[$j]) && $aTokens[$j][0] === T_AS;
		}

		return false;
	}
}
