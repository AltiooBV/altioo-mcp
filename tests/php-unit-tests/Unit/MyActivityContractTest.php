<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\MyActivity;
use Altioo\iTop\Extension\MCP\Service\Audit\ChangeSummary;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * core_my_activity answers for the caller and for no one else, and returns
 * nothing that can quote a third party.
 *
 * What it does with a database is run on an instance (OwnActivityTest). These
 * are the properties that can be pinned from the source and the schema, and the
 * parsing of the two lines it reads back, against the code that writes them.
 */
class MyActivityContractTest extends TestCase
{
	private static function source(): string
	{
		return (string)file_get_contents(dirname(__DIR__, 3).'/src/Core/Tools/MyActivity.php');
	}

	/** There is no way to say whose activity: not in the schema, not in the signature. */
	public function testThereIsNoArgumentThatNamesAUser(): void
	{
		$aProperties = array_keys((new MyActivity())->getInputSchema()['properties']);
		$this->assertEqualsCanonicalizing(['since', 'limit', 'offset'], $aProperties);

		$aParameters = array_map(static fn ($o): string => $o->getName(), (new ReflectionMethod(MyActivity::class, 'execute'))->getParameters());
		$this->assertEqualsCanonicalizing(['since', 'limit', 'offset'], $aParameters);
	}

	/** The filter is the real user's key, taken from iTop and not from the request, and never a login. */
	public function testTheFilterIsTheRealUsersKeyAndNothingElse(): void
	{
		$sSource = self::source();

		$this->assertStringContainsString('CMDBChange::GetCurrentUserId()', $sSource);
		$this->assertStringContainsString("'SELECT AltiooEventMCPService WHERE user_id = :user'", $sSource);
		$this->assertStringNotContainsString('userinfo', preg_replace('#/\*.*?\*/#s', '', $sSource) ?? '', 'a login is never matched');
		$this->assertStringNotContainsString('GetUserId()', str_replace('GetCurrentUserId()', '', $sSource), 'the impersonated user is not the account that acted');
	}

	/**
	 * Raw parameters, what a tool said, the login and the error reference are
	 * not returned: any of them can hold a value or a name of someone else.
	 *
	 * @dataProvider neverReturnedProvider
	 */
	public function testAColumnThatCanQuoteAThirdPartyIsNeverRead(string $sColumn): void
	{
		$sCode = preg_replace('#/\*.*?\*/#s', '', self::source()) ?? '';

		$this->assertStringNotContainsString("'".$sColumn."'", $sCode);
	}

	/** @return array<string, array{0: string}> */
	public static function neverReturnedProvider(): array
	{
		return [
			'request_params' => ['request_params'],
			'refusal_text'   => ['refusal_text'],
			'error_ref'      => ['error_ref'],
			'message'        => ['message'],
			'userinfo'       => ['userinfo'],
		];
	}

	/**
	 * Served in portal mode as the one exception ADR 0010 records, and read
	 * grade: it changes nothing.
	 */
	public function testItIsServedInPortalModeUnderADecidedAdr(): void
	{
		$oTool = new MyActivity();

		$this->assertInstanceOf(PortalAware::class, $oTool);
		$this->assertTrue($oTool->getAnnotations()->readOnlyHint);
		$sAdr = (string)file_get_contents(dirname(__DIR__, 3).'/doc/adr/0010-portal-mode-serves-the-users-own-activity.md');
		$this->assertStringContainsString('Status: accepted', $sAdr);
		$this->assertStringContainsString('Decided by: Guy Couronne', $sAdr);
		$this->assertStringContainsString('not as a precedent', $sAdr);
	}

	/**
	 * In portal mode an object is named only if the portal shows it, a code
	 * only if the view form does, and the instance's configuration is not
	 * told: pinned in the source, run in OwnActivityTest.
	 */
	public function testThePortalNarrowingIsThePortalsOwn(): void
	{
		$sSource = self::source();

		$this->assertStringContainsString('$oAccess->Readable($sClass, (int)$iId)', $sSource);
		$this->assertStringContainsString('$oAccess->ShownOf($oObject)', $sSource);
		$this->assertStringContainsString("Some earlier calls may not be listed.", $sSource);
	}

	public function testItReadsBackWhatChangeSummaryWrites(): void
	{
		$sText = ChangeSummary::Compose([
			['class' => 'Server', 'key' => 45, 'verb' => ChangeSummary::VERB_UPDATED, 'attcode' => 'name'],
			['class' => 'Server', 'key' => 45, 'verb' => ChangeSummary::VERB_UPDATED, 'attcode' => 'status'],
			['class' => 'UserRequest', 'key' => 5231, 'verb' => ChangeSummary::VERB_CREATED],
			['class' => 'Person', 'key' => 9, 'verb' => ChangeSummary::VERB_DELETED],
		]);

		$this->assertSame([
			['class' => 'Server', 'id' => 45, 'verb' => 'updated', 'attributes' => ['name', 'status']],
			['class' => 'UserRequest', 'id' => 5231, 'verb' => 'created', 'attributes' => []],
			['class' => 'Person', 'id' => 9, 'verb' => 'deleted', 'attributes' => []],
		], MyActivity::parseChanged($sText));
	}

	public function testItReadsBackWhatChangeSummaryTruncates(): void
	{
		$aOperations = [];
		for ($i = 1; $i <= ChangeSummary::MAX_OBJECTS + 7; $i++) {
			$aOperations[] = ['class' => 'Server', 'key' => $i, 'verb' => ChangeSummary::VERB_CREATED];
		}
		$aParsed = MyActivity::parseChanged(ChangeSummary::Compose($aOperations));

		$this->assertCount(ChangeSummary::MAX_OBJECTS, $aParsed, 'the counted remainder names nothing, and is not parsed as an object');
	}

	public function testItReadsBackWhatReadSummaryWrites(): void
	{
		$aRead = ['UserRequest' => []];
		for ($i = 1; $i <= ReadSummary::MAX_PER_CLASS + 3; $i++) {
			$aRead['UserRequest'][$i] = true;
		}
		$aRead['Person'] = [7 => true];

		$aParsed = MyActivity::parseRead(ReadSummary::ComposeFrom($aRead));

		$this->assertCount(ReadSummary::MAX_PER_CLASS + 1, $aParsed, 'fifty named, one other class, and "(+3 more)" is not an object');
		$this->assertSame(['class' => 'Person', 'id' => 7], end($aParsed));
	}

	public function testAnythingThatIsNotALineIsDropped(): void
	{
		$this->assertSame([], MyActivity::parseChanged("... and 4 more objects\nnot a line\n'; DROP --::1 updated"));
		$this->assertSame([], MyActivity::parseRead('+3 more in other classes; ; x'));
	}
}
