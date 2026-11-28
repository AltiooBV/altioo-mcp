<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use MetaModel;

/**
 * What the lifecycle's first state asks of a class, said of the class and not of an object: what the schema reports,
 * and what an exclusion has to know about before a create is tried. A write asks the object itself, which also hears
 * the extensions (doc/adr/0004, Amendment 1).
 *
 * @internal
 * @since 2.2.0
 */
final class InitialState
{
	/**
	 * Whether the first state flags a writable attribute mandatory without hiding it. False for a class without a
	 * lifecycle, and for the state itself, which no create sends.
	 *
	 * @psalm-suppress MixedAssignment, MixedArgument, MixedOperand MetaModel is not typed in the stubs.
	 */
	public static function MakesMandatory(string $sClass, string $sAttCode): bool
	{
		if (!MetaModel::HasLifecycle($sClass) || MetaModel::GetStateAttributeCode($sClass) === $sAttCode
			|| !MetaModel::GetAttributeDef($sClass, $sAttCode)->IsWritable()) {
			return false;
		}
		$iFlags = (int)MetaModel::GetInitialStateAttributeFlags($sClass, MetaModel::GetDefaultState($sClass), $sAttCode);

		return ($iFlags & OPT_ATT_MANDATORY) !== 0 && ($iFlags & OPT_ATT_HIDDEN) === 0;
	}
}
