<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use DBObject;
use Mcp\Exception\ToolCallException;
use MetaModel;

/**
 * What the tools served in portal mode share.
 *
 * Only what has no console counterpart is one: the portal's lists. Reading,
 * changing and creating objects are the console's tools, served in portal
 * mode through {@see \Altioo\iTop\Extension\MCP\Service\ObjectAccess}
 * (doc/adr/0006-one-tool-surface-the-portal-is-a-policy.md).
 *
 * Each one answers from {@see PortalPolicy} and nothing else, and none of
 * them asks UserRights alone, which grants "Portal user" read on every
 * bizmodel class.
 *
 * @internal
 * @since 1.2.0
 */
abstract class AbstractPortalTool extends AbstractMCPTool implements PortalAware
{
	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves, as the console's object tools do. */
	public function getToolset(): string
	{
		return 'objects';
	}

	/** Portal mode only: a console user has the object tools. */
	public function isAvailable(): bool
	{
		return PortalMode::Current() !== null;
	}

	/**
	 * The policy of this request's portal, or the refusal a client is given
	 * when the portal cannot be read - already logged where it was raised.
	 */
	protected static function policy(): PortalPolicy
	{
		return self::portalRefusal(static fn () => PortalPolicy::ForCurrentRequest());
	}

	/**
	 * Refuses a list of the change log, which core_object_history serves
	 * one object at a time.
	 */
	protected static function refuseReserved(string $sClass): void
	{
		if (ObjectHistory::IsReserved($sClass)) {
			throw new ToolCallException('This list is the change log: core_object_history gives the history of one object the portal shows you.');
		}
	}

	/**
	 * An object as the portal shows it: the attributes named, each one the
	 * user may read, external keys with the name of what they point at.
	 *
	 * @param array<int, string> $aAttCodes
	 *
	 * @return array<string, mixed>
	 */
	protected static function serialized(DBObject $oObject, array $aAttCodes): array
	{
		$sClass = get_class($oObject);

		$aFields = [];
		foreach ($aAttCodes as $sAttCode) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				continue;
			}
			$aFields[] = $sAttCode;

			// The id alone says nothing to the person reading the answer; the
			// portal shows the name.
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if ($oAttDef->IsExternalKey() && MetaModel::IsValidAttCode($sClass, $sAttCode.'_friendlyname')) {
				$aFields[] = $sAttCode.'_friendlyname';
			}
		}
		if (MetaModel::IsValidAttCode($sClass, 'friendlyname')) {
			array_unshift($aFields, 'friendlyname');
		}

		return ObjectSerializer::Serialize($oObject, $sClass, array_values(array_unique($aFields)));
	}
}
