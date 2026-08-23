<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use DBObjectSearch;
use MetaModel;

/**
 * The searches the object tools build for themselves.
 *
 * One shape, written once. Every tool that reads, writes, deletes or moves a
 * single object first builds "this class, this id" so that
 * UserRights::IsActionAllowed() can be asked about that object rather than
 * about the class - which is the check that stops a caller learning an object
 * exists by being refused it.
 *
 * @api
 * @since 1.0.0
 */
final class ObjectQuery
{
	/** The name the identifier is bound under. */
	private const ID_PARAMETER = 'id';

	/** The name a list of identifiers is bound under. */
	private const IDS_PARAMETER = 'ids';

	/**
	 * A search for one object, by class and identifier.
	 *
	 * The identifier is bound rather than interpolated into the OQL. Nothing
	 * here is exploitable as it stands - $iId is typed int by the tool
	 * signatures, and the class names come from MetaModel rather than from the
	 * caller - but that is an argument about the current callers, and it has to
	 * be re-made every time one is added. Binding removes the question instead
	 * of answering it, and it is what iTop's own code does.
	 *
	 * The class stays interpolated because OQL names the class in its FROM
	 * clause, where no parameter can go; it is validated with
	 * MetaModel::IsValidClass() before reaching here.
	 *
	 * @since 1.0.0
	 */
	public static function ById(string $sClass, int $iId): DBObjectSearch
	{
		$sKey = MetaModel::DBGetKey($sClass);

		return DBObjectSearch::FromOQL(
			"SELECT {$sClass} WHERE {$sKey} = :".self::ID_PARAMETER,
			[self::ID_PARAMETER => $iId]
		);
	}

	/**
	 * A search for a set of objects of one class, by identifier.
	 *
	 * The IN list is bound as a list, which OQL takes directly - see
	 * cmdbabstract.class.inc.php, which binds ":triggers" the same way. Built
	 * by hand it was an implode() into the query text, which is the one shape
	 * where an unexpected value in the list turns into query syntax.
	 *
	 * @param array<int|string, int|string> $aIds Keys are ignored; the values are the identifiers.
	 * @since 1.0.0
	 */
	public static function ByIds(string $sClass, array $aIds): DBObjectSearch
	{
		$sKey = MetaModel::DBGetKey($sClass);

		return DBObjectSearch::FromOQL(
			"SELECT {$sClass} WHERE {$sKey} IN (:".self::IDS_PARAMETER.')',
			[self::IDS_PARAMETER => array_values(array_map('intval', $aIds))]
		);
	}
}
