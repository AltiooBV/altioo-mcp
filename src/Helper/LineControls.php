<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * The characters that make one line of text read as two, or read in another order, which a
 * caller's text may not keep once it is stored where people (and models) read it back.
 *
 * ASCII controls and DEL, the C1 controls (U+0085, NEL, among them), the Unicode line and
 * paragraph separators (U+2028, U+2029), and the bidirectional formatting characters (the
 * marks U+061C, U+200E, U+200F, the embeddings and overrides U+202A to U+202E, the isolates
 * U+2066 to U+2069). A log forwarder splits on the first group and a reader sees the second
 * reorder what follows; neither is a character anyone needs in a client's name or in a comment.
 *
 * @internal
 * @since 2.1.0
 */
final class LineControls
{
	/** For preg_replace() with the u modifier. */
	public const PATTERN = '/[\x00-\x1F\x7F\x{80}-\x{9F}\x{2028}\x{2029}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u';

	/**
	 * The text with each run of those characters replaced by one space. The text must be valid
	 * UTF-8 (mb_scrub() first); null comes back when it is not and the pattern cannot run.
	 */
	public static function Replace(string $sText): ?string
	{
		return preg_replace(self::PATTERN, ' ', $sText);
	}
}
