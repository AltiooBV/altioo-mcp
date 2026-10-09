<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use MetaModel;
use Throwable;

/**
 * A secret something will read back and use, refused on the way in.
 *
 * iTop keeps two kinds of secret. AttributeOneWayPassword stores a salted
 * hash, so its value can only be compared against: that is a credential into
 * iTop, and a class holding one is refused whole by AccessGrants. The
 * recoverable types - AttributePassword, AttributeEncryptedString and
 * AttributeEncryptedPassword, which extends it - are stored so that they can
 * be read back, and the only reason to read a secret back is to log in to
 * something else with it: a mail server, a remote API, a device.
 *
 * That is what makes writing one worth refusing. Replacing a mailbox's
 * password, alongside its login and server, points the instance's mail
 * collection at a server the caller controls; replacing an API secret does the
 * same for whatever calls out with it. AccessGrants refuses the classes iTop
 * itself ships for this - the OAuth clients, the remote connections, the
 * webhook action - by name, and a class an extension adds has no name to
 * list. The type is the one thing every such class has in common, so the rule
 * is on the attribute rather than on the class: the rest of a mailbox or a
 * device stays writable, and only the secret on it is not.
 *
 * It refuses more than the dangerous case, and knowingly: a password kept on a
 * CI for a person to read and nothing to use is refused the same way, because
 * nothing in the datamodel tells the two apart. mcp_allow_privilege_escalation
 * lifts it, as it lifts the class barriers on the outbound machinery, since
 * the decision it stands for is the same one - this endpoint may arrange for
 * the instance to authenticate outward as somebody the caller chose.
 *
 * @api
 * @since 1.2.0
 */
final class SecretPolicy
{
	/**
	 * The recoverable secret types, matched by descent.
	 *
	 * AttributeEncryptedPassword is not listed: it extends
	 * AttributeEncryptedString, and the module that declares it is optional.
	 */
	private const RECOVERABLE_TYPES = ['AttributePassword', 'AttributeEncryptedString'];

	public const SECRET_REFUSAL = 'The value for \'%s\' is a secret this instance keeps in order to read it back and log in to something else with it - a mail server, a remote API, a device. Writing one is refused unless mcp_allow_privilege_escalation is on: replacing it can point that login at a system the caller chose, and nothing in the datamodel says which secrets are used that way. Set it in the iTop console. The other attributes of this object can still be written here.';

	/**
	 * Why this value may not be written, or null when it may.
	 *
	 * The value is not looked at: clearing a secret is as much a change to
	 * what the instance logs in with as replacing it.
	 */
	public static function RefusalFor(string $sClass, string $sAttCode, mixed $value): ?string
	{
		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		} catch (Throwable $e) {
			// An unknown attribute is refused by the write itself, in its own words.
			return null;
		}

		return self::RefusalGiven($oAttDef, $sAttCode, MCPHelper::AllowsPrivilegeEscalation());
	}

	/**
	 * The same decision with the instance's answer handed in rather than read,
	 * so that the rule can be tested without an iTop or a configuration.
	 */
	public static function RefusalGiven(object $oAttDef, string $sAttCode, bool $bEscalationAllowed): ?string
	{
		if ($bEscalationAllowed || !self::IsRecoverableSecret($oAttDef)) {
			return null;
		}

		return sprintf(self::SECRET_REFUSAL, $sAttCode);
	}

	/**
	 * Whether $oAttDef stores a secret so that it can be read back.
	 */
	public static function IsRecoverableSecret(object $oAttDef): bool
	{
		foreach (self::RECOVERABLE_TYPES as $sType) {
			if (is_a($oAttDef, $sType)) {
				return true;
			}
		}

		return false;
	}
}
