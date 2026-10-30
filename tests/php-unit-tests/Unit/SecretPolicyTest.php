<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\SecretPolicy;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

// The policy matches iTop's attribute types by name. The bootstrap loads iTop
// before any test when one is reachable, so these stand-ins are declared only
// in a run that has no iTop at all and can never shadow the real classes.
if (!class_exists('AttributePassword')) {
	eval('
		class AttributeString {}
		class AttributeText extends AttributeString {}
		class AttributePassword extends AttributeString {}
		class AttributeEncryptedString extends AttributeString {}
		class AttributeEncryptedPassword extends AttributeEncryptedString {}
		class AttributeOneWayPassword {}
	');
}

/**
 * A secret the instance logs in to something else with is not written here
 * unless the operator allowed escalation.
 *
 * The class barrier in AccessGrants refused the outbound credentials iTop
 * ships by name, and left every class an extension adds - a mailbox for
 * mail-to-ticket, a device with a stored login - writable down to the
 * password. A caller replacing that password beside the login and the server
 * points the instance's own login at a system it chose. The type is what all
 * such classes have in common, so the rule is pinned on the type.
 */
class SecretPolicyTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const ROOT = __DIR__.'/../../..';

	/**
	 * @return array<string, array{string}>
	 */
	public static function recoverableTypes(): array
	{
		return [
			'password'           => ['AttributePassword'],
			'encrypted string'   => ['AttributeEncryptedString'],
			'encrypted password' => ['AttributeEncryptedPassword'],
		];
	}

	/**
	 * @dataProvider recoverableTypes
	 */
	public function testARecoverableSecretIsRefused(string $sType): void
	{
		$sRefusal = SecretPolicy::RefusalGiven(self::attribute($sType), 'password', false);

		$this->assertIsString($sRefusal, "{$sType} holds a secret the instance reads back to log in elsewhere with");
		$this->assertStringContainsString("'password'", $sRefusal, 'the refusal does not name the attribute');
		$this->assertStringContainsString('mcp_allow_privilege_escalation', $sRefusal, 'the refusal does not name the setting that lifts it');
	}

	/**
	 * @dataProvider recoverableTypes
	 */
	public function testTheEscalationSettingLiftsIt(string $sType): void
	{
		$this->assertNull(SecretPolicy::RefusalGiven(self::attribute($sType), 'password', true));
	}

	/**
	 * Ordinary text stays writable, and a one-way password is not this rule's:
	 * a class holding one is refused whole by AccessGrants, with its own
	 * setting and its own self guard, which a second refusal here would only
	 * muddle.
	 *
	 * @return array<string, array{string}>
	 */
	public static function otherTypes(): array
	{
		return [
			'string'            => ['AttributeString'],
			'text'              => ['AttributeText'],
			'one-way password'  => ['AttributeOneWayPassword'],
		];
	}

	/**
	 * @dataProvider otherTypes
	 */
	public function testAnythingElseIsLeftAlone(string $sType): void
	{
		$this->assertNull(SecretPolicy::RefusalGiven(self::attribute($sType), 'name', false));
	}

	/**
	 * An attribute definition of $sType, as the policy sees one.
	 *
	 * Built without its constructor: iTop's takes a code and a parameter list
	 * this test has no use for, and the stand-ins above take none.
	 */
	private static function attribute(string $sType): object
	{
		if (!class_exists($sType)) {
			self::markTestSkipped("{$sType} is declared by a module this iTop does not have installed.");
		}

		return (new \ReflectionClass($sType))->newInstanceWithoutConstructor();
	}

	/**
	 * Every place that turns a caller's value into an attribute value asks
	 * the policy first, as FormulaPolicyTest requires of formulas.
	 */
	public function testEveryWritePathAsksThePolicy(): void
	{
		$iFound = 0;
		$oFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS));
		foreach ($oFiles as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sSource = '';
			foreach (token_get_all((string) file_get_contents($oFile->getPathname())) as $mToken) {
				if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
					continue;
				}
				$sSource .= is_array($mToken) ? $mToken[1] : $mToken;
			}
			$iOffset = 0;
			while (($iMake = strpos($sSource, 'RestUtils::MakeValue(', $iOffset)) !== false) {
				$iFound++;
				$sBefore = substr($sSource, 0, $iMake);
				$iFunction = strrpos($sBefore, 'function ');
				$this->assertIsInt($iFunction);
				$this->assertStringContainsString(
					'SecretPolicy::RefusalFor(',
					substr($sBefore, $iFunction),
					$oFile->getFilename().' turns a caller value into an attribute value without asking SecretPolicy first'
				);
				$iOffset = $iMake + 1;
			}
		}

		$this->assertGreaterThanOrEqual(3, $iFound, 'expected the create, the shared update and stimulus (WritePlan), and the bulk write paths');
	}
}
