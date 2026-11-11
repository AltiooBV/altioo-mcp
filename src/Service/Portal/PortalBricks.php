<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Combodo\iTop\Portal\Brick\BrowseBrick;
use Combodo\iTop\Portal\Brick\CreateBrick;
use Combodo\iTop\Portal\Brick\ManageBrick;
use DBSearch;

/**
 * What the portal's granted bricks reach, list and create.
 *
 * The part of reading a portal that turns its bricks into data: which classes a
 * brick reaches, the named lists it offers and the create actions it declares.
 * What the user may then see or do with that is PortalPolicy's to answer.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalBricks
{
	public const BRICK_MANAGE = 'manage';

	public const BRICK_BROWSE = 'browse';

	public const BRICK_CREATE = 'create';

	/** A BrowseBrick action that opens a creation form. */
	private const ACTION_CREATE_FROM_THIS = 'create_from_this';

	/** How such an action says what it creates: BrowseBrick's ENUM_FACTORY_TYPE_*. */
	private const FACTORY_CLASS = 'class';

	private const FACTORY_METHOD = 'method';

	/**
	 * What a brick class is to portal mode: one of iTop's own ManageBrick,
	 * BrowseBrick and CreateBrick, or nothing.
	 *
	 * A subclass of one of them is read as that type (doc/adr/0002): a custom
	 * brick class extending ManageBrick still declares its data the way a
	 * ManageBrick does, and reads stay bounded by the scopes whatever its
	 * controller adds. A class that is none of them - one extending
	 * PortalBrick directly, a FilterBrick - reaches nothing.
	 */
	public static function BrickKind(string $sBrickClass): ?string
	{
		$sBrickClass = ltrim($sBrickClass, '\\');

		foreach ([ManageBrick::class => self::BRICK_MANAGE, BrowseBrick::class => self::BRICK_BROWSE, CreateBrick::class => self::BRICK_CREATE] as $sType => $sKind) {
			// By name first, so that the answer does not need the portal
			// module loaded for the stock classes themselves.
			if ($sBrickClass === $sType || is_a($sBrickClass, $sType, true)) {
				return $sKind;
			}
		}

		return null;
	}

	/**
	 * The create action a BrowseBrick action declares, or null when it is
	 * not one.
	 *
	 * Its class is named, or chosen by a method the portal calls with the
	 * object the user picked: on an instance with both request and incident
	 * management, iTop's own itop-full-itil makes the services brick's action
	 * \Ticket::CreateFromServiceSubcategory, which answers UserRequest or
	 * Incident from the subcategory's request type. Such an action is kept,
	 * with no class - what the method returns is PHP, and is never guessed -
	 * and the method with it, for the step that creates, which can run it on
	 * the server as the portal does. It reaches no class: only a named one
	 * does.
	 *
	 * @param array<string, mixed> $aAction As BrowseBrick::GetLevels() gives one level's action.
	 *
	 * @return array{action: string, class: string|null, factory_method: string|null, title: string, from: string, rules: array<int, string>}|null
	 */
	public static function CreateActionOf(string $sAction, array $aAction, string $sFrom, string $sTitle): ?array
	{
		if (($aAction['type'] ?? null) !== self::ACTION_CREATE_FROM_THIS) {
			return null;
		}

		$sFactory = (string)($aAction['factory']['type'] ?? '');
		$sValue = trim((string)($aAction['factory']['value'] ?? ''));
		if ($sValue === '' || !in_array($sFactory, [self::FACTORY_CLASS, self::FACTORY_METHOD], true)) {
			return null;
		}

		return [
			'action'         => $sAction,
			'class'          => $sFactory === self::FACTORY_CLASS ? $sValue : null,
			'factory_method' => $sFactory === self::FACTORY_METHOD ? $sValue : null,
			'title'          => $sTitle,
			'from'           => $sFrom,
			'rules'          => array_values(array_map('strval', (array)($aAction['rules'] ?? []))),
		];
	}

	/**
	 * What the granted bricks reach, list and create.
	 *
	 * The bricks here are already the granted ones: BrickCollection keeps a
	 * brick only when it is active and its security passes for the user's
	 * profiles, which is the portal's own test. Visibility on the home page or
	 * in the menu is layout and is not looked at - a brick hidden from both is
	 * often the one doing the work.
	 *
	 * A create_from_this whose class is chosen by a method rather than named
	 * is listed with no class and reaches nothing: what that method does is
	 * PHP, and is never guessed ({@see CreateActionOf()}). A brick that is
	 * none of iTop's three data types nor a subclass of one reaches nothing
	 * ({@see BrickKind()}).
	 *
	 * @param iterable<int, object> $aBricks
	 *
	 * @return array{0: array<string, array<int, string>>, 1: array<string, array<string, array<int, DBSearch|null>>>, 2: array<int, array<string, mixed>>, 3: array<string, array<string, mixed>>, 4: array<int, array<string, mixed>>}
	 */
	public static function Granted(iterable $aBricks): array
	{
		$aReach = [];
		$aBrickSets = [];
		$aLists = [];
		$aListDefs = [];
		$aCreates = [];

		foreach ($aBricks as $oBrick) {
			$sBrick = (string)$oBrick->GetId();
			$sKind = self::BrickKind(get_class($oBrick));

			if ($sKind === self::BRICK_MANAGE) {
				$oSet = DBSearch::FromOQL($oBrick->GetOql());
				$sClass = $oSet->GetClass();
				$aReach[$sClass][] = $sBrick;
				$aBrickSets[$sClass][$sBrick][] = $oSet;

				$aGrouping = $oBrick->GetGrouping();
				// Tabs and areas group the rows by an attribute; one the operator excluded splits nothing here.
				foreach (['tabs', 'areas'] as $sGrouping) {
					$sBy = $aGrouping[$sGrouping]['attribute'] ?? null;
					if (is_string($sBy) && ExclusionPolicy::ExcludesAttribute($sClass, $sBy)) {
						unset($aGrouping[$sGrouping]);
					}
				}
				$aTabs = [];
				$aConditions = [];
				foreach ($aGrouping['tabs']['groups'] ?? [] as $aGroup) {
					$aTabs[] = ['id' => (string)$aGroup['id'], 'title' => \Dict::S((string)$aGroup['title'])];
					$aConditions[(string)$aGroup['id']] = (string)$aGroup['condition'];
				}

				$aListDefs[$sBrick] = [
					'kind'       => self::BRICK_MANAGE,
					'class'      => $sClass,
					'oql'        => (string)$oBrick->GetOql(),
					'columns'    => self::withoutExcluded($sClass, array_map('strval', $oBrick->GetFields())),
					'tabs'       => $aConditions,
					'tabs_by'    => $aGrouping['tabs']['attribute'] ?? null,
					'parent'     => null,
					'parent_att' => null,
				];

				$aLists[] = [
					'list'       => $sBrick,
					'kind'       => self::BRICK_MANAGE,
					'title'      => \Dict::S((string)$oBrick->GetTitle()),
					'class'      => $sClass,
					'tabs'       => $aTabs,
					'tabs_by'    => $aGrouping['tabs']['attribute'] ?? null,
					'grouped_by' => $aGrouping['areas']['attribute'] ?? null,
				];
			} elseif ($sKind === self::BRICK_BROWSE) {
				self::levels($sBrick, \Dict::S((string)$oBrick->GetTitle()), $oBrick->GetLevels(), [], $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates);
			} elseif ($sKind === self::BRICK_CREATE) {
				$sClass = (string)$oBrick->GetClass();
				$aReach[$sClass][] = $sBrick;
				$aBrickSets[$sClass][$sBrick][] = null;
				$aCreates[] = [
					'action'         => $sBrick,
					'class'          => $sClass,
					'factory_method' => null,
					'title'          => \Dict::S((string)$oBrick->GetTitle()),
					'from'           => null,
					'from_list'      => null,
					'rules'          => array_values($oBrick->GetRules()),
				];
			}
		}

		return [$aReach, $aBrickSets, $aLists, $aListDefs, $aCreates];
	}

	/**
	 * The columns of a list, less those the operator excluded (doc/adr/0012): neither shown, searched nor counted by.
	 *
	 * @param array<int, string> $aColumns
	 *
	 * @return array<int, string>
	 */
	private static function withoutExcluded(string $sClass, array $aColumns): array
	{
		return array_values(array_filter($aColumns, static fn (string $sCode): bool => !ExclusionPolicy::ExcludesAttribute($sClass, $sCode)));
	}

	/**
	 * One BrowseBrick level and those under it.
	 *
	 * @param array<int, array<string, mixed>>        $aLevels
	 * @param array<int, string>                      $aPath   The level ids above this one.
	 * @param array<string, array<int, string>>       $aReach
	 * @param array<string, array<string, array<int, DBSearch|null>>> $aBrickSets
	 * @param array<int, array<string, mixed>>        $aLists
	 * @param array<string, array<string, mixed>>     $aListDefs
	 * @param array<int, array<string, mixed>>        $aCreates
	 */
	private static function levels(string $sBrick, string $sTitle, array $aLevels, array $aPath, array &$aReach, array &$aBrickSets, array &$aLists, array &$aListDefs, array &$aCreates): void
	{
		foreach ($aLevels as $aLevel) {
			$oSet = DBSearch::FromOQL((string)$aLevel['oql']);
			$sClass = $oSet->GetClass();
			$aReach[$sClass][] = $sBrick;
			$aBrickSets[$sClass][$sBrick][] = $oSet;

			$aLevelPath = array_merge($aPath, [(string)$aLevel['id']]);
			$sList = $sBrick.'/'.implode('/', $aLevelPath);

			$aLists[] = [
				'list'       => $sList,
				'kind'       => self::BRICK_BROWSE,
				'title'      => isset($aLevel['title']) ? \Dict::S((string)$aLevel['title']) : $sTitle,
				'class'      => $sClass,
				// The level above, whose rows parent_id is one of: how a
				// client walks down a browse brick it has never seen.
				'parent'     => $aPath === [] ? null : $sBrick.'/'.implode('/', $aPath),
				'parent_att' => $aLevel['parent_att'] ?? null,
			];

			// What the level shows of each object: its name, its description
			// and tooltip, and the fields it lists beside them.
			$aColumns = [];
			foreach (['name_att', 'description_att', 'tooltip_att'] as $sKey) {
				if (is_string($aLevel[$sKey] ?? null) && $aLevel[$sKey] !== '') {
					$aColumns[] = $aLevel[$sKey];
				}
			}
			$aListDefs[$sList] = [
				'kind'       => self::BRICK_BROWSE,
				'class'      => $sClass,
				'oql'        => (string)$aLevel['oql'],
				'columns'    => self::withoutExcluded($sClass, array_unique(array_merge($aColumns, array_map('strval', array_keys($aLevel['fields'] ?? []))))),
				'tabs'       => [],
				'tabs_by'    => null,
				'parent'     => $aPath === [] ? null : $sBrick.'/'.implode('/', $aPath),
				'parent_att' => $aLevel['parent_att'] ?? null,
			];

			foreach ($aLevel['actions'] ?? [] as $sAction => $aAction) {
				$aCreate = is_array($aAction) ? self::CreateActionOf($sList.'/'.(string)$sAction, $aAction, $sClass, $sTitle) : null;
				if ($aCreate === null) {
					continue;
				}

				// The level it is offered on, whose path holds the objects
				// the action's rules read: the subcategory picked, and the
				// service and family above it.
				$aCreate['from_list'] = $sList;

				$aCreates[] = $aCreate;
				if ($aCreate['class'] !== null) {
					$aReach[$aCreate['class']][] = $sBrick;
					// Created by name: nothing narrows which class it is.
					$aBrickSets[$aCreate['class']][$sBrick][] = null;
				}
			}

			self::levels($sBrick, $sTitle, $aLevel['levels'] ?? [], $aLevelPath, $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates);
		}
	}
}
