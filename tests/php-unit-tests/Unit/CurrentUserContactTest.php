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
 * The caller's own contact is resolved as its own, not as somebody else's.
 *
 * UserRights::GetContactFriendlyname() reads the contact under the caller's
 * silo, and a contact outside it is not merely hidden: the Person lookup
 * answers null, the Contact fallback runs with $bMustBeFound at its default,
 * and the resulting CoreException took the whole resource down - the SDK's
 * ReadResourceHandler catches the stray throwable and returns "Error while
 * reading resource", losing the user id and language that had resolved fine.
 *
 * Catching that would have been the wrong fix. An identity is not something a
 * caller has to hold a right on to be told, and iTop settles the point itself:
 * FindUser() loads the user's own account with AllowAllData(). So the contact
 * is fetched the same way, and the assertions below are mostly about the one
 * thing that keeps this from being a contact reader - the id is taken from the
 * caller's own user record and can never come from the caller.
 *
 * A source scan rather than a behavioural test for the same reason as
 * AuthRefusalCoverageTest: MetaModel and UserRights do not exist without iTop,
 * and a unit suite that boots neither can still hold the invariant in place.
 */
class CurrentUserContactTest extends TestCase
{
	/**
	 * read() must not reach the silo-scoped accessor.
	 */
	public function testReadDoesNotUseTheSiloScopedAccessor(): void
	{
		$this->assertStringNotContainsString(
			'UserRights::GetContactFriendlyname()',
			$this->methodBody('read'),
			'GetContactFriendlyname() reads the contact under the caller\'s silo and'
			.' raises CoreException when it falls outside it, which fails the whole'
			.' resource. The caller\'s own contact is not read that way.'
		);
	}

	/**
	 * The lookup is the caller's own, and only ever the caller's own.
	 *
	 * This is the assertion that matters. Fetching with $bAllowAllData bypasses
	 * the silo, so what makes it safe is entirely that the id is read from the
	 * caller's own user record. An id arriving from anywhere else - a URI
	 * variable, an argument, a filter - turns this into a reader for every
	 * contact in the database.
	 */
	public function testTheContactIdComesFromTheCallersOwnUserRecord(): void
	{
		$sBody = str_replace(' ', '', $this->methodBody('ContactFriendlyname'));

		$this->assertStringContainsString(
			'UserRights::GetContactId()',
			$sBody,
			'The id must come from the caller\'s own user record.'
		);
		$this->assertStringContainsString(
			"MetaModel::GetObject('Contact',\$iContactId,false,true)",
			$sBody,
			'The contact is fetched by that id alone, with $bMustBeFound false so a'
			.' dangling contactid answers null, and $bAllowAllData true because the'
			.' caller\'s own identity is not something it holds a right on.'
		);
	}

	/**
	 * Nothing the caller sent can reach the lookup.
	 *
	 * The helper takes no parameters, so there is no route from a request into
	 * the id it resolves. Adding one is the single change that would make the
	 * AllowAllData fetch dangerous, so it fails here.
	 */
	public function testTheLookupTakesNothingFromTheCaller(): void
	{
		$oMethod = new ReflectionMethod(CurrentUser::class, 'ContactFriendlyname');

		$this->assertSame(
			0,
			$oMethod->getNumberOfParameters(),
			'ContactFriendlyname() must resolve the caller\'s own contact and nothing'
			.' else; a parameter is how a caller-supplied id would get in.'
		);
		$this->assertTrue(
			$oMethod->isPrivate(),
			'Nothing outside this resource has a reason to call an AllowAllData lookup.'
		);
	}

	/**
	 * A missing contact is an answer, not a failure.
	 */
	public function testAnUnresolvableContactDegradesToNull(): void
	{
		$sBody = $this->methodBody('ContactFriendlyname');

		$this->assertStringContainsString(
			'return null;',
			$sBody,
			'A user with no contact linked answers null.'
		);
		$this->assertStringNotContainsString(
			'throw',
			$sBody,
			'Throwing puts the caller back at "Error while reading resource" and'
			.' loses the identity the rest of the payload carries.'
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
