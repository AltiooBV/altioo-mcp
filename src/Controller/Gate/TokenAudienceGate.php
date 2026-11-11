<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller\Gate;

use Altioo\iTop\Extension\MCP\Exception\MCPAuthException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\OAuthResource;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use DBObject;
use ExecutionKPI;
use UserRights;

/**
 * A token a person granted for another URL of this endpoint is not used here.
 *
 * Runs while the token is still at hand: one a person granted for another URL
 * of this endpoint - another portal's - is not theirs to use here (doc/adr/0007).
 *
 * @internal
 * @since 2.0.0
 */
final class TokenAudienceGate implements iGate
{
	/**
	 * Refuses a token a person granted, through altioo-oauth-server, for
	 * another URL of this endpoint: with one OAuth resource per portal, a
	 * consent for one portal does not reach another (doc/adr/0007). A token
	 * no connection owns - one an administrator minted - has no such URL and
	 * is held by the other rules only; so is every token when that module is
	 * not installed.
	 *
	 * @throws MCPAuthException When the token was granted for another URL; the
	 *     401's challenge sends the client to consent for this one.
	 */
	#[\Override]
	public function Check(ExecutionKPI $oKPI): bool
	{
		$sAudienceClass = OAuthResource::TOKEN_AUDIENCE_CLASS;
		if (!class_exists($sAudienceClass)) {
			return true;
		}

		$oToken = TokenScopes::OfCurrentRequestObject();
		if (!$oToken instanceof DBObject || !is_a($oToken, 'PersonalToken')) {
			return true;
		}

		$oAudience = call_user_func([$sAudienceClass, 'Of'], (int)$oToken->GetKey());
		if (!is_object($oAudience) || !method_exists($oAudience, 'IsFor')) {
			return true;
		}

		$sCalled = OAuthResource::CalledEndpointUrl();
		if ($oAudience->IsFor($sCalled) === true) {
			return true;
		}

		ErrorReport::Log(sprintf(
			"Refused an MCP request: '%s' presented a token granted for the OAuth resource '%s', not for the URL called ('%s').",
			UserRights::GetUser(),
			property_exists($oAudience, 'sResourceKey') ? (string)$oAudience->sResourceKey : '?',
			$sCalled
		));

		throw new MCPAuthException(
			'This token was granted for another URL of this endpoint, such as another portal\'s. '
			.'Connect again from this URL to consent to it.',
			MCPResult::UNAUTHORIZED
		);

		return true;
	}
}
