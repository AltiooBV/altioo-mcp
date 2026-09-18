<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\MCPContext;

/**
 * How much of the surface one caller is served.
 *
 * Two questions, decided together and from the same places: what this caller
 * may do, and which toolsets it sees.
 *
 * "What it may do" is graded rather than binary. A read-only switch is the
 * usual answer - it is what the GitHub server offers - and it does not survive
 * contact with the common request, which is an assistant that may open a
 * ticket and add a work note but must not delete anything. Atlassian's remote
 * server grades its OAuth scopes for the same reason. The three grades here
 * are read, write and delete, and they are read off the tool annotations every
 * tool already declares, so a pack is graded by saying what its tools are -
 * not by listing them anywhere.
 *
 * There are two sources. The instance configuration - mcp_capabilities and
 * mcp_enabled_toolsets - which applies to everyone. And the scopes of the
 * token the caller authenticated with, which apply to that credential alone.
 * The two combine by keeping whichever is narrower, so a token can restrict
 * itself further than the instance but never reach past it.
 *
 * Naming nothing and being left with nothing are different answers, and the
 * difference is the whole of the guarantee above. A side that names no grade
 * has no opinion, so it serves all of them; two sides that name grades and
 * agree on none serve none. Collapsing the two - reading "nothing left" as
 * "nothing named" - is what would let a read-only instance plus a
 * delete-scoped token come out wider than either, so the lists are held as
 * null for "nothing named" and an empty array for "nothing left".
 *
 * Per-token grading is the part that is hard to get any other way. Everything
 * else in iTop's permission model hangs off the user, so the usual way to hold
 * "the assistant may not delete" is a second user account with its own
 * profiles, kept in step by hand. A scope on a token is one person holding two
 * credentials of different strength, and the token classes already carry the
 * field.
 *
 * A value object: no iTop, no configuration, no request. Every rule below is
 * decided here and tested without any of them.
 *
 * @since 1.0.0
 */
final class AccessPolicy
{
	/** Reads nothing but reads: a tool that declares readOnlyHint. */
	public const CAPABILITY_READ = 'read';

	/** Creates or modifies: a tool that is neither read-only nor destructive. */
	public const CAPABILITY_WRITE = 'write';

	/** Destroys: a tool that declares destructiveHint, and anything unannotated. */
	public const CAPABILITY_DELETE = 'delete';

	public const CAPABILITIES = [self::CAPABILITY_READ, self::CAPABILITY_WRITE, self::CAPABILITY_DELETE];

	/**
	 * @param array<int, string>|null $aCapabilities Granted grades; null names none, which serves all of them.
	 * @param array<int, string>|null $aToolsets     Toolsets served; null names none, which serves all of them.
	 */
	private function __construct(
		private readonly ?array $aCapabilities,
		private readonly ?array $aToolsets,
		private readonly bool $bAdvisory = false,
	)
	{
	}

	/** Everything, which is what an instance that configures nothing gets. */
	public static function Unrestricted(): self
	{
		return new self(null, null);
	}

	/**
	 * Whether every write this policy allows must rehearse instead of writing.
	 *
	 * A modifier rather than a grade: it does not decide which tools are
	 * served, it decides what they do when called. See
	 * MCPContext::SCOPE_ADVISORY.
	 *
	 * @since 1.0.0
	 */
	public function isAdvisory(): bool
	{
		return $this->bAdvisory;
	}

	/**
	 * The policy of the request being served, for the code that needs it and
	 * is not handed it.
	 *
	 * Remembered once by the controller rather than recomputed, because
	 * recomputing reads the token out of the database and a write tool asking
	 * "am I rehearsing" should not cost a query. Static, and safe to be: this
	 * endpoint serves one request per process and holds nothing between them.
	 *
	 * Null before the controller has decided, which is the state a unit suite
	 * runs in - and {@see \Altioo\iTop\Extension\MCP\Helper\WritePlan::Simulated()}
	 * treats null as "no opinion" rather than "not advisory", so nothing here
	 * quietly turns a rehearsal into a write.
	 */
	private static ?self $oCurrent = null;

	/** @since 1.0.0 */
	public static function Remember(self $oPolicy): void
	{
		self::$oCurrent = $oPolicy;
	}

	/** @since 1.0.0 */
	public static function Current(): ?self
	{
		return self::$oCurrent;
	}

	/** For tests, and for a runner that serves more than one request. @since 1.0.0 */
	public static function Forget(): void
	{
		self::$oCurrent = null;
	}

	/**
	 * What an operator wrote, which is a list or nothing at all.
	 *
	 * An empty list is a setting nobody filled in, so it names nothing and
	 * serves everything. A list that names only grades this endpoint has never
	 * heard of is a different thing: something was meant, none of it can be
	 * honoured, and serving everything because a line was misspelt is the
	 * failure mode worth avoiding - so that one serves nothing.
	 *
	 * @param array<int, string> $aCapabilities Empty for all of them.
	 * @param array<int, string> $aToolsets     Empty for all of them.
	 */
	public static function Of(array $aCapabilities, array $aToolsets): self
	{
		return new self(
			empty($aCapabilities)
				? null
				: array_values(array_unique(array_intersect($aCapabilities, self::CAPABILITIES))),
			empty($aToolsets) ? null : array_values(array_unique($aToolsets))
		);
	}

	/**
	 * What the scopes of a token grant.
	 *
	 * Only scopes belonging to this endpoint are read; a token also carrying
	 * REST/JSON or Export says nothing here.
	 *
	 *   MCP                    everything
	 *   MCP-read               may read
	 *   MCP-write              may read and write, but not delete
	 *   MCP-delete             may delete
	 *   MCP-toolset-<name>     restricted to that toolset, e.g. MCP-toolset-objects
	 *
	 * The grades are a union of what is listed rather than a ladder position,
	 * so MCP-write alone is "read and write" - granting write without read
	 * would describe nothing anyone wants - and MCP-delete alone is a token
	 * that may only destroy, which is strange but is what it says. The
	 * toolsets prefix is spelled out so that a pack naming a toolset "write"
	 * cannot collide with a grade.
	 *
	 * No MCP scope at all means no opinion, not "nothing": basic
	 * authentication carries no token, and the instance configuration decides
	 * on its own.
	 *
	 * @param array<int, string> $aScopes As held by the token.
	 */
	public static function FromScopes(array $aScopes): self
	{
		$bEverything = false;
		$bAdvisory = false;
		$aCapabilities = [];
		$aToolsets = [];

		foreach ($aScopes as $sScope) {
			if ($sScope === MCPContext::SCOPE_MCP) {
				$bEverything = true;
				continue;
			}
			// Read before the grades and independently of them: a token
			// carrying MCP and MCP-advisory is asking for everything, as a
			// rehearsal. The modifier narrows, so it survives "everything".
			if ($sScope === MCPContext::SCOPE_ADVISORY) {
				$bAdvisory = true;
				continue;
			}
			if (str_starts_with($sScope, MCPContext::SCOPE_TOOLSET_PREFIX)) {
				$aToolsets[] = substr($sScope, strlen(MCPContext::SCOPE_TOOLSET_PREFIX));
				continue;
			}
			if (str_starts_with($sScope, MCPContext::SCOPE_MCP.'-')) {
				$sCapability = substr($sScope, strlen(MCPContext::SCOPE_MCP) + 1);
				if (in_array($sCapability, self::CAPABILITIES, true)) {
					$aCapabilities[] = $sCapability;
				}
			}
		}

		// Writing without reading describes nothing an operator means.
		if (!empty($aCapabilities) && in_array(self::CAPABILITY_WRITE, $aCapabilities, true)) {
			$aCapabilities[] = self::CAPABILITY_READ;
		}

		if ($bEverything) {
			return new self(null, null, $bAdvisory);
		}

		return new self(
			empty($aCapabilities) ? null : array_values(array_unique($aCapabilities)),
			empty($aToolsets) ? null : $aToolsets,
			$bAdvisory
		);
	}

	/**
	 * The narrower of the two, rule by rule.
	 *
	 * Both lists intersect - except that a side naming nothing means "all of
	 * them" rather than "none of them", so it contributes nothing rather than
	 * emptying the result.
	 */
	public function narrowedBy(self $oOther): self
	{
		return new self(
			self::narrowList($this->aCapabilities, $oOther->aCapabilities),
			self::narrowList($this->aToolsets, $oOther->aToolsets),
			// Or rather than and: advisory on either side is the narrower
			// answer, and narrowing is the only thing this method does.
			$this->bAdvisory || $oOther->bAdvisory
		);
	}

	/**
	 * Null on either side names nothing and drops out; two named lists
	 * intersect, and an intersection that comes out empty stays empty - the
	 * two sides agreed on nothing, which grants nothing.
	 *
	 * @param array<int, string>|null $aOne
	 * @param array<int, string>|null $aOther
	 *
	 * @return array<int, string>|null
	 */
	private static function narrowList(?array $aOne, ?array $aOther): ?array
	{
		if ($aOne === null) {
			return $aOther;
		}
		if ($aOther === null) {
			return $aOne;
		}

		return array_values(array_intersect($aOne, $aOther));
	}

	public function allowsCapability(string $sCapability): bool
	{
		return $this->aCapabilities === null || in_array($sCapability, $this->aCapabilities, true);
	}

	public function allowsToolset(string $sToolset): bool
	{
		return $this->aToolsets === null || in_array($sToolset, $this->aToolsets, true);
	}

	/**
	 * Whether a tool is served, given what its annotations claim about it.
	 *
	 * @param bool|null $bReadOnlyHint    As annotated, or null when unannotated.
	 * @param bool|null $bDestructiveHint As annotated, or null when unannotated.
	 */
	public function allowsTool(?bool $bReadOnlyHint, ?bool $bDestructiveHint): bool
	{
		return $this->allowsCapability(self::CapabilityOf($bReadOnlyHint, $bDestructiveHint));
	}

	/**
	 * The grade a tool falls into.
	 *
	 * A tool that claims nothing is graded delete - the harshest grade, and
	 * the one that keeps it out of every narrowed token. getAnnotations()
	 * defaults to null, so "claims nothing" is the state of every tool whose
	 * author never considered the question, and grading those as harmless is
	 * how a pack update quietly hands a read-only credential something that
	 * writes. The cost of the mistake is a tool that does not appear until it
	 * is annotated, which is a complaint someone makes rather than a breach
	 * nobody notices.
	 */
	public static function CapabilityOf(?bool $bReadOnlyHint, ?bool $bDestructiveHint): string
	{
		if ($bReadOnlyHint === true) {
			// destructiveHint is defined as meaningless when readOnlyHint is
			// set, and iTop has no tool that reads by destroying.
			return self::CAPABILITY_READ;
		}

		if ($bReadOnlyHint === null && $bDestructiveHint === null) {
			return self::CAPABILITY_DELETE;
		}

		return $bDestructiveHint === true ? self::CAPABILITY_DELETE : self::CAPABILITY_WRITE;
	}

	/** @return array<int, string>|null Null when nothing is named, which serves all of them. */
	public function capabilities(): ?array
	{
		return $this->aCapabilities;
	}

	/** @return array<int, string>|null Null when nothing is named, which serves all of them. */
	public function toolsets(): ?array
	{
		return $this->aToolsets;
	}
}
