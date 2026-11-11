<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

/**
 * What a rights block says, and the words it says it in.
 *
 * The eight gates a class is graded on, the three grades an answer from
 * UserRights becomes (yes, no, depends), the stricter of two grades, and the
 * values a caller may narrow a class list by. Pure: no datamodel is read here.
 * What the barrier on access-granting classes adds to a grade is
 * ClassRights.
 *
 * @internal
 * @since 2.0.0
 */
final class GateGrades
{
	/**
	 * The keys a rights block carries, in the order rights() writes them.
	 *
	 * Written here rather than derived from rights(), which cannot be called
	 * without iTop - and held level with it by SchemaToolsContractTest, which
	 * reads that method's source. A key that exists in the block and not here
	 * is one a caller cannot narrow on; one here and not in the block is a
	 * filter that silently matches nothing.
	 */
	/**
	 * The gates whose answer can differ from one object to the next.
	 *
	 * Reading is settled by the time an object is in a result, and creating
	 * is asked of a class rather than of an object that does not exist yet.
	 * What is left is the four writes, which a silo or an addon grading per
	 * object answers one object at a time.
	 */
	public const OBJECT_RIGHTS_KEYS = ['modify', 'bulkModify', 'delete', 'bulkDelete'];

	/**
	 * The `may` value that reports every gate and narrows on none.
	 *
	 * A rights block always carries all eight grades, so one call already
	 * answers "what may I create, modify and delete here" - what it could not
	 * do was answer it for the classes one of those gates refuses, because
	 * asking for rights and narrowing on them were the same request. This
	 * separates them. Spelled '*' rather than named after a gate, so nothing
	 * reads it as a ninth one.
	 *
	 * @since 1.0.0
	 */
	public const RIGHTS_ALL = '*';

	private const RIGHTS_KEYS = [
		'read',
		'bulkRead',
		'create',
		'bulkCreate',
		'modify',
		'bulkModify',
		'delete',
		'bulkDelete',
	];

	/**
	 * The gates {@see ClassListPayload()} can narrow on, which are the keys a
	 * rights block carries.
	 *
	 * Public because the tool builds its enum and its refusal message from
	 * this: a second list written out in the schema would be a second thing to
	 * keep level with rights(), and the one a client validates against.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function RightsKeys(): array
	{
		return self::RIGHTS_KEYS;
	}

	/**
	 * Everything `may` accepts: the gates, plus {@see RIGHTS_ALL}.
	 *
	 * Separate from {@see RightsKeys()} because the two are asked different
	 * questions. RightsKeys() is the keys a rights block carries, which is what
	 * FilterByRight() indexes into; this is what a caller may send, and '*' is
	 * a request rather than a key.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function MayValues(): array
	{
		return array_merge([self::RIGHTS_ALL], self::RIGHTS_KEYS);
	}

	/**
	 * The stricter of two grades, which is how a tool gated on both answers.
	 *
	 * 'no' wins over everything, because either refusal ends the call on its
	 * own. 'depends' wins over 'yes' for the same reason one step later: a
	 * conjunction is only settled when both halves are, and one half still
	 * asking for the object leaves the pair asking for it.
	 */
	public static function stricter(string $sLeft, string $sRight): string
	{
		if ($sLeft === 'no' || $sRight === 'no') {
			return 'no';
		}
		if ($sLeft === 'depends' || $sRight === 'depends') {
			return 'depends';
		}

		return 'yes';
	}

	/**
	 * One tri-state rights answer, as a word a model can act on.
	 *
	 * DEPENDS keeps its own word rather than being folded into either
	 * neighbour. It is the addon saying "ask again, with the object in hand",
	 * and a model told 'yes' or 'no' instead has been told something nobody
	 * answered - the same mistake, one surface further out, that reading these
	 * as booleans makes in the tools.
	 *
	 * The parameter carries no type, on purpose. UserRights answers with the
	 * UR_ALLOWED_* constants, but the return type is not declared on the iTop
	 * side, and a bool arriving at an `int` hint under strict_types is a
	 * TypeError rather than a wrong answer. The cast reads both: true and false
	 * land on UR_ALLOWED_YES and UR_ALLOWED_NO, which are 1 and 0.
	 *
	 * @param mixed $mAllowed A UR_ALLOWED_* answer, as iTop returned it.
	 */
	public static function grade($mAllowed): string
	{
		return match ((int)$mAllowed) {
			UR_ALLOWED_NO => 'no',
			UR_ALLOWED_YES => 'yes',
			default => 'depends',
		};
	}
}
