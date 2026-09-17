<?php
/**
 * Resolves the declared support policy into a concrete CI matrix.
 *
 * `.github/itop-support.json` names branches - 3.2, 3.3 - because that is what
 * the extension actually claims. What CI has to install is a specific release.
 * This turns one into the other by asking what the newest patch of each branch
 * is, which is the only way the matrix stays true after a patch nobody here
 * noticed was published.
 *
 * Each branch resolves to two things, and they are not interchangeable:
 *
 *   zip_url  the packaged release, from SourceForge. This is the iTop that gets
 *            installed. It has to be: the git repository does not contain every
 *            module a release ships - authent-token, which this extension
 *            depends on, is in no source tag at all - so a source tree installs
 *            as an iTop that silently drops this module.
 *   tag      the matching git tag, used for one thing only: iTop's test harness
 *            (tests/php-unit-tests/src/BaseTestCase), which is absent from
 *            packaged releases and which every integration test here extends.
 *
 * Usage:
 *   php tools/ci/resolve-itop-versions.php            # human readable, to stdout
 *   php tools/ci/resolve-itop-versions.php --matrix   # GitHub matrix JSON, to stdout
 *   php tools/ci/resolve-itop-versions.php --zip=3.2  # URL of the packaged release
 *
 * Exits non-zero when a declared branch resolves to nothing, because a branch
 * we claim to support and cannot even find is a claim to correct, not a run to
 * skip quietly.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

const SUPPORT_FILE = __DIR__.'/../../.github/itop-support.json';
const TAGS_API = 'https://api.github.com/repos/Combodo/iTop/tags?per_page=100';
// The packaged releases. Not every one of them is mirrored to GitHub releases -
// 3.2.2 is not - so SourceForge is the listing that answers for every branch.
//
// limit=100 because that is the largest SourceForge accepts: anything above it
// is answered 400, and this file treats an unreadable listing as fatal, so a
// larger number is not a longer listing but no listing at all. 100 entries
// reach back past 2.4, which is several branches further than we claim.
const FILES_RSS = 'https://sourceforge.net/projects/itop/rss?path=/itop&limit=100';
const PAGES = 4; // ~400 tags, back past 2.6. More than enough for any live branch.

/**
 * A tag this script understands, or null.
 *
 * iTop tags a patch as `3.2.3`, a re-spin of that patch as `3.2.3-2`, and a
 * pre-release as `3.3.0-beta1`. Everything else in that repository's tag list -
 * `saas-1.0.18`, `N2016`, `3.1.0-designer-2` - is not a release of the product
 * and is dropped here rather than sorted.
 *
 * The returned key sorts newest last: patch, then stable above pre-release,
 * then pre-release stage, then the re-spin number.
 */
function parse_tag(string $sTag): ?array
{
	if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta|rc)(\d*))?(?:-(\d+))?$/', $sTag, $aM)) {
		return null;
	}
	$aStages = ['alpha' => 0, 'beta' => 1, 'rc' => 2];
	$bStable = ($aM[4] ?? '') === '';

	return [
		'tag'     => $sTag,
		'branch'  => $aM[1].'.'.$aM[2],
		'stable'  => $bStable,
		'key'     => [
			(int)$aM[3],
			$bStable ? 1 : 0,
			$bStable ? 0 : $aStages[$aM[4]],
			$bStable ? 0 : (int)($aM[5] === '' ? 1 : $aM[5]),
			(int)($aM[6] ?? 0),
		],
	];
}

function fetch_tags(): array
{
	$aHeaders = [
		'User-Agent: altioo-mcp-ci',
		'Accept: application/vnd.github+json',
	];
	// Unauthenticated the API allows 60 calls an hour per runner IP, which a
	// busy matrix will exhaust. Actions always has a token; use it.
	$sToken = getenv('GITHUB_TOKEN') ?: '';
	if ($sToken !== '') {
		$aHeaders[] = 'Authorization: Bearer '.$sToken;
	}

	$aTags = [];
	for ($iPage = 1; $iPage <= PAGES; $iPage++) {
		$sBody = @file_get_contents(TAGS_API.'&page='.$iPage, false, stream_context_create([
			'http' => ['header' => implode("\r\n", $aHeaders), 'timeout' => 20, 'ignore_errors' => true],
		]));
		if ($sBody === false) {
			fwrite(STDERR, "could not reach the GitHub tag API\n");
			exit(1);
		}
		$aPage = json_decode($sBody, true);
		if (!is_array($aPage) || (isset($aPage['message']) && !isset($aPage[0]))) {
			fwrite(STDERR, "the GitHub tag API answered: ".($aPage['message'] ?? 'something unparseable')."\n");
			exit(1);
		}
		if (count($aPage) === 0) {
			break;
		}
		foreach ($aPage as $aTag) {
			$aTags[] = (string)$aTag['name'];
		}
	}

	return $aTags;
}

/**
 * URL of the newest packaged zip of a branch.
 *
 * The archive name carries a build number nobody can predict and a release
 * label that does not always match the git tag - 3.2.2's archive is
 * iTop-3.2.2-1-17851.zip, published under a tag called 3.2.2 - so the listing
 * has to be read rather than a URL constructed.
 */
function resolve_zip(string $sBranch, bool $bAllowPre): ?array
{
	$sRss = @file_get_contents(FILES_RSS, false, stream_context_create([
		'http' => ['header' => 'User-Agent: altioo-mcp-ci', 'timeout' => 30],
	]));
	if ($sRss === false) {
		fwrite(STDERR, "could not reach the SourceForge file listing\n");
		exit(1);
	}

	preg_match_all('#/itop/([^/]+)/(iTop-([0-9][^/]*?)-(\d+)\.zip)/download#', $sRss, $aMatches, PREG_SET_ORDER);

	$aBest = null;
	$sBestUrl = null;
	foreach ($aMatches as $aMatch) {
		$aParsed = parse_tag($aMatch[3]);
		if ($aParsed === null || $aParsed['branch'] !== $sBranch) {
			continue;
		}
		if (!$aParsed['stable'] && !$bAllowPre) {
			continue;
		}
		if ($aBest === null || $aParsed['key'] > $aBest['key']) {
			$aBest = $aParsed;
			$sBestUrl = 'https://downloads.sourceforge.net/project/itop/itop/'.$aMatch[1].'/'.$aMatch[2];
		}
	}

	return $sBestUrl === null ? null : [
		'release' => $aBest['tag'],   // the release label, e.g. 3.2.3-2
		'stable'  => $aBest['stable'],
		'url'     => $sBestUrl,
	];
}

/**
 * The git tag holding the test harness for a release, or null.
 *
 * A release and its tag do not always carry the same label: the archive
 * iTop-3.2.2-1-17851.zip is published under a tag called 3.2.2. Try the exact
 * label first, then the label without its re-spin suffix.
 */
function pair_tag(string $sRelease, array $aTags): ?string
{
	if (in_array($sRelease, $aTags, true)) {
		return $sRelease;
	}
	$sBase = preg_replace('/-\d+$/', '', $sRelease);

	return ($sBase !== $sRelease && in_array($sBase, $aTags, true)) ? $sBase : null;
}

$aSupport = json_decode((string)file_get_contents(SUPPORT_FILE), true, 512, JSON_THROW_ON_ERROR);

foreach ($argv as $sArg) {
	if (!str_starts_with($sArg, '--zip=')) {
		continue;
	}
	$sBranch = substr($sArg, 6);
	$bAllowPre = false;
	foreach ($aSupport['branches'] as $aBranch) {
		if ($aBranch['branch'] === $sBranch) {
			$bAllowPre = (bool)($aBranch['allow_prerelease'] ?? false);
		}
	}
	$aZip = resolve_zip($sBranch, $bAllowPre);
	if ($aZip === null) {
		fwrite(STDERR, "no packaged release found for branch $sBranch\n");
		exit(1);
	}
	echo $aZip['url'], "\n";
	exit(0);
}
$aTags = fetch_tags();

$aResolved = [];
$bFailed = false;

foreach ($aSupport['branches'] as $aBranch) {
	$sBranch = $aBranch['branch'];
	$bAllowPre = (bool)($aBranch['allow_prerelease'] ?? false);

	// The release, not the tag, is what gets installed - so it is what decides
	// which version this branch resolves to.
	$aZip = resolve_zip($sBranch, $bAllowPre);
	if ($aZip === null) {
		fwrite(STDERR, "no packaged release found for the declared branch $sBranch"
			.($bAllowPre ? '' : ' (pre-releases are not allowed for it)')."\n");
		$bFailed = true;
		continue;
	}

	$sRelease = $aBranch['pin'] ?? $aZip['release'];
	$sTag = pair_tag($sRelease, $aTags);
	if ($sTag === null) {
		// Not fatal here. The install still happens; it is the integration
		// suite that has no harness to run under, and the job that needs one
		// is the one that should complain.
		fwrite(STDERR, "warning: no git tag matches release $sRelease - no test harness for it\n");
	}

	$aResolved[] = [
		'branch'  => $sBranch,
		'release' => $sRelease,
		'tag'     => $sTag ?? '',
		'zip_url' => $aZip['url'],
		'php'     => $aBranch['php'],
		'stable'  => $aZip['stable'],
		'pinned'  => isset($aBranch['pin']),
	];
}

if ($bFailed) {
	exit(1);
}

if (in_array('--matrix', $argv, true)) {
	$aInclude = [];
	foreach ($aResolved as $aEntry) {
		foreach ($aEntry['php'] as $sPhp) {
			$aInclude[] = [
				'itop_branch'  => $aEntry['branch'],
				'itop_release' => $aEntry['release'],
				'itop_tag'     => $aEntry['tag'],
				'zip_url'      => $aEntry['zip_url'],
				'php'          => $sPhp,
				// A branch resolved to a beta is a warning shot, not a gate:
				// the job runs and reports, and does not fail the pull request
				// of somebody who had nothing to do with it.
				'stable'       => (bool)($aEntry['stable'] ?? true),
			];
		}
	}
	echo json_encode(['include' => $aInclude], JSON_UNESCAPED_SLASHES), "\n";
	exit(0);
}

foreach ($aResolved as $aEntry) {
	printf(
		"iTop %-4s -> release %-12s %-13s harness from tag %-12s on PHP %s\n",
		$aEntry['branch'],
		$aEntry['release'],
		($aEntry['pinned'] ?? false) ? '(pinned)' : (($aEntry['stable'] ?? true) ? '(stable)' : '(pre-release)'),
		$aEntry['tag'] !== '' ? $aEntry['tag'] : '(none found)',
		implode(', ', $aEntry['php'])
	);
}
