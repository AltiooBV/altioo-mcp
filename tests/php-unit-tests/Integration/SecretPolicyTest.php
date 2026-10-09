<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\SecretPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use AttributePassword;
use AttributeEncryptedString;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The secret rule, against the attribute definitions iTop actually compiled.
 *
 * The unit test pins the rule on stand-ins named like iTop's types. This one
 * asks the installed datamodel, so a type iTop renames or re-parents shows up
 * here as an attribute the rule no longer sees.
 */
class SecretPolicyTest extends ItopDataTestCaseAlias
{
	/**
	 * Every recoverable secret the installed datamodel declares is refused.
	 *
	 * Never vacuous: the core declares one on every instance, the change
	 * log's record of a previous encrypted value.
	 */
	public function testEveryRecoverableSecretInTheDatamodelIsRefused(): void
	{
		$aFound = [];
		foreach (MetaModel::GetClasses() as $sClass) {
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
				if (!$oAttDef instanceof AttributePassword && !$oAttDef instanceof AttributeEncryptedString) {
					continue;
				}
				$aFound[] = "{$sClass}.{$sAttCode}";
				$this->assertNotNull(
					SecretPolicy::RefusalGiven($oAttDef, $sAttCode, false),
					"{$sClass}.{$sAttCode} is a ".get_class($oAttDef).' and is not refused'
				);
			}
		}

		$this->assertNotEmpty($aFound, 'no recoverable secret found at all, so the type names no longer match what iTop compiles');
	}

	/**
	 * Ordinary text on an ordinary class stays writable.
	 */
	public function testAnOrdinaryAttributeIsNotRefused(): void
	{
		$this->assertNull(SecretPolicy::RefusalGiven(MetaModel::GetAttributeDef('Organization', 'name'), 'name', false));
	}
}
