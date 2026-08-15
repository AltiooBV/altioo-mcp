<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Combodo\iTop\AuthentToken\Hook\TokenLoginExtension;
use MetaModel;
use Throwable;

/**
 * The scopes of the token this request authenticated with.
 *
 * iTop keeps a token's scopes on the token object and checks them during
 * login, by matching them against the ContextTag stack - it never tells the
 * endpoint which of them matched. Reading them back is therefore a second
 * look at the same credential, done once the login has already succeeded.
 */
final class TokenScopes
{
	/** Where authent-token reads its credential from. */
	private const AUTH_TOKEN_KEY = 'HTTP_AUTH_TOKEN';

	/** The classes that carry a scope field. */
	private const TOKEN_CLASSES = ['PersonalToken', 'UserToken'];

	/**
	 * Every MCP scope this instance declares, to be pushed as context tags
	 * before login.
	 *
	 * iTop honours a scope only when a tag of the same name is on the stack,
	 * so a scope nobody pushes is a token that cannot log in. Reading the
	 * declared enumeration rather than a hardcoded list is what lets a pack
	 * add MCP-tickets to the token classes in its own datamodel and have it
	 * work, without this module knowing the name.
	 *
	 * @return array<int, string>
	 */
	public static function DeclaredContextTags(): array
	{
		$aTags = [MCPContext::SCOPE_MCP];

		foreach (self::TOKEN_CLASSES as $sClass) {
			try {
				if (!MetaModel::IsValidClass($sClass) || !MetaModel::IsValidAttCode($sClass, 'scope')) {
					continue;
				}

				$aValues = MetaModel::GetAttributeDef($sClass, 'scope')->GetAllowedValues();
				foreach (array_keys(is_array($aValues) ? $aValues : []) as $sValue) {
					if (is_string($sValue) && str_starts_with($sValue, MCPContext::SCOPE_MCP)) {
						$aTags[] = $sValue;
					}
				}
			} catch (Throwable $e) {
				MCPHelper::LogError('Could not read the declared token scopes of '.$sClass.': '.$e->getMessage());
			}
		}

		return array_values(array_unique($aTags));
	}

	/** Whether this request authenticated with a token at all. */
	public static function RequestCarriesAToken(): bool
	{
		$sToken = $_SERVER[self::AUTH_TOKEN_KEY] ?? '';

		return is_string($sToken) && $sToken !== '';
	}

	/**
	 * The scopes held by the presented token.
	 *
	 * Called after a successful login, so decrypting the same credential again
	 * should succeed. When it does not, the scopes are unknown - and unknown
	 * scopes are answered with the narrowest policy rather than the widest,
	 * because the failure mode of the other choice is a token minted
	 * read-only being served as if it were not.
	 *
	 * @return array<int, string>|null Null when the scopes could not be established.
	 */
	public static function OfCurrentRequest(): ?array
	{
		$sToken = $_SERVER[self::AUTH_TOKEN_KEY] ?? '';
		if (!is_string($sToken) || $sToken === '') {
			return null;
		}

		if (!class_exists(TokenLoginExtension::class)) {
			MCPHelper::LogError('authent-token is not installed, so token scopes cannot be read.');

			return null;
		}

		try {
			$oToken = TokenLoginExtension::GetToken($sToken);
			$oScope = $oToken->Get('scope');

			$aScopes = method_exists($oScope, 'GetValues') ? $oScope->GetValues() : [];

			return array_values(array_filter($aScopes, 'is_string'));
		} catch (Throwable $e) {
			MCPHelper::LogError('The scopes of the presented token could not be read: '.$e->getMessage());

			return null;
		}
	}
}
