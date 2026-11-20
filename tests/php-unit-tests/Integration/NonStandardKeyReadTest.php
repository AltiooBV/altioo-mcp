<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSet;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Notes of the medium fixes (item 10): `core_object_get` on iTop's `lnkTriggerAction` raised an unhandled OqlNormalizeException, because the
 * class names its key `link_id` and the by-id query used MetaModel::DBGetKey(), the column, where OQL says `id` for every class.
 */
class NonStandardKeyReadTest extends ItopDataTestCaseAlias
{
	public function testAClassWhoseKeyColumnIsNotNamedIdIsReadById(): void
	{
		if (!MetaModel::IsValidClass('lnkTriggerAction') || MetaModel::DBGetKey('lnkTriggerAction') === 'id') {
			$this->markTestSkipped('Needs a class whose key column is not `id` (iTop\'s lnkTriggerAction).');
		}
		$oTrigger = $this->createTriggerLink();

		$this->assertSame(1, (new DBObjectSet(ObjectQuery::ById('lnkTriggerAction', (int)$oTrigger->GetKey())))->Count());
		$this->assertSame(1, (new DBObjectSet(ObjectQuery::ByIds('lnkTriggerAction', [(int)$oTrigger->GetKey()])))->Count());

		$aResult = ToolOutput::Decode(ObjectGet::execute('lnkTriggerAction', (int)$oTrigger->GetKey()));

		$this->assertSame('lnkTriggerAction', $aResult['class']);
		$this->assertSame((int)$oTrigger->GetKey(), $aResult['object'][MetaModel::DBGetKey('lnkTriggerAction')] ?? $aResult['object']['id'] ?? null);
	}

	private function createTriggerLink(): \DBObject
	{
		$oSet = new DBObjectSet(\DBObjectSearch::FromOQL('SELECT lnkTriggerAction'));
		$oExisting = $oSet->Fetch();
		if ($oExisting !== null) {
			return $oExisting;
		}

		$oAction = MetaModel::NewObject('ActionNotification');
		$this->markTestSkipped('No trigger link in this instance and none is made here: '.get_class($oAction));
	}
}
