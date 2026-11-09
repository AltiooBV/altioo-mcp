<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

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
 * @api
 * @since 1.3.0
 */
final class ExclusionPolicy
{
	public const SETTING_CLASSES = 'mcp_excluded_classes';

	public const SETTING_ATTRIBUTES = 'mcp_excluded_attributes';

	/** Never excludable: what an object is, not what it holds. */
	private const IDENTITY_CODES = ['id', 'finalclass', 'friendlyname'];

	/** @var array{key: string, classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string, active: bool, datamodel: ?string}|null */
	private static ?array $aCache = null;

	/** @var array<string, bool> Answers of {@see ExcludesAttribute()} and {@see ExcludesClass()}, for the settings in $aCache. */
	private static array $aMemo = [];

	/**
	 * The settings as the datamodel-free half of the rule reads them.
	 *
	 * Public for the unit suite, which has no configuration to read from.
	 *
	 * @return array{classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string}
	 */
	public static function Parse(mixed $mClasses, mixed $mAttributes): array
	{
		$aClasses = [];
		$aAttributes = [];

		if (!is_array($mClasses)) {
			return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class names, not %s", self::SETTING_CLASSES, gettype($mClasses))];
		}
		foreach ($mClasses as $mClass) {
			if (!is_string($mClass) || !MCPHelper::LooksLikeClassName(trim($mClass))) {
				return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class names, and holds %s", self::SETTING_CLASSES, is_scalar($mClass) ? var_export($mClass, true) : gettype($mClass))];
			}
			$aClasses[strtolower(trim($mClass))] = trim($mClass);
		}

		if (!is_array($mAttributes)) {
			return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class => array of attribute codes, not %s", self::SETTING_ATTRIBUTES, gettype($mAttributes))];
		}
		foreach ($mAttributes as $mClass => $mCodes) {
			if (!is_string($mClass) || !MCPHelper::LooksLikeClassName(trim($mClass)) || !is_array($mCodes) || $mCodes === []) {
				return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should be an array of class => array of attribute codes, and holds a bad entry for %s", self::SETTING_ATTRIBUTES, var_export($mClass, true))];
			}
			foreach ($mCodes as $mCode) {
				if (!is_string($mCode) || !MCPHelper::LooksLikeClassName(trim($mCode))) {
					return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' should list attribute codes for %s, and holds %s", self::SETTING_ATTRIBUTES, trim($mClass), is_scalar($mCode) ? var_export($mCode, true) : gettype($mCode))];
				}
				if (in_array(strtolower(trim($mCode)), self::IDENTITY_CODES, true)) {
					return ['classes' => [], 'attributes' => [], 'problem' => sprintf("'%s' cannot exclude '%s' of %s: it is what the object is, not what it holds", self::SETTING_ATTRIBUTES, trim($mCode), trim($mClass))];
				}
				$aAttributes[strtolower(trim($mClass))][strtolower(trim($mCode))] = trim($mCode);
			}
		}

		return ['classes' => $aClasses, 'attributes' => $aAttributes, 'problem' => null];
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
						self::SETTING_ATTRIBUTES
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
	 * The classes below $sClass that are excluded, with everything under them,
	 * for the searches that return rows of a parent class: a search on Contact
	 * returns every Person, and an excluded Person must not be among them -
	 * nor be counted.
	 *
	 * @return array<int, string>
	 */
	public static function ExcludedBelow(string $sClass): array
	{
		$aState = self::state();
		if ($aState['classes'] === []) {
			return [];
		}

		$aBelow = [];
		try {
			foreach ($aState['classes'] as $sExcluded) {
				$sExcluded = self::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				if (strcasecmp($sExcluded, $sClass) === 0 || !MetaModel::IsValidClass($sExcluded)) {
					continue;
				}
				if (!in_array(strtolower($sClass), array_map('strtolower', self::lineage($sExcluded)), true)) {
					continue;
				}
				$aBelow[] = $sExcluded;
				foreach (MetaModel::EnumChildClasses($sExcluded, ENUM_CHILD_CLASSES_ALL) as $sChild) {
					$aBelow[] = (string)$sChild;
				}
			}
		} catch (Throwable $e) {
			return [];
		}

		return array_values(array_unique($aBelow));
	}

	/**
	 * iTop's full-text condition, over every attribute it would search but the
	 * ones the operator excluded: a word found only in an excluded attribute
	 * matches nothing, as it would if the attribute did not exist.
	 */
	public static function AddFullText(\DBObjectSearch $oSearch, string $sNeedle): void
	{
		$sClass = $oSearch->GetClass();
		$aCodes = [];
		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if (!$oAttDef->IsScalar() || $oAttDef->IsExternalKey() || !$oAttDef->IsSearchable()
				|| self::ExcludesAttribute($sClass, (string)$sAttCode)) {
				continue;
			}
			$aCodes[] = (string)$sAttCode;
		}

		$oSearch->AddCondition_FullTextOnAttributes($aCodes, $sNeedle);
	}

	/**
	 * Whether the object is a file filed under an object of an excluded class:
	 * an Attachment names its host by class and id, not by a key that
	 * {@see ExcludesAttribute()} could follow.
	 */
	public static function IsFiledUnderExcluded(\DBObject $oObject): bool
	{
		if (!self::IsActive() || !is_a($oObject, 'Attachment')) {
			return false;
		}

		try {
			return self::ExcludesClass((string)$oObject->Get('item_class'));
		} catch (Throwable $e) {
			return true;
		}
	}

	/**
	 * The search without the rows of an excluded subclass.
	 *
	 * @psalm-suppress InvalidReturnType
	 * @psalm-suppress InvalidReturnStatement
	 */
	public static function Narrowed(\DBSearch|\DBObjectSearch $oSearch, string $sClass): \DBSearch|\DBObjectSearch
	{
		// A search over attachments leaves out those filed under an excluded class.
		if (self::IsActive() && class_exists('MetaModel') && MetaModel::IsValidClass('Attachment')
			&& in_array('attachment', array_map('strtolower', self::lineage($sClass)), true)) {
			$aHosts = [];
			foreach (self::state()['classes'] as $sExcluded) {
				$sExcluded = self::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				$aHosts[] = $sExcluded;
				foreach (MetaModel::EnumChildClasses($sExcluded, ENUM_CHILD_CLASSES_ALL) as $sChild) {
					$aHosts[] = (string)$sChild;
				}
			}
			if ($aHosts !== []) {
				$oSearch = $oSearch->Intersect(\DBObjectSearch::FromOQL(sprintf(
					'SELECT %s WHERE item_class NOT IN (%s)',
					$sClass,
					implode(', ', array_map(static fn (string $s): string => "'".$s."'", array_unique($aHosts)))
				)));
			}
		}

		$aBelow = self::ExcludedBelow($sClass);
		if ($aBelow === []) {
			return $oSearch;
		}

		$oOut = \DBObjectSearch::FromOQL(sprintf(
			'SELECT %s WHERE finalclass NOT IN (%s)',
			$sClass,
			implode(', ', array_map(static fn (string $s): string => "'".$s."'", $aBelow))
		));

		return $oSearch->Intersect($oOut);
	}

	/**
	 * Why a query is refused although every class and field it names is served:
	 * it names a class that has an excluded subclass anywhere but as the one it
	 * selects, and there the rows of the subclass cannot be told apart from the
	 * rest - a join or a sub-query on Contact would match an excluded Person.
	 * Said without naming the class: it would say there is something under it.
	 */
	public static function OqlRefusal(string $sOql): ?string
	{
		if (!self::IsActive()) {
			return null;
		}

		$aTainted = [];
		try {
			foreach (self::state()['classes'] as $sExcluded) {
				$sExcluded = self::canonicalClass(strtolower($sExcluded)) ?? $sExcluded;
				if (MetaModel::IsValidClass($sExcluded)) {
					foreach (self::lineage($sExcluded) as $sParent) {
						if (strcasecmp($sParent, $sExcluded) !== 0) {
							$aTainted[strtolower($sParent)] = true;
						}
					}
				}
			}
		} catch (Throwable $e) {
			return 'This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';
		}

		foreach (array_keys($aTainted) as $sParent) {
			$iNamed = preg_match_all('/\b'.preg_quote($sParent, '/').'\b/i', $sOql);
			if ($iNamed > 1) {
				return 'This query cannot be checked against what this channel serves, so it is refused. Select from one class and filter on its attributes.';
			}
		}

		return null;
	}

	/** Forgets what was read, for a suite that changes the configuration between cases. */
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
				&& in_array(strtolower($sClass), array_map('strtolower', self::lineage(self::canonicalClass((string)$sListed) ?? (string)$sListed)), true)) {
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
	private static function lineage(string $sClass): array
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
	 * @return array{key: string, classes: array<string, string>, attributes: array<string, array<string, string>>, problem: ?string, active: bool, datamodel: ?string}
	 */
	private static function state(): array
	{
		$oConfig = utils::GetConfig();
		$mClasses = $oConfig->GetModuleSetting(MCPHelper::MODULE_NAME, self::SETTING_CLASSES, []);
		$mAttributes = $oConfig->GetModuleSetting(MCPHelper::MODULE_NAME, self::SETTING_ATTRIBUTES, []);
		$sKey = md5(serialize([$mClasses, $mAttributes]));

		if (self::$aCache !== null && self::$aCache['key'] === $sKey) {
			return self::$aCache;
		}
		self::$aMemo = [];

		$aParsed = self::Parse($mClasses, $mAttributes);
		$sProblem = $aParsed['problem'] ?? self::datamodelProblem($aParsed['classes'], $aParsed['attributes']);

		if ($sProblem !== null) {
			MCPHelper::LogError("Itop configuration parameter for exclusions: {$sProblem}. Nothing is served to anyone until it is corrected.");
			$aParsed = ['classes' => [], 'attributes' => [], 'problem' => $sProblem];
		}

		return self::$aCache = $aParsed + [
			'key'       => $sKey,
			'active'    => $aParsed['classes'] !== [] || $aParsed['attributes'] !== [],
			'datamodel' => null,
		];
	}

	/**
	 * What the datamodel says is wrong with a setting that parses: a name it
	 * does not have, an attribute that is not on its class, or one that is part
	 * of the class's friendly name.
	 *
	 * @param array<string, string>                $aClasses
	 * @param array<string, array<string, string>> $aAttributes
	 */
	private static function datamodelProblem(array $aClasses, array $aAttributes): ?string
	{
		if (!class_exists('MetaModel') || ($aClasses === [] && $aAttributes === [])) {
			return null;
		}

		try {
			foreach ($aClasses as $sKey => $sClass) {
				if (self::canonicalClass((string)$sKey) === null) {
					return sprintf("'%s' names '%s', which this datamodel does not have", self::SETTING_CLASSES, $sClass);
				}
			}
			foreach ($aAttributes as $sKey => $aCodes) {
				$sClass = self::canonicalClass($sKey);
				if ($sClass === null) {
					return sprintf("'%s' names '%s', which this datamodel does not have", self::SETTING_ATTRIBUTES, $sKey);
				}
				foreach ($aCodes as $sCode) {
					if (!MetaModel::IsValidAttCode($sClass, $sCode)) {
						return sprintf("'%s' names '%s' on %s, which has no such attribute", self::SETTING_ATTRIBUTES, $sCode, $sClass);
					}
					foreach (array_merge([$sClass], MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL)) as $sOn) {
						$aInName = (array)(MetaModel::GetNameSpec($sOn)[1] ?? []);
						// The complementary name too (a ticket's title is part of what a name search matches),
						// which is asked on its own: the combined long name calls a function iTop 3.2 does not define.
						if (class_exists('Combodo\iTop\Core\MetaModel\FriendlyNameType')) {
							try {
								$aInName = array_merge($aInName, (array)(MetaModel::GetNameSpec($sOn, \Combodo\iTop\Core\MetaModel\FriendlyNameType::COMPLEMENTARY)[1] ?? []));
							} catch (Throwable $e) {
								// Nothing to add.
							}
						}
						if (in_array($sCode, $aInName, true)) {
							return sprintf("'%s' excludes '%s' of %s, which is part of the friendly name of %s: the friendly name would still serve its value", self::SETTING_ATTRIBUTES, $sCode, $sClass, $sOn);
						}
					}
				}
			}
		} catch (Throwable $e) {
			return MCPHelper::OpaqueFailure('The datamodel could not be checked against the exclusions', $e);
		}

		return null;
	}

	private static function canonicalClass(string $sLowerName): ?string
	{
		foreach (MetaModel::GetClasses() as $sClass) {
			if (strtolower((string)$sClass) === $sLowerName) {
				return (string)$sClass;
			}
		}

		return null;
	}
}
