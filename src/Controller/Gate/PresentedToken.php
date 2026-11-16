<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use MetaModel;

/**
 * What the token this request presented says about itself.
 *
 * Plumbing only (doc/adr/0014): it reads a field. Whether that expiry is
 * acceptable is decided in the gate that asks, in its own source.
 *
 * @internal
 * @since 2.0.0
 */
final class PresentedToken
{
	/**
	 * The expiry of the token this request presented, as iTop stores it, or
	 * null when it has none - including a token class that carries no expiry
	 * at all, which the callers therefore cannot bound and refuse.
	 */
	public static function Expiration(): ?string
	{
		$oToken = TokenScopes::OfCurrentRequestObject();
		if ($oToken === null || !MetaModel::IsValidAttCode(get_class($oToken), 'expiration_date')) {
			return null;
		}

		$mExpiration = $oToken->Get('expiration_date');

		return is_string($mExpiration) && $mExpiration !== '' ? $mExpiration : null;
	}

	/**
	 * Whether the token class this request presented can carry an expiry date
	 * at all: a personal token can, an application token (UserToken) cannot.
	 * Only chooses the wording of a refusal.
	 */
	public static function CanExpire(): bool
	{
		$oToken = TokenScopes::OfCurrentRequestObject();

		return $oToken !== null && MetaModel::IsValidAttCode(get_class($oToken), 'expiration_date');
	}
}
