<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\MCPHttp;
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
 *
 * @since 1.0.0
 */
final class TokenScopes
{
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

				foreach (self::PossibleScopeValues(MetaModel::GetAttributeDef($sClass, 'scope')) as $sValue) {
					if (str_starts_with($sValue, MCPContext::SCOPE_MCP)) {
						$aTags[] = $sValue;
					}
				}
			} catch (Throwable $e) {
				MCPHelper::LogError('Could not read the declared token scopes of '.$sClass.': '.$e->getMessage());
			}
		}

		return array_values(array_unique($aTags));
	}

	/**
	 * The values a scope attribute may hold, whichever way this iTop exposes
	 * them.
	 *
	 * scope is an AttributeEnumSet, and GetAllowedValues() - the accessor a
	 * plain enum answers - returns null for it. Asking only that one is what
	 * reduced this list to the base scope on every instance, silently: the
	 * null became an empty array one line later, no tag was pushed for any
	 * MCP-read, MCP-write or MCP-toolset-* scope, and every token holding one
	 * was refused by iTop with "Scope not authorized" - which reaches the
	 * caller as "Invalid login", and reaches log/error.log as nothing at all.
	 *
	 * Both accessors are asked, and both array shapes accepted: a code => label
	 * map, whose keys are the codes, or a plain list of codes. This module
	 * supports two iTop branches and the shape is not part of any contract
	 * between them - and a label is never mistaken for a code, because the keys
	 * win wherever there are string keys to win with.
	 *
	 * @return array<int, string>
	 */
	private static function PossibleScopeValues(object $oAttDef): array
	{
		$aValues = [];

		foreach (['GetPossibleValues', 'GetAllowedValues'] as $sMethod) {
			if (!method_exists($oAttDef, $sMethod)) {
				continue;
			}

			$mDeclared = $oAttDef->$sMethod();
			if (!is_array($mDeclared)) {
				continue;
			}

			$aCodes = array_filter(array_keys($mDeclared), 'is_string');
			$aValues = array_merge($aValues, $aCodes !== [] ? $aCodes : array_filter($mDeclared, 'is_string'));
		}

		return array_values($aValues);
	}

	/**
	 * Whether $sClass is a class whose rows can grade this endpoint.
	 *
	 * The token classes are named in TOKEN_CLASSES because the enumeration has
	 * to be read before login, from classes that are known then. This answers
	 * the question the other way round - given a class, does it carry a scope
	 * attribute declaring scopes of ours - so that a class this module has
	 * never heard of is recognised the day an iTop version or a pack adds it.
	 *
	 * AccessGrants is the caller: a class that can grade this endpoint is a
	 * class this endpoint must not write, whatever its name turns out to be.
	 *
	 * False whenever it cannot be established - no MetaModel, an unknown
	 * class, an attribute definition that raises. The name floor in
	 * AccessGrants is what covers today's classes; this only ever adds to it,
	 * so failing quietly here narrows nothing that was already refused.
	 *
	 * @since 1.0.0
	 */
	public static function GradesThisEndpoint(string $sClass): bool
	{
		if (!class_exists('MetaModel')) {
			return false;
		}

		try {
			if (!MetaModel::IsValidClass($sClass) || !MetaModel::IsValidAttCode($sClass, 'scope')) {
				return false;
			}

			foreach (self::PossibleScopeValues(MetaModel::GetAttributeDef($sClass, 'scope')) as $sValue) {
				if (str_starts_with($sValue, MCPContext::SCOPE_MCP)) {
					return true;
				}
			}
		} catch (Throwable $e) {
			MCPHelper::LogError('Could not read the scopes declared by '.$sClass.': '.$e->getMessage());
		}

		return false;
	}

	/** Whether this request authenticated with a token at all. */
	public static function RequestCarriesAToken(): bool
	{
		return MCPHttp::CurrentAuthToken() !== null;
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
		$sToken = MCPHttp::CurrentAuthToken();
		if ($sToken === null) {
			return null;
		}

		if (!class_exists(TokenLoginExtension::class)) {
			MCPHelper::LogError('authent-token is not installed, so token scopes cannot be read.');

			return null;
		}

		try {
			$oToken = self::OfCurrentRequestObject();
			if ($oToken === null) {
				return null;
			}
			$oScope = $oToken->Get('scope');

			$aScopes = method_exists($oScope, 'GetValues') ? $oScope->GetValues() : [];

			return array_values(array_filter($aScopes, 'is_string'));
		} catch (Throwable $e) {
			// The class, never the message. GetToken() is handed the raw
			// credential, and an exception raised while decrypting or looking
			// it up is free to quote what it was given - which would put the
			// token itself in log/error.log, in clear, for as long as that file
			// is kept.
			MCPHelper::LogError('The scopes of the presented token could not be read ('.get_class($e).').');

			return null;
		}
	}

	/**
	 * The token object this request authenticated with, when it used one.
	 *
	 * Separate from {@see OfCurrentRequest()} because the audit row needs the
	 * credential itself and not its scopes: which token, and - since
	 * `PersonalToken` and `UserToken` are siblings under `cmdbAbstractObject`
	 * rather than two halves of one class - which of the two it is.
	 *
	 * **Deliberately not memoised.** Resolving it twice in a request costs a
	 * second decrypt of a credential already in hand; a static holding a token
	 * object costs correctness the first time this class is used somewhere
	 * that serves more than one request per process. This class has kept no
	 * request state so far and this is not the change that should give it
	 * some.
	 *
	 * The caller gets the object or null, never an exception: this is reached
	 * on the way to writing an audit row, where a failure must cost a field
	 * rather than the row.
	 *
	 * @return object|null A `PersonalToken` or a `UserToken`.
	 *
	 * @since 1.0.0
	 */
	public static function OfCurrentRequestObject(): ?object
	{
		$sToken = MCPHttp::CurrentAuthToken();
		if ($sToken === null) {
			return null;
		}

		if (!class_exists(TokenLoginExtension::class)) {
			return null;
		}

		try {
			return TokenLoginExtension::GetToken($sToken);
		} catch (Throwable $e) {
			// The class, never the message - GetToken() is handed the raw
			// credential and an exception is free to quote it.
			MCPHelper::LogError('The presented token could not be resolved ('.get_class($e).').');

			return null;
		}
	}
}
