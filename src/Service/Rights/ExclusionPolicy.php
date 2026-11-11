<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;

use AttributeExternalField;
use AttributeExternalKey;
use AttributeLinkedSet;
use AttributeLinkedSetIndirect;
use MetaModel;
use Throwable;
use utils;

/**
 * What an operator has taken out of this channel (doc/adr/0012).
 *
 * `mcp_excluded_classes` and `mcp_excluded_attributes`, for everyone and in both
 * modes. An excluded class or attribute is **absent**: every site that asks
 * answers as it would for a name the datamodel does not have, because "excluded"
 * as a different answer would say which classes and attributes exist and are
 * worth hiding.
 *
 * This narrows the channel, not the account. Nothing here is an access control:
 * the same account reads the same data in the console.
 *
 * It answers the question - is this class, this attribute excluded, and is the caller exempt - and
 * reads its settings through ExclusionSettings. What the answer does to a search is
 * ExclusionSearches.
 *
 * @api
 * @since 1.3.0
 */
final class ExclusionPolicy
{
	/** @var array{key: string, classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string, active: bool, exempt: bool, datamodel: ?string}|null */
	private static ?array $aCache = null;

	/** @var array<string, bool> Answers of {@see ExcludesAttribute()} and {@see ExcludesClass()}, for the settings in $aCache. */
	private static array $aMemo = [];

	/**
	 * Whether this call runs as an account the operator exempted (doc/adr/0013): it holds one of
	 * `mcp_exclusion_exempt_profiles`, and the call is not in portal mode. Nothing else decides it - not a
	 * token's scope, not the name the client declared, not an OAuth client - since each of those is the
	 * caller's to choose. False whenever the settings are malformed.
	 */
	public static function IsExempt(): bool
	{
		return self::state()['exempt'];
	}

	/** Whether the settings hold something that cannot be honoured: the endpoint then serves nothing. */
	public static function IsMalformed(): bool
	{
		return self::state()['problem'] !== null;
	}

	/** Why the settings cannot be honoured, or null. For the log; never for a caller. */
	public static function Problem(): ?string
	{
		return self::state()['problem'];
	}

	/** Whether anything is excluded. */
	public static function IsActive(): bool
	{
		$aState = self::state();

		return $aState['classes'] !== [] || $aState['attributes'] !== [];
	}

	/**
	 * Whether this channel serves the class at all: the datamodel has it and it
	 * is not excluded. What every class gate asks, so that an excluded class
	 * and an unknown one take the same path and give the same answer.
	 */
	public static function Serves(string $sClass): bool
	{
		return MetaModel::IsValidClass($sClass) && !self::ExcludesClass($sClass);
	}

	/**
	 * Whether the class, or one it descends from, is excluded.
	 */
	public static function ExcludesClass(string $sClass): bool
	{
		$aState = self::state();
		if ($aState['classes'] === []) {
			return false;
		}

		return self::$aMemo['c|'.$sClass] ??= (static function () use ($sClass, $aState): bool {
			foreach (self::lineage($sClass) as $sName) {
				if (isset($aState['classes'][strtolower($sName)])) {
					return true;
				}
			}

			return false;
		})();
	}

	/**
	 * Whether the attribute is excluded on this class: listed on it or on a
	 * class it descends from, or derived from something that is - an external
	 * field resolving to an excluded attribute or through an excluded key, a key
	 * or a link set pointing at an excluded class.
	 */
	public static function ExcludesAttribute(string $sClass, string $sAttCode): bool
	{
		if (!self::IsActive()) {
			return false;
		}

		// A row of fifty objects asks once per attribute each.
		$sKey = 'a|'.$sClass.'|'.$sAttCode;

		return self::$aMemo[$sKey] ??= self::excludes($sClass, $sAttCode, 0);
	}

	/**
	 * The attribute codes of the class that are excluded, for the sites that
	 * list attributes rather than ask about one.
	 *
	 * @return array<int, string>
	 */
	public static function ExcludedAttributes(string $sClass): array
	{
		if (!self::IsActive()) {
			return [];
		}

		$aCodes = [];
		try {
			/** @psalm-suppress InvalidCast */
			foreach (array_keys(MetaModel::ListAttributeDefs($sClass)) as $sAttCode) {
				if (self::ExcludesAttribute($sClass, (string)$sAttCode)) {
					$aCodes[] = (string)$sAttCode;
				}
			}
		} catch (Throwable $e) {
			return [];
		}

		return $aCodes;
	}

	/**
	 * Why creating a class is not possible through this channel: a field iTop
	 * requires is excluded, so no create could ever pass. Said without naming
	 * the field, and null when nothing stands in the way.
	 */
	public static function RefusalToCreate(string $sClass): ?string
	{
		if (!self::IsActive()) {
			return null;
		}

		try {
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
				if ($oAttDef->IsWritable() && $oAttDef->IsNullAllowed() === false
					&& in_array($oAttDef->GetDefaultValue(), ['', 0, '0', null], true) && self::ExcludesAttribute($sClass, (string)$sAttCode)) {
					return sprintf(
						"Creating %s is not possible through this channel: a field iTop requires is excluded by '%s'. Create it in the iTop console.",
						$sClass,
						ExclusionSettings::SETTING_ATTRIBUTES
					);
				}
			}
		} catch (Throwable $e) {
			return null;
		}

		return null;
	}

	/**
	 * Validation messages minus those that name an excluded attribute, by its
	 * code or its label on the class.
	 *
	 * @param array<int|string, string> $aIssues
	 *
	 * @return array<int, string>
	 */
	public static function WithoutExcluded(string $sClass, array $aIssues): array
	{
		$aCodes = self::ExcludedAttributes($sClass);
		if ($aCodes === []) {
			return array_values($aIssues);
		}

		$aNames = [];
		foreach ($aCodes as $sCode) {
			$aNames[] = $sCode;
			try {
				$aNames[] = (string)MetaModel::GetLabel($sClass, $sCode);
			} catch (Throwable $e) {
				// The code is still matched.
			}
		}
		$aNames = array_filter($aNames, static fn (string $s): bool => $s !== '');

		return array_values(array_filter($aIssues, static function (string $sIssue) use ($aNames): bool {
			foreach ($aNames as $sName) {
				if (stripos($sIssue, $sName) !== false) {
					return false;
				}
			}

			return true;
		}));
	}

	/**
	 * Forgets what was read, for a suite that changes the configuration between cases.
	 *
	 * Public for the tests: no promise to a tool pack, whatever the class's own tag says. A request reads its settings
	 * once, so nothing outside a test has a reason to call it.
	 *
	 * @internal
	 */
	public static function Forget(): void
	{
		self::$aCache = null;
		self::$aMemo = [];
	}

	private static function excludes(string $sClass, string $sAttCode, int $iDepth): bool
	{
		$aState = self::state();
		if ($aState['classes'] === [] && $aState['attributes'] === []) {
			return false;
		}
		if ($iDepth > 8) {
			return true; // a loop in the datamodel is not a licence to serve it
		}

		$sCode = strtolower($sAttCode);
		foreach (self::lineage($sClass) as $sName) {
			if (isset($aState['attributes'][strtolower($sName)][$sCode])) {
				return true;
			}
		}

		// Listed on a subclass: a query on this class reaches the subclass's rows, and an attribute the two
		// share is the same column, so it is excluded here too. Otherwise a filter, a sort or a search
		// on UserRequest.caller_id is one on Ticket.caller_id, which would not be.
		foreach ($aState['attributes'] as $sListed => $aCodes) {
			if (isset($aCodes[$sCode])
				&& in_array(strtolower($sClass), array_map('strtolower', self::lineage(ExclusionSettings::canonicalClass((string)$sListed) ?? (string)$sListed)), true)) {
				return true;
			}
		}

		try {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				return false;
			}
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

			if ($oAttDef instanceof AttributeExternalField) {
				return self::excludes($sClass, $oAttDef->GetKeyAttCode(), $iDepth + 1)
					|| self::ExcludesClass($oAttDef->GetTargetClass())
					|| self::excludes($oAttDef->GetTargetClass(), $oAttDef->GetExtAttCode(), $iDepth + 1);
			}
			if ($oAttDef instanceof AttributeExternalKey) {
				return self::ExcludesClass($oAttDef->GetTargetClass());
			}
			if ($oAttDef instanceof AttributeLinkedSet) {
				$sLinked = $oAttDef->GetLinkedClass();
				if (self::ExcludesClass($sLinked)) {
					return true;
				}
				/** @psalm-suppress TypeDoesNotContainType */
				if ($oAttDef instanceof AttributeLinkedSetIndirect) {
					$oToRemote = MetaModel::GetAttributeDef($sLinked, $oAttDef->GetExtKeyToRemote());

					return $oToRemote instanceof AttributeExternalKey && self::ExcludesClass($oToRemote->GetTargetClass());
				}

				return false;
			}
		} catch (Throwable $e) {
			// A datamodel that cannot be walked is not one to serve.
			return true;
		}

		return false;
	}

	/**
	 * The class and every class above it, by the datamodel.
	 *
	 * @return array<int, string>
	 */
	public static function lineage(string $sClass): array
	{
		$aLineage = [$sClass];
		try {
			if (class_exists('MetaModel') && MetaModel::IsValidClass($sClass)) {
				foreach (MetaModel::EnumParentClasses($sClass, ENUM_PARENT_CLASSES_ALL) as $sParent) {
					$aLineage[] = (string)$sParent;
				}
			}
		} catch (Throwable $e) {
			// Just the name, then.
		}

		return $aLineage;
	}

	/**
	 * @return array{key: string, classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string, active: bool, exempt: bool, datamodel: ?string}
	 */
	public static function state(): array
	{
		$oConfig = utils::GetConfig();
		$mClasses = $oConfig->GetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, []);
		$mAttributes = $oConfig->GetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, []);
		$mExempt = $oConfig->GetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		// Who is asking is part of the answer: the real user (the account that authenticated, not the one it
		// acts as), and whether this is portal mode, which is never exempt.
		$sKey = md5(serialize([$mClasses, $mAttributes, $mExempt, self::asking()]));

		if (self::$aCache !== null && self::$aCache['key'] === $sKey) {
			return self::$aCache;
		}
		self::$aMemo = [];

		$aParsed = ExclusionSettings::Parse($mClasses, $mAttributes);
		$sProblem = $aParsed['problem'] ?? ExclusionSettings::datamodelProblem($aParsed['classes'], $aParsed['attributes']);

		// A profile that does not exist, or a setting that is no list, is a problem for everyone and not an
		// exemption for anyone: a typo must never exempt everybody.
		$aExempt = ExclusionSettings::ParseExempt($mExempt);
		$sProblem ??= $aExempt['problem'] ?? ExclusionSettings::exemptProfilesProblem($aExempt['profiles']);

		if ($sProblem !== null) {
			ErrorReport::Log("Itop configuration parameter for exclusions: {$sProblem}. Nothing is served to anyone until it is corrected.");

			return self::$aCache = ['classes' => [], 'attributes' => [], 'problem' => $sProblem, 'key' => $sKey, 'active' => false, 'exempt' => false, 'datamodel' => null];
		}

		$bExempt = $aExempt['profiles'] !== [] && ExclusionSettings::holdsOneOf($aExempt['profiles']);
		if ($bExempt) {
			// The whole exemption: what is excluded is served, every class and every attribute.
			$aParsed = ['classes' => [], 'attributes' => [], 'problem' => null];
		}

		return self::$aCache = $aParsed + [
			'key'       => $sKey,
			'active'    => $aParsed['classes'] !== [] || $aParsed['attributes'] !== [],
			'exempt'    => $bExempt,
			'datamodel' => null,
		];
	}

	/** Who is asking, as the cache key sees it: the real user's key, and the portal when there is one. */
	private static function asking(): string
	{
		try {
			$sPortal = class_exists(PortalMode::class) ? (string)PortalMode::Current() : '';
			// As the audit row and the change log name the actor: the real user under impersonation, the user otherwise.
			$iUser = class_exists('CMDBChange') ? (int)\CMDBChange::GetCurrentUserId() : 0;
		} catch (Throwable $e) {
			return 'unknown';
		}

		return $iUser.'|'.$sPortal;
	}

}
