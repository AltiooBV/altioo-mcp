<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Resources\CurrentUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The one resource that says who the caller is must survive a contact it
 * cannot read.
 *
 * UserRights::GetContactFriendlyname() resolves through User::GetContactObject(),
 * which tries Person with $bMustBeFound false and then falls back to
 * MetaModel::GetObject('Contact', ...) with that flag left at its default. So a
 * contact that is deleted, archived, or simply outside the caller's silo raises
 * CoreException rather than answering null. Unguarded, that took the whole
 * resource down: the SDK's ReadResourceHandler catches the stray Throwable and
 * returns "Error while reading resource", which tells the caller nothing and
 * loses the user id and language that had resolved perfectly well.
 *
 * An API identity whose own contact sits outside its silo is exactly the caller
 * most likely to read this, so this is the common case, not the edge.
 *
 * A source scan rather than a behavioural test for the same reason as
 * AuthRefusalCoverageTest: CoreException and UserRights do not exist without
 * iTop, and a unit suite that boots neither can still hold the guard in place.
 */
class CurrentUserContactTest extends TestCase
{
	/**
	 * read() must not reach the throwing call itself.
	 *
	 * If it ever calls GetContactFriendlyname() directly again, the guard below
	 * can still be present and still be dead code.
	 */
	public function testReadDoesNotCallTheThrowingAccessorDirectly(): void
	{
		$this->assertStringNotContainsString(
			'UserRights::GetContactFriendlyname()',
			$this->methodBody('read'),
			'read() must resolve the contact through the guarded helper, otherwise a'
			.' contact outside the caller\'s silo fails the whole resource again.'
		);
	}

	/**
	 * The guard catches the type iTop actually raises.
	 *
	 * ArchivedObjectException extends CoreException, so the one catch covers
	 * both the missing contact and the archived one.
	 */
	public function testTheContactLookupCatchesCoreException(): void
	{
		$sBody = $this->methodBody('ContactFriendlyname');

		$this->assertStringContainsString(
			'UserRights::GetContactFriendlyname()',
			$sBody,
			'The helper is what calls the accessor.'
		);
		$this->assertStringContainsString(
			'catch(CoreException',
			str_replace(' ', '', $sBody),
			'MetaModel::GetObject() raises CoreException when the contact is not the'
			.' caller\'s to read; catching anything narrower lets it escape.'
		);
	}

	/**
	 * A degraded answer, not a silent one: the operator gets the reason.
	 */
	public function testTheRefusalIsLoggedAndTheResourceStillAnswers(): void
	{
		$sBody = $this->methodBody('ContactFriendlyname');

		$this->assertStringContainsString(
			'MCPHelper::LogError',
			$sBody,
			'A contact that cannot be resolved is a configuration fact the operator'
			.' has to be able to find in the log.'
		);
		$this->assertStringContainsString(
			'return null;',
			$sBody,
			'The name is what is lost; the identity in the rest of the payload is not.'
		);
		$this->assertStringNotContainsString(
			'throw',
			$sBody,
			'Rethrowing puts the caller back at "Error while reading resource".'
		);
	}

	/**
	 * The fields that never depended on the contact still answer.
	 *
	 * These are the point of the resource for a caller whose contact is
	 * unreachable, so they must not migrate behind the same guard.
	 *
	 * @dataProvider independentFieldProvider
	 */
	public function testTheIdentityFieldsAreStillReadDirectly(string $sCall): void
	{
		$this->assertStringContainsString(
			$sCall,
			$this->methodBody('read'),
			"read() must still answer {$sCall}: it does not go through the contact."
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function independentFieldProvider(): array
	{
		return [
			'user id'  => ['UserRights::GetUserId()'],
			'language' => ['UserRights::GetUserLanguage()'],
			'contact id' => ['UserRights::GetContactId()'],
		];
	}

	/**
	 * The body of one method, comments stripped - otherwise a call named only
	 * in a doc comment would satisfy the search.
	 */
	private function methodBody(string $sMethod): string
	{
		$oMethod = new ReflectionMethod(CurrentUser::class, $sMethod);
		$aLines = file((new ReflectionClass(CurrentUser::class))->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
