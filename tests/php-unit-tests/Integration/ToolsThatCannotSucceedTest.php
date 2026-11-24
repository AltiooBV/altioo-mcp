<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C6: a tool that can never succeed for the caller is not served, rather than listed and refused
 * on every call. Asked where registration asks, ServedElements::isHidden().
 */
class ToolsThatCannotSucceedTest extends ItopDataTestCaseAlias
{
	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	private function hidden(string $sName, object $oTool): bool
	{
		return ServedElements::isHidden($sName, $oTool, [], AccessPolicy::Unrestricted());
	}

	private function loginAs(string $sProfile): void
	{
		$oProfile = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :n'), [], ['n' => $sProfile]))->Fetch();
		if ($oProfile === null) {
			$this->markTestSkipped("No profile {$sProfile}.");
		}
		$sSuffix = bin2hex(random_bytes(4));
		$iPerson = (int)$this->CreatePerson(1, (int)$this->CreateOrganization('CannotSucceed '.$sSuffix)->GetKey())->GetKey();
		$this->CreateUser('cannot-succeed-'.$sSuffix, (int)$oProfile->GetKey(), 'Cannot-Succeed-'.$sSuffix.'-1!', $iPerson);
		UserRights::Login('cannot-succeed-'.$sSuffix);
	}

	public function testBulkDeleteIsServedToAnAccountThatMayDeleteInBulk(): void
	{
		$this->loginAs('Administrator');

		$this->assertFalse($this->hidden('core_object_bulk_delete', new ObjectBulkDelete()));
	}

	public function testBulkDeleteIsNotServedWhenNoClassAllowsIt(): void
	{
		// The stock Portal user holds no bulk delete on any class.
		$this->loginAs('Portal user');
		$bAny = false;
		foreach (MetaModel::GetClasses() as $sClass) {
			$bAny = $bAny || (!MetaModel::IsAbstract($sClass) && UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE));
		}
		if ($bAny) {
			$this->markTestSkipped('This iTop grants the Portal user a bulk delete.');
		}

		$this->assertTrue($this->hidden('core_object_bulk_delete', new ObjectBulkDelete()));
		$this->assertFalse($this->hidden('core_object_history', new ObjectHistory()), 'history is the console\'s whatever its rights: only a portal that shows none hides it');
	}

	public function testHistoryIsNotServedInAPortalThatShowsNone(): void
	{
		if (!class_exists('Combodo\iTop\Portal\Brick\BrickCollection') || !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal on iTop 3.2.2 or later.');
		}
		$this->loginAs('Portal user');
		RequestScope::EnterPortal('itop-portal');

		// The stock portal grants no brick that reaches the change log.
		$this->assertTrue($this->hidden('core_object_history', new ObjectHistory()));
	}
}
