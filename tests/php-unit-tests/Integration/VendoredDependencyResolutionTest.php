<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Which copy of a double-shipped library actually runs.
 *
 * This module ships a vendor/ tree because store users never run Composer.
 * iTop ships one too, and seven packages appear in both. Two Composer class
 * loaders in one process do not merge: each is prepended on registration, so
 * the one registered *last* answers first, and the loser's copy is never
 * loaded at all.
 *
 * On the MCP endpoint the module's autoloader is required by index.php before
 * approot.inc.php, and iTop's is therefore registered afterwards - which means
 * iTop's is prepended last and iTop's copies win. Every overlapping package
 * runs at the version iTop ships, not the version composer.lock records.
 *
 * That is not fixable by reordering. Prepending the module's loader again
 * after startup would shadow iTop's own libraries for the rest of the request,
 * inside core code that was written against them; and the versions cannot be
 * pinned to iTop's either, because this module supports two iTop branches that
 * do not ship the same ones.
 *
 * So the arrangement stands and is checked instead: whatever executes has to
 * satisfy what this module's dependency graph declares it needs. Six of the
 * seven do. The seventh is recorded below with the reason it is survivable,
 * so that it is a known state rather than a silent one - and so that any
 * *eighth* fails this test.
 */
class VendoredDependencyResolutionTest extends TestCase
{
	// tests/php-unit-tests/Integration -> module root
	private const MODULE_ROOT = __DIR__.'/../../..';

	/**
	 * Overlaps that do not satisfy the declared constraint and are shipped
	 * anyway, each with what makes it survivable. Anything not listed here
	 * must satisfy its constraint.
	 *
	 * @var array<string, string>
	 */
	private const ACCEPTED_DIVERGENCES = [
		'psr/http-factory' =>
			'mcp/sdk asks for ^1.1, iTop 3.2 ships 1.0.2. The only difference between them is that '
			.'1.1 added return types to the factory interfaces. nyholm/psr7 declares those return types on its '
			.'own methods, and PHP allows a method to add a return type the interface leaves unspecified - so '
			.'the typed implementation satisfies the untyped interface. It would stop being survivable if the '
			.'SDK began calling something 1.1 introduced.',
	];

	/** @var array<string, mixed>|null */
	private static ?array $aLock = null;

	private static ?string $sItopRoot = null;

	protected function setUp(): void
	{
		parent::setUp();

		self::$sItopRoot = self::locateItop();
		if (self::$sItopRoot === null) {
			$this->markTestSkipped(
				'No iTop tree carrying lib/composer/installed.json to compare against. '
				.'Run this from an installed iTop, or point ITOP_ROOT at one.'
			);
		}
	}

	/**
	 * The iTop whose libraries would win.
	 *
	 * All this needs is one JSON file, so it does not go through the
	 * bootstrap's locator: that one boots the application when it succeeds,
	 * and booting needs a configured instance this comparison has no use for.
	 */
	private static function locateItop(): ?string
	{
		$aCandidates = [];

		if (defined('APPROOT')) {
			$aCandidates[] = rtrim(APPROOT, '/');
		}

		$sFromEnv = getenv('ITOP_ROOT');
		if (is_string($sFromEnv) && $sFromEnv !== '') {
			$aCandidates[] = rtrim($sFromEnv, '/');
		}

		// <itop-web-root>/extensions/<module> -> two levels up.
		$aCandidates[] = dirname(self::MODULE_ROOT, 2);

		foreach ($aCandidates as $sCandidate) {
			if (is_file($sCandidate.'/lib/composer/installed.json')) {
				return $sCandidate;
			}
		}

		return null;
	}

	/**
	 * The whole point. For every package both trees carry, the version iTop
	 * ships - the one that runs - has to satisfy every constraint this
	 * module's own dependency graph puts on it.
	 */
	public function testWhatRunsSatisfiesWhatTheLockRequires(): void
	{
		$aFailures = [];

		foreach ($this->overlappingPackages() as $sPackage => $aVersions) {
			[$sMine, $sTheirs] = $aVersions;

			foreach ($this->constraintsOn($sPackage) as $sBy => $sConstraint) {
				if ($this->satisfies($sTheirs, $sConstraint)) {
					continue;
				}

				$aFailures[$sPackage] = sprintf(
					'%s (%s requires %s; composer.lock has %s, iTop runs %s)',
					$sPackage,
					$sBy,
					$sConstraint,
					$sMine,
					$sTheirs
				);
			}
		}

		$aUnexpected = array_diff_key($aFailures, self::ACCEPTED_DIVERGENCES);

		$this->assertSame(
			[],
			array_values($aUnexpected),
			"A double-shipped package runs at a version this module does not declare support for.\n"
			.'iTop\'s copy is the one that loads on the MCP endpoint, so composer.lock is describing code that '
			.'does not execute. Either widen what depends on it, drop the package from this module\'s tree, or - '
			.'if it is survivable - record it in ACCEPTED_DIVERGENCES with the reason.'
		);
	}

	/**
	 * An accepted divergence that has been fixed upstream must be removed from
	 * the list, or the list stops meaning anything.
	 */
	public function testEveryAcceptedDivergenceIsStillDiverging(): void
	{
		$aOverlap = $this->overlappingPackages();

		foreach (self::ACCEPTED_DIVERGENCES as $sPackage => $sReason) {
			$this->assertArrayHasKey(
				$sPackage,
				$aOverlap,
				sprintf('%s is no longer shipped by both trees; drop it from ACCEPTED_DIVERGENCES.', $sPackage)
			);

			$bStillDiverges = false;
			foreach ($this->constraintsOn($sPackage) as $sConstraint) {
				if (!$this->satisfies($aOverlap[$sPackage][1], $sConstraint)) {
					$bStillDiverges = true;
				}
			}

			$this->assertTrue(
				$bStillDiverges,
				sprintf('%s now satisfies every constraint; drop it from ACCEPTED_DIVERGENCES.', $sPackage)
			);
		}
	}

	/**
	 * The overlap is worth naming out loud: it is what a reader of the release
	 * archive has to know, and it grows silently every time either side adds a
	 * dependency.
	 */
	public function testTheOverlapIsNotEmptyAndIsAllPsrPlusAssert(): void
	{
		$aOverlap = $this->overlappingPackages();

		$this->assertNotEmpty($aOverlap, 'no overlap found at all - the comparison is probably reading the wrong tree');

		foreach (array_keys($aOverlap) as $sPackage) {
			$this->assertMatchesRegularExpression(
				'#^(psr/|webmozart/assert$)#',
				$sPackage,
				sprintf(
					'%s is now double-shipped as well. It is not a PSR interface package, so it carries behaviour '
					.'rather than a contract, and two versions of behaviour is a different conversation.',
					$sPackage
				)
			);
		}
	}

	/**
	 * Packages both trees carry.
	 *
	 * @return array<string, array{0: string, 1: string}> package => [locked here, shipped by iTop]
	 */
	private function overlappingPackages(): array
	{
		$aMine = [];
		foreach ($this->lock()['packages'] as $aPackage) {
			$aMine[$aPackage['name']] = ltrim($aPackage['version'], 'v');
		}

		$aTheirs = [];
		$aInstalled = json_decode(file_get_contents(self::$sItopRoot.'/lib/composer/installed.json'), true);
		foreach ($aInstalled['packages'] ?? $aInstalled as $aPackage) {
			$aTheirs[$aPackage['name']] = ltrim($aPackage['version'], 'v');
		}

		$aOverlap = [];
		foreach (array_intersect_key($aMine, $aTheirs) as $sName => $sVersion) {
			$aOverlap[$sName] = [$sVersion, $aTheirs[$sName]];
		}
		ksort($aOverlap);

		return $aOverlap;
	}

	/**
	 * Every constraint this module's graph puts on one package, keyed by who
	 * asks for it.
	 *
	 * @return array<string, string>
	 */
	private function constraintsOn(string $sPackage): array
	{
		$aConstraints = [];

		$aRoot = json_decode(file_get_contents(self::MODULE_ROOT.'/composer.json'), true);
		if (isset($aRoot['require'][$sPackage])) {
			$aConstraints['this module'] = $aRoot['require'][$sPackage];
		}

		foreach ($this->lock()['packages'] as $aPackage) {
			if (isset($aPackage['require'][$sPackage])) {
				$aConstraints[$aPackage['name']] = $aPackage['require'][$sPackage];
			}
		}

		return $aConstraints;
	}

	/**
	 * Whether a version satisfies a constraint.
	 *
	 * Handles the caret unions that are the only form this dependency graph
	 * uses, and fails loudly on anything else rather than guessing - a
	 * constraint silently read as "satisfied" would make this whole test
	 * pass for the wrong reason. composer/semver is not a dependency of this
	 * module and is not worth becoming one for six comparisons.
	 */
	private function satisfies(string $sVersion, string $sConstraint): bool
	{
		foreach (explode('||', $sConstraint) as $sAlternative) {
			$sAlternative = trim($sAlternative);

			if (!str_starts_with($sAlternative, '^')) {
				$this->fail(sprintf(
					'This test only understands caret constraints and it met "%s". Teach satisfies() that form, '
					.'or the comparison it guards is not being made.',
					$sConstraint
				));
			}

			$sFloor = substr($sAlternative, 1);
			if (version_compare($sVersion, $sFloor, '<')) {
				continue;
			}

			if (version_compare($sVersion, $this->ceilingOf($sFloor), '<')) {
				return true;
			}
		}

		return false;
	}

	/** The first version a caret constraint no longer admits. */
	private function ceilingOf(string $sFloor): string
	{
		$aParts = array_map('intval', explode('.', $sFloor) + [0, 0, 0]);

		// Below 1.0.0 the minor is the breaking digit, which is what makes
		// mcp/sdk's ^0.7.1 mean "0.7.x and nothing else".
		return $aParts[0] === 0
			? sprintf('0.%d.0', $aParts[1] + 1)
			: sprintf('%d.0.0', $aParts[0] + 1);
	}

	/** @return array<string, mixed> */
	private function lock(): array
	{
		if (self::$aLock === null) {
			self::$aLock = json_decode(file_get_contents(self::MODULE_ROOT.'/composer.lock'), true);
		}

		return self::$aLock;
	}
}
