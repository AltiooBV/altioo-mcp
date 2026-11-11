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
 * The dependency order of src/, from doc/adr/0015-2-0-lays-out-src-as-itop-does.md,
 * point 2: a class imports only from its own layer or from one below it.
 *
 * Each file's class imports (`use` lines before the class, not `use function`,
 * not trait `use`), its fully-qualified `\Altioo\iTop\Extension\MCP\...` names
 * and its references to a class of its own namespace (which need no import
 * today and become one the day the file moves) are read from the PHP tokens, so
 * a mention in a comment or a string counts for nothing.
 *
 * The code is still in the pre-2.0 folders. CLASS_LAYERS names the layer each
 * class will belong to; a class not listed takes its layer from its namespace.
 * Each step of the ADR's landing plan deletes the entries of the classes it
 * moves.
 *
 * BASELINE is today's violations, frozen. The test fails on a violation that is
 * not in it, and on an entry that no longer occurs: a fix removes its line in
 * the same commit, so the list only shrinks and is empty when the ADR is done.
 * To see what a change did, run this test: each failure prints the lines to
 * add or to remove. Never add a line to make a new dependency pass.
 */
class LayerDependencyTest extends TestCase
{
	private const ROOT = 'Altioo\\iTop\\Extension\\MCP\\';

	/**
	 * Layers from top to bottom; a class may import its own layer and any layer
	 * with a larger rank. Same rank, different layer, is not allowed: the two
	 * chains Write > Read > Access and Portal > Access are independent, and
	 * Portal imports nothing of Read or Write (see mayImport()).
	 *
	 * Api is not in the order: anything may import it, and it imports only
	 * from API_MAY_IMPORT_FROM_RANK downward. Hook and Registry import downward
	 * only; Hook sits with Controller, Registry directly below Core.
	 * Nothing below Core may import Core\Tools\*, since all of Core is above it.
	 *
	 * @var array<string, int>
	 */
	private const LAYERS = [
		'Controller' => 10,
		'Hook' => 10,
		'Service\\Server' => 20,
		'Core' => 30,
		'Registry' => 35,
		'Service\\Write' => 40,
		'Service\\Portal' => 40,
		'Service\\Read' => 50,
		'Service\\Access' => 60,
		'Service\\Audit' => 70,
		'Helper' => 80,
		'Model' => 80,
		'Exception' => 80,
		'Api' => 0,
	];

	private const BOTTOM_RANK = 80;

	/** Api imports these ranks and below (Write, Portal, Read, Access, Audit, the bottom). */
	private const API_MAY_IMPORT_FROM_RANK = 40;

	/**
	 * Class (relative to the root namespace) => the layer ADR 0015 gives it.
	 * The target folder is in the comment. Delete the entry when the file moves.
	 *
	 * @var array<string, string>
	 */
	private const CLASS_LAYERS = [
		'Abstract\\AbstractBulkTool' => 'Api', // Api/
		'Abstract\\AbstractMCPPrompt' => 'Api', // Api/
		'Abstract\\AbstractMCPResource' => 'Api', // Api/
		'Abstract\\AbstractMCPResourceTemplate' => 'Api', // Api/
		'Abstract\\AbstractMCPTool' => 'Api', // Api/
		'Abstract\\AbstractObjectSearch' => 'Api', // Api/
		'Contract\\iMCPServiceProvider' => 'Api', // Api/
		'Testing\\ElementContract' => 'Api', // Api/
		'Helper\\ToolOutput' => 'Api', // Api/
		'Helper\\ResourceOutput' => 'Api', // Api/
		'Helper\\ChangeTracking' => 'Api', // Api/
		'Helper\\MCPHelper' => 'Helper', // Helper/, the rest of it; its version check goes to Api/ (the split decides)
		'Helper\\MCPHttp' => 'Controller', // Controller/
		'Helper\\Identifier' => 'Registry', // Registry/
		'Helper\\AccessGrants' => 'Service\\Access', // Service/Access/
		'Helper\\ExclusionPolicy' => 'Service\\Access', // Service/Access/
		'Helper\\CurrentUserReader' => 'Service\\Read', // Service/Read/
		'Helper\\DatamodelReader' => 'Service\\Read', // Service/Read/ (its rights grading goes to Service/Access/)
		'Helper\\DocumentAccess' => 'Service\\Read', // Service/Read/
		'Helper\\ObjectHistory' => 'Service\\Read', // Service/Read/
		'Helper\\ObjectQuery' => 'Service\\Read', // Service/Read/
		'Helper\\ObjectSerializer' => 'Service\\Read', // Service/Read/
		'Helper\\EditPolicy' => 'Service\\Write', // Service/Write/
		'Helper\\FormulaPolicy' => 'Service\\Write', // Service/Write/
		'Helper\\MentionPolicy' => 'Service\\Write', // Service/Write/
		'Helper\\SecretPolicy' => 'Service\\Write', // Service/Write/
		'Helper\\RestValue' => 'Service\\Write', // Service/Write/
		'Helper\\WritePlan' => 'Service\\Write', // Service/Write/
		'Helper\\JsonPayload' => 'Helper', // Helper/
		'Helper\\LogAPILogger' => 'Helper', // Helper/
		'Helper\\MCPContext' => 'Helper', // Helper/
		'Helper\\MCPLog' => 'Helper', // Helper/
		'Helper\\Settings' => 'Helper', // Helper/
		'Models\\MCPResult' => 'Model', // Model/
		'Server\\ServerInstructions' => 'Service\\Server', // Service/Server/
		'Server\\Session\\StatelessSessionStore' => 'Service\\Server', // Service/Server/
		'Service\\MCPService' => 'Service\\Server', // Service/Server/
		'Service\\AccessPolicy' => 'Service\\Access', // Service/Access/
		'Service\\ExcludingModelReflection' => 'Service\\Access', // Service/Access/
		'Service\\ObjectAccess' => 'Service\\Access', // Service/Access/
		'Service\\TokenScopes' => 'Service\\Access', // Service/Access/
		'Service\\PortalAccess' => 'Service\\Portal', // Service/Portal/
		'Service\\PortalAware' => 'Service\\Portal', // Service/Portal/
		'Service\\PortalBrickTemplates' => 'Service\\Portal', // Service/Portal/
		'Service\\PortalDescription' => 'Service\\Portal', // Service/Portal/
		'Service\\PortalMode' => 'Service\\Portal', // Service/Portal/
		'Service\\PortalPolicy' => 'Service\\Portal', // Service/Portal/
		'Service\\AuditRetention' => 'Service\\Audit', // Service/Audit/
		'Service\\ChangeSummary' => 'Service\\Audit', // Service/Audit/
		'Service\\ReadSummary' => 'Service\\Audit', // Service/Audit/
		'Service\\AuditHousekeeping' => 'Hook', // Hook/
	];

	/**
	 * Today's violations, `importer → imported`, sorted, one per line.
	 * Shrink it, never grow it.
	 */
	private const BASELINE = <<<'TXT'
Abstract\AbstractMCPPrompt → Helper\Identifier
Abstract\AbstractMCPResource → Helper\Identifier
Abstract\AbstractMCPResourceTemplate → Helper\Identifier
Abstract\AbstractMCPTool → Helper\Identifier
Core\Resources\Instructions → Service\MCPService
Core\Tools\Instructions → Service\MCPService
Helper\CurrentUserReader → Service\MCPService
Helper\CurrentUserReader → Service\PortalMode
Helper\DatamodelReader → Helper\SecretPolicy
Helper\DocumentAccess → Helper\WritePlan
Helper\ExclusionPolicy → Service\PortalMode
Helper\MCPHelper → Service\PortalMode
Helper\ObjectHistory → Service\PortalMode
Helper\ObjectSerializer → Helper\WritePlan
Helper\Settings → Helper\ExclusionPolicy
Helper\Settings → Helper\MCPHttp
Helper\WritePlan → Core\Tools\ObjectSearchByOQL
Helper\WritePlan → Service\PortalMode
Service\MCPService → Helper\MCPHttp
Service\ObjectAccess → Helper\ObjectHistory
Service\ObjectAccess → Helper\ObjectQuery
Service\ObjectAccess → Helper\ObjectSerializer
Service\ObjectAccess → Service\PortalAccess
Service\ObjectAccess → Service\PortalMode
Service\ObjectAccess → Service\PortalPolicy
Service\PortalAccess → Core\Tools\PortalList
Service\PortalAccess → Helper\DocumentAccess
Service\PortalAccess → Helper\ObjectHistory
Service\PortalDescription → Helper\DatamodelReader
Service\PortalDescription → Helper\ObjectHistory
Service\PortalPolicy → Helper\ObjectHistory
Service\TokenScopes → Helper\MCPHttp
Testing\ElementContract → Registry\MCPRegistry
TXT;

	public function testEveryClassHasALayer(): void
	{
		$aUnmapped = [];
		foreach (self::Classes() as $sClass => $sFile) {
			if (self::LayerOf($sClass) === null) {
				$aUnmapped[] = $sClass;
			}
		}
		$this->assertSame([], $aUnmapped, 'These classes are in no layer: add them to CLASS_LAYERS, or move them to a folder that is one.');
	}

	public function testClassLayersListsOnlyClassesThatExist(): void
	{
		$aClasses = self::Classes();
		$aStale = [];
		foreach (self::CLASS_LAYERS as $sClass => $sLayer) {
			if (!isset($aClasses[$sClass])) {
				$aStale[] = $sClass;
			}
			$this->assertArrayHasKey($sLayer, self::LAYERS, "$sClass names a layer that does not exist");
		}
		$this->assertSame([], $aStale, 'These CLASS_LAYERS entries no longer match a file: delete them.');
	}

	public function testDependenciesPointDownwardOnly(): void
	{
		$aFound = self::Violations();
		$aBaseline = array_values(array_filter(array_map('trim', explode("\n", self::BASELINE))));

		$aNew = array_values(array_diff($aFound, $aBaseline));
		$aGone = array_values(array_diff($aBaseline, $aFound));

		$this->assertSame(
			[],
			$aNew,
			"These imports break the layer order of ADR 0015 and are not in the baseline:\n".implode("\n", $aNew)
		);
		$this->assertSame(
			[],
			$aGone,
			"These baseline entries no longer occur - remove it from the baseline:\n".implode("\n", $aGone)
		);
		$this->assertSame($aBaseline, array_values(array_unique($aBaseline)), 'The baseline lists a line twice.');
		$aSorted = $aBaseline;
		sort($aSorted, SORT_STRING);
		$this->assertSame($aSorted, $aBaseline, 'Keep the baseline sorted.');
	}

	/**
	 * @return list<string> sorted `importer → imported` lines, classes relative to the root namespace
	 */
	public static function Violations(): array
	{
		$aViolations = [];
		foreach (self::Dependencies() as $sFrom => $aTargets) {
			$sFromLayer = self::LayerOf($sFrom);
			foreach (array_keys($aTargets) as $sTo) {
				$sToLayer = self::LayerOf($sTo);
				if ($sFromLayer === null || $sToLayer === null) {
					continue; // reported by testEveryClassHasALayer
				}
				if (!self::MayImport($sFromLayer, $sToLayer)) {
					$aViolations[$sFrom.' → '.$sTo] = true;
				}
			}
		}
		$aLines = array_keys($aViolations);
		sort($aLines, SORT_STRING);

		return $aLines;
	}

	private static function MayImport(string $sFrom, string $sTo): bool
	{
		if ($sFrom === $sTo || $sTo === 'Api') {
			return true;
		}
		if ($sFrom === 'Api') {
			return self::LAYERS[$sTo] >= self::API_MAY_IMPORT_FROM_RANK;
		}
		// The chains are independent: Portal reaches Access and below, not Read or Write.
		if ($sFrom === 'Service\\Portal' && in_array($sTo, ['Service\\Read', 'Service\\Write'], true)) {
			return false;
		}

		// Helper, Model and Exception are one tier: the bottom imports within itself.
		return self::LAYERS[$sTo] > self::LAYERS[$sFrom] || self::LAYERS[$sTo] === self::BOTTOM_RANK && self::LAYERS[$sFrom] === self::BOTTOM_RANK;
	}

	private static function LayerOf(string $sClass): ?string
	{
		if (isset(self::CLASS_LAYERS[$sClass])) {
			return self::CLASS_LAYERS[$sClass];
		}
		$sBest = null;
		foreach (array_keys(self::LAYERS) as $sLayer) {
			if (str_starts_with($sClass, $sLayer.'\\') && ($sBest === null || strlen($sLayer) > strlen($sBest))) {
				$sBest = $sLayer;
			}
		}

		return $sBest;
	}

	/**
	 * @return array<string, string> class relative to the root namespace => file
	 */
	private static function Classes(): array
	{
		static $aClasses = null;
		if ($aClasses === null) {
			$aClasses = [];
			$sSrc = dirname(__DIR__, 3).'/src/';
			$oIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sSrc, \FilesystemIterator::SKIP_DOTS));
			foreach ($oIterator as $oFile) {
				if ($oFile->getExtension() === 'php') {
					$sRelative = substr($oFile->getPathname(), strlen($sSrc), -4);
					$aClasses[str_replace('/', '\\', $sRelative)] = $oFile->getPathname();
				}
			}
			ksort($aClasses);
		}

		return $aClasses;
	}

	/**
	 * @return array<string, array<string, true>> class => the classes of src/ it imports, names fully-qualified or of its own namespace
	 */
	private static function Dependencies(): array
	{
		$aClasses = self::Classes();
		$aDependencies = [];
		foreach ($aClasses as $sClass => $sFile) {
			$aTargets = [];
			$aTokens = token_get_all((string) file_get_contents($sFile), TOKEN_PARSE);
			$iDepth = 0;
			$iCount = count($aTokens);
			$aSiblings = [];
			$iPos = strrpos($sClass, '\\');
			$sOwnNamespace = $iPos === false ? '' : substr($sClass, 0, $iPos + 1);
			foreach ($aClasses as $sOther => $_) {
				if ($sOther === $sClass || !str_starts_with($sOther, $sOwnNamespace)) {
					continue;
				}
				$sShort = substr($sOther, strlen($sOwnNamespace));
				if (!str_contains($sShort, '\\')) {
					$aSiblings[$sShort] = $sOther;
				}
			}
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
				switch ($mToken[0]) {
					case T_CURLY_OPEN:
					case T_DOLLAR_OPEN_CURLY_BRACES:
						$iDepth++;
						break;
					case T_USE:
						if ($iDepth !== 0) {
							break; // closure or trait use
						}
						for ($j = $i + 1; $j < $iCount && $aTokens[$j] !== ';'; $j++) {
							$mNext = $aTokens[$j];
							if (is_array($mNext) && in_array($mNext[0], [T_FUNCTION, T_CONST], true)) {
								break;
							}
							if (is_array($mNext) && in_array($mNext[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
								self::Collect(ltrim($mNext[1], '\\'), $aTargets);
							}
						}
						$i = $j;
						break;
					case T_NAME_FULLY_QUALIFIED:
						self::Collect(ltrim($mToken[1], '\\'), $aTargets);
						break;
					case T_STRING:
						$sName = $mToken[1];
						if (!isset($aSiblings[$sName])) {
							break;
						}
						$mPrev = self::PreviousSignificant($aTokens, $i);
						if (is_array($mPrev) && in_array($mPrev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_USE, T_AS], true)) {
							break;
						}
						if ($mPrev === '&' || $mPrev === '$') {
							break;
						}
						$aTargets[$aSiblings[$sName]] = true;
						break;
				}
			}
			unset($aTargets[$sClass]);
			$aDependencies[$sClass] = $aTargets;
		}

		return $aDependencies;
	}

	/**
	 * @param array<string, true> $aTargets
	 */
	private static function Collect(string $sName, array &$aTargets): void
	{
		if (str_starts_with($sName, self::ROOT)) {
			$sRelative = substr($sName, strlen(self::ROOT));
			if (isset(self::Classes()[$sRelative])) {
				$aTargets[$sRelative] = true;
			}
		}
	}

	/**
	 * @param list<array{0: int, 1: string, 2: int}|string> $aTokens
	 * @return array{0: int, 1: string, 2: int}|string|null
	 */
	private static function PreviousSignificant(array $aTokens, int $i)
	{
		for ($j = $i - 1; $j >= 0; $j--) {
			if (is_array($aTokens[$j]) && in_array($aTokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $aTokens[$j];
		}

		return null;
	}
}
