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
 * The module's loader is registered by module.altioo-mcp.php, which names
 * vendor/autoload.php as a datamodel file - so iTop loads it during startup,
 * after its own, on every request to the environment. Registered last, it is
 * prepended last, and this module's copies are the ones that answer. That is
 * true of the console, the portal, cron, and now of the MCP endpoint as well.
 *
 * It was not always true of the MCP endpoint. index.php used to require
 * __DIR__.'/vendor/autoload.php' before approot.inc.php, which put the
 * module's loader first and iTop's second, and iTop's copies won there and
 * nowhere else. That require was removed because served from extensions/ it
 * resolved to a different absolute path than startup's, redeclared the
 * autoloader class and fatalled - see EntryPointAutoloaderTest. The endpoint
 * now resolves overlaps the way the rest of the application always has.
 *
 * The versions cannot be pinned to iTop's, because this module supports two
 * iTop branches that do not ship the same ones. So the overlap stands and is
 * checked instead. All seven satisfy what this module declares, which is why
 * ACCEPTED_DIVERGENCES below is empty - an empty list is the good state, not a
 * missing one. Anything that stops satisfying its constraint fails here until
 * somebody either fixes it or records it there with the reason it survives,
 * and any *eighth* package appearing in both trees fails the overlap test.
 *
 * KNOWN GAP, needs a decision. The assertions below still measure the
 * direction that stopped happening: they ask whether iTop's version satisfies
 * this module's constraints. It is a real compatibility check and it passes,
 * but it is now stricter than anything that runs. The question this file
 * should be asking after the change above is the reverse one - whether this
 * module's copies satisfy what *iTop's* installed.json declares - because
 * those are the copies that shadow iTop's libraries inside iTop's own code.
 * That check does not exist anywhere yet.
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
		// psr/http-factory was here: mcp/sdk asks for ^1.1 and iTop 3.2.2
		// shipped 1.0.2, survivable because the only difference is the return
		// types 1.1 added to the factory interfaces. iTop 3.2.3 ships 1.1.0,
		// so the divergence is gone on every patch this module now supports
		// and testEveryAcceptedDivergenceIsStillDiverging says so rather than
		// letting the entry sit here meaning nothing.
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
			"A double-shipped package is carried at a version iTop's copy does not satisfy.\n"
			.'This module\'s copy is the one that loads, so the constraint is met at runtime; what this reports '
			.'is that the two trees have drifted far enough apart that iTop\'s own code is now being handed a '
			.'version its branch never shipped. Either widen what depends on it, drop the package from this '
			.'module\'s tree, or - if it is survivable - record it in ACCEPTED_DIVERGENCES with the reason.'
		);
	}

	/**
	 * An accepted divergence that has been fixed upstream must be removed from
	 * the list, or the list stops meaning anything.
	 *
	 * One assertion, made whether or not the list has entries: a per-entry loop
	 * asserts nothing at all when the list is empty, and PHPUnit rightly calls
	 * that risky. Empty is the state this list is supposed to be in most of the
	 * time, so it has to be the state that is checked rather than the state
	 * that is skipped.
	 */
	public function testEveryAcceptedDivergenceIsStillDiverging(): void
	{
		$aOverlap = $this->overlappingPackages();

		$aStillDiverging = [];
		foreach (array_keys(self::ACCEPTED_DIVERGENCES) as $sPackage) {
			// Gone from one of the trees is not diverging either, and lands in
			// the same report: the entry has outlived what it described.
			if (!isset($aOverlap[$sPackage])) {
				continue;
			}

			foreach ($this->constraintsOn($sPackage) as $sConstraint) {
				if (!$this->satisfies($aOverlap[$sPackage][1], $sConstraint)) {
					$aStillDiverging[] = $sPackage;
					break;
				}
			}
		}

		$this->assertSame(
			array_keys(self::ACCEPTED_DIVERGENCES),
			$aStillDiverging,
			"An entry in ACCEPTED_DIVERGENCES has stopped diverging - either iTop moved or this module did.\n"
			.'Drop it. A list that records states which no longer happen is a list nobody rereads, and the '
			.'next real divergence gets added to it without anyone checking the ones already there.'
		);
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
