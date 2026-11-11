<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * What a tool says when it will not act on the class it was given.
 *
 * @internal
 * @since 2.0.0
 */
class ClassRefusal
{
	/**
	 * What a tool says when it will not act on the class it was given.
	 *
	 * One sentence for two cases, and deliberately: a class that does not
	 * exist and a class this account may not read answer identically, because
	 * telling them apart is a class enumerator for a customer datamodel whose
	 * class names are themselves information.
	 *
	 * What changed is the claim, not the discretion. "Unknown class 'X'." on
	 * its own asserts something false half the time - a reviewer reading it
	 * for URP_UserProfile concluded the class was absent from the datamodel and
	 * went looking for why, when the truth was that iTop reserves its rights
	 * model for administrators and no profile would ever have changed it. The
	 * answer now names both possibilities, which costs the oracle nothing: a
	 * caller learns the same thing in both cases, and learns which two things
	 * that is.
	 *
	 * Used by every site that refuses a class, so the two cases cannot drift
	 * into different wording by being written twice.
	 *
	 * A value that could not be a class name is not repeated back: see
	 * {@see self::LooksLikeClassName()}.
	 *
	 * @since 2.0.0
	 */
	public static function Unreadable(string $sClass): string
	{
		if (!self::LooksLikeClassName($sClass)) {
			return "That is not a class name: a class name is letters, digits and underscores, and does not start with a digit. "
				."core_class_list shows the classes you can reach.";
		}

		if (PortalMode::Current() !== null) {
			return "Unknown class '{$sClass}': the portal does not offer it. core_class_list shows the classes it offers you.";
		}

		return "Unknown class '{$sClass}': either this datamodel has no such class, or this account may not read it. "
			."iTop reserves its rights model, its credentials and its automation for administrators, so if you named one "
			."of those, no profile you can be granted changes this. core_class_list shows the classes you can reach.";
	}

	/**
	 * Whether a value has the shape of an iTop class name, so that a refusal
	 * may quote it.
	 *
	 * Refusals quote the class they were given, because a client that typed
	 * `UserReqest` fixes it from the quote. A value that is no class name at
	 * all - `UserRequest' OR 1=1 --`, markup, a paragraph - gains nothing from
	 * being repeated, and repeating it puts the caller's text, verbatim, into
	 * the audit row's message and whatever shows it next. iTop escapes that
	 * row in the console; this keeps the text out rather than relying on every
	 * later reader of it to escape.
	 *
	 * The shape is the PHP class name a compiled iTop class becomes, in ASCII:
	 * a non-ASCII class name, legal in PHP and unseen in any datamodel, is
	 * refused without the quote and is still refused. It is checked on the
	 * value alone, never against the datamodel, so it tells a caller nothing
	 * about which classes exist. `\z` rather than `$`, which would let a
	 * trailing newline through.
	 *
	 * @since 1.2.0
	 */
	public static function LooksLikeClassName(string $sValue): bool
	{
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $sValue) === 1;
	}
}
