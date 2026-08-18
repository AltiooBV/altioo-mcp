<?php
/**
 * Generates a CycloneDX software bill of materials from composer.lock.
 *
 * Why this exists rather than the CycloneDX Composer plugin: the plugin has to
 * be allowed through `config.allow-plugins`, which means the release build runs
 * third-party code with the same rights as the build itself, to read a file
 * that is already JSON. The audit surface of this module is vendor/ - shipped,
 * not resolved by the installer - so the document describing it should be the
 * cheapest honest thing available.
 *
 * Production dependencies only: require-dev never reaches an installed
 * instance, and listing it would overstate what an operator is running.
 *
 * Deterministic on purpose. The serial number is derived from the lock's own
 * content hash and the timestamp honours SOURCE_DATE_EPOCH, so regenerating
 * without changing a dependency produces a byte-identical document and a diff
 * means something.
 *
 * Usage: php tools/sbom.php [> sbom.json]
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

$sRoot = dirname(__DIR__);

$aLock = json_decode(file_get_contents($sRoot.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$aRoot = json_decode(file_get_contents($sRoot.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$sEpoch = getenv('SOURCE_DATE_EPOCH');
$iEpoch = is_string($sEpoch) && ctype_digit($sEpoch) ? (int)$sEpoch : time();

// A stable identifier for "this exact set of dependencies", so two builds of
// the same lock agree and a changed dependency is visible as a changed serial.
$sSeed = hash('sha256', $aLock['content-hash'] ?? '');
$sSerial = sprintf(
    'urn:uuid:%s-%s-%s-%s-%s',
    substr($sSeed, 0, 8),
    substr($sSeed, 8, 4),
    // Version 5 nibble: this is a name-derived UUID, not a random one.
    '5'.substr($sSeed, 13, 3),
    dechex((hexdec(substr($sSeed, 16, 1)) & 0x3) | 0x8).substr($sSeed, 17, 3),
    substr($sSeed, 20, 12)
);

/**
 * One CycloneDX component per locked package.
 *
 * @param array<string, mixed> $aPackage
 *
 * @return array<string, mixed>
 */
function component(array $aPackage): array
{
    $aParts = explode('/', $aPackage['name'], 2);
    $sGroup = count($aParts) === 2 ? $aParts[0] : '';
    $sName = $aParts[count($aParts) - 1];
    $sVersion = $aPackage['version'];

    $aComponent = [
        'type'    => 'library',
        'name'    => $sName,
        'version' => $sVersion,
        'purl'    => 'pkg:composer/'.$aPackage['name'].'@'.rawurlencode($sVersion),
    ];

    if ($sGroup !== '') {
        $aComponent['group'] = $sGroup;
    }

    if (isset($aPackage['description'])) {
        $aComponent['description'] = $aPackage['description'];
    }

    // SPDX ids as Composer records them. An expression like "MIT OR GPL-2.0"
    // is not an id, so it goes in the field CycloneDX has for expressions.
    $aLicenses = [];
    foreach ((array)($aPackage['license'] ?? []) as $sLicense) {
        $aLicenses[] = preg_match('/\s(OR|AND)\s/', $sLicense) === 1
            ? ['expression' => $sLicense]
            : ['license' => ['id' => $sLicense]];
    }
    if ($aLicenses !== []) {
        $aComponent['licenses'] = $aLicenses;
    }

    // The dist reference is the commit or archive Composer resolved, which is
    // what makes "version 1.8.2" checkable rather than merely stated.
    if (isset($aPackage['dist']['reference'])) {
        $aComponent['externalReferences'] = [[
            'type'    => 'distribution',
            'url'     => $aPackage['dist']['url'] ?? '',
            'comment' => 'reference '.$aPackage['dist']['reference'],
        ]];
    }

    return $aComponent;
}

$aComponents = array_map('component', $aLock['packages'] ?? []);
usort($aComponents, static fn (array $a, array $b): int => strcmp(
    ($a['group'] ?? '').'/'.$a['name'],
    ($b['group'] ?? '').'/'.$b['name']
));

$aBom = [
    'bomFormat'    => 'CycloneDX',
    'specVersion'  => '1.5',
    'serialNumber' => $sSerial,
    'version'      => 1,
    'metadata'     => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $iEpoch),
        'tools'     => [['vendor' => 'Altioo', 'name' => 'tools/sbom.php']],
        'component' => [
            'type'    => 'application',
            'name'    => $aRoot['name'],
            'version' => moduleVersion($sRoot),
            'purl'    => 'pkg:composer/'.$aRoot['name'],
            'licenses' => [['expression' => $aRoot['license']]],
        ],
    ],
    'components' => $aComponents,
];

/**
 * The module version, read where the release actually declares it.
 */
function moduleVersion(string $sRoot): string
{
    $sXml = file_get_contents($sRoot.'/extension.xml');

    return preg_match('#<version>([^<]+)</version>#', $sXml, $aMatch) === 1 ? trim($aMatch[1]) : '0.0.0';
}

echo json_encode($aBom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
