<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * No attribute whose code names a stored secret comes back unmasked.
 *
 * SECURITY.md is honest that the masking of outbound secrets is a named list,
 * not a derived property: "a class here is readable because nobody added it to
 * the blocklist, not because it was evaluated and judged safe." The OAuth half
 * of that list was found by a reviewer; RemoteiTopConnectionToken.token was
 * found by a red-team read after it had shipped readable. This test is the
 * mechanism that turns the next such class into a failing test instead of a
 * finding: it walks every class the installed datamodel declares and refuses
 * any plain string or text attribute whose exact code names a secret but which
 * {@see ObjectSerializer::IsSensitive()} would still report in clear.
 *
 * Exact codes, not substrings, for the reason OUTBOUND_SECRET_ATTRIBUTES is:
 * token_type ("Bearer") and refresh_token_expiration (a date) are the answer,
 * not the secret, and a substring sweep would mask them. A new genuinely
 * non-secret attribute that happens to be named exactly one of these is the
 * one case a human must rule on - which is what a failure here asks for, and
 * why the list of secret codes lives in the test beside the reason.
 */
class UnmaskedSecretSweepTest extends ItopDataTestCaseAlias
{
	/**
	 * Attribute codes that, spelled exactly, name a credential at rest. The
	 * same spellings {@see \Altioo\iTop\Extension\MCP\Helper\AccessGrants}
	 * masks on the OAuth and webhook-connection hierarchies, kept here as the
	 * wider net: any class at all carrying one of these in a readable type is
	 * what this test is looking for.
	 */
	private const SECRET_CODES = [
		'client_secret',
		'access_token',
		'refresh_token',
		'token',
		'secret',
		'api_key',
		'apikey',
		'private_key',
	];

	/**
	 * `<owning class>::<code>` the net catches that a human has ruled are not
	 * credentials at rest, each with the reason, because the bare words `token`
	 * and `secret` are what the real cases spell too (OAuthClient::token,
	 * RemoteiTopConnectionToken::token) and cannot be dropped from the net. A
	 * new entry here is a decision, not a convenience: if you cannot write the
	 * reason, the attribute is not on this list.
	 */
	private const NOT_A_CREDENTIAL = [
		// A per-image display nonce iTop embeds in rendered <img data-img-secret>
		// tags, checked when the image bytes are served. It travels in the HTML
		// of any page its viewer can already read; it is not a stored credential.
		'InlineImage::secret',
		// The pessimistic edit-lock nonce (priv_ownership_token), identifying who
		// holds the lock on an object. A concurrency token, not authentication:
		// iTop lists it in its own standard search and detail views.
		'iTopOwnershipToken::token',
	];

	public function testNoClassReportsAStoredSecretInClear(): void
	{
		if (!class_exists('MetaModel')) {
			$this->markTestSkipped('needs a loaded datamodel');
		}

		$aUnmasked = [];
		foreach (MetaModel::GetClasses() as $sClass) {
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
				if (!in_array(strtolower((string) $sAttCode), self::SECRET_CODES, true)) {
					continue;
				}
				if (ObjectSerializer::IsSensitive($oAttDef, $sClass)) {
					continue;
				}
				// Report the root that declares it, so subclasses collapse to
				// one line rather than one per leaf.
				$sOwner = MetaModel::GetAttributeOrigin($sClass, $sAttCode);
				if (in_array($sOwner.'::'.$sAttCode, self::NOT_A_CREDENTIAL, true)) {
					continue;
				}
				$aUnmasked[$sOwner.'::'.$sAttCode.' ('.get_class($oAttDef).')'] = true;
			}
		}
		$aUnmasked = array_keys($aUnmasked);
		sort($aUnmasked);

		$this->assertSame([], $aUnmasked, sprintf(
			"a secret-named attribute is read back in clear - add its class hierarchy to "
			."AccessGrants::OUTBOUND_CREDENTIAL_ROOTS, or, if it is genuinely not a secret, "
			."allowlist it here with the reason: %s",
			implode(', ', $aUnmasked)
		));
	}
}
