<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The release's tool manifest (tools/tools-manifest.php) is what a client pins
 * tool definitions against, so it has to be enumerable without an iTop, be the
 * same bytes twice, and say what each digest is over.
 */
class ToolsManifestTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		if (!is_file(dirname(__DIR__, 3).'/tools/tools-manifest.php')) {
			self::markTestSkipped('tools/ is not part of the release archive.');
		}
	}

	private static function run_manifest(): string
	{
		$sScript = dirname(__DIR__, 3).'/tools/tools-manifest.php';
		$sOut = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($sScript).' 2>&1');

		return is_string($sOut) ? $sOut : '';
	}

	public function testItIsTheSameBytesTwice(): void
	{
		$this->assertSame(self::run_manifest(), self::run_manifest());
	}

	public function testItListsEveryCoreToolWithADigestThatCanBeRecomputed(): void
	{
		$aManifest = json_decode(self::run_manifest(), true, 512, JSON_THROW_ON_ERROR);

		$iFiles = count(array_filter(
			glob(dirname(__DIR__, 3).'/src/Core/Tools/*.php') ?: [],
			static fn (string $s): bool => !str_starts_with(basename($s), 'Abstract')
		));
		$this->assertCount($iFiles, $aManifest['tools']);
		$this->assertSame(array_keys($aManifest['tools']), array_values(array_map(
			static fn (array $a): string => $a['definition']['name'],
			$aManifest['tools']
		)));

		// Decoded as objects as well: an empty JSON object read into an array
		// comes back as [], which is a different byte string and a different digest.
		$oManifest = json_decode(self::run_manifest(), false, 512, JSON_THROW_ON_ERROR);

		foreach ($aManifest['tools'] as $sName => $aTool) {
			$this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $sName);
			$this->assertNotSame('', (string)$aTool['definition']['description'], $sName);
			$this->assertSame('object', $aTool['definition']['inputSchema']['type'], $sName);
			$this->assertNotNull($aTool['definition']['annotations'], $sName.' has no annotations');
			$this->assertSame(
				$aTool['sha256'],
				hash('sha256', json_encode($oManifest->tools->$sName->definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
				$sName.': the digest is over the definition as the manifest prints it, compact'
			);
		}
	}
}
