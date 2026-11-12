<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use PhpToken;
use ReflectionClass;

/**
 * What a source file calls, read from its PHP tokens.
 *
 * The contract tests of ADR 0014 ask whether a tool's own source makes a call:
 * `AccessGrants::RefusalFor(`, `ChangeLogReservation::IsReserved(`, `->Hides(`.
 * A text search answers that wrongly in two directions: it is satisfied by a
 * comment, a docblock or a string that names the call, and it breaks on every
 * rename that leaves the call in place. This class parses the file with
 * TOKEN_PARSE and answers from the code only.
 *
 * - Comments, docblocks, strings and inline HTML never count as code.
 * - `X::m(` is resolved through the file's namespace and `use` lines, so a
 *   `RefusalFor` on another class with the same short name does not count.
 *   `self::` and `static::` are the class the method is in, `parent::` the
 *   class it extends.
 * - Names are compared without regard to case, as PHP does.
 *
 * A call is described by a spec string, the form it has in the source:
 * `Fully\Qualified\Class::Method` (static), `->method` (on any object) or
 * `function_name` (a plain function, no namespace resolution). A class
 * constant is `Fully\Qualified\Class::CONST`, a global one `UR_ACTION_READ`.
 *
 * Methods are found by name. Where `$sMethod` is null the question is asked of
 * the whole file.
 */
final class SourceCalls
{
	private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

	private string $sFile;
	private string $sNamespace = '';
	/** @var array<string, string> lowercase alias => class, class imports only */
	private array $aImports = [];
	/** @var array<int, array{class: string, parent: string, name: string, from: int, to: int}> */
	private array $aMethods = [];
	/** @var array<int, array{0: string, 1: string, 2: int, 3: int}> class, parent, first and last token of each class body */
	private array $aClassRanges = [];
	/** @var array<int, PhpToken> */
	private array $aTokens;

	private function __construct(string $sCode, string $sFile)
	{
		$this->sFile = $sFile;
		$this->aTokens = array_values(array_filter(
			PhpToken::tokenize($sCode, TOKEN_PARSE),
			static fn (PhpToken $oToken): bool => !in_array($oToken->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)
		));
		$this->index();
	}

	public static function OfFile(string $sFile): self
	{
		$sCode = file_get_contents($sFile);
		if ($sCode === false) {
			throw new \RuntimeException("SourceCalls cannot read {$sFile}");
		}

		return new self($sCode, $sFile);
	}

	/**
	 * @param class-string|object $mClass
	 */
	public static function OfClass(string|object $mClass): self
	{
		$sFile = (new ReflectionClass($mClass))->getFileName();
		if ($sFile === false) {
			throw new \RuntimeException('SourceCalls cannot read an internal class');
		}

		return self::OfFile($sFile);
	}

	public static function OfCode(string $sCode): self
	{
		return new self($sCode, '(code)');
	}

	public function File(): string
	{
		return $this->sFile;
	}

	/**
	 * The classes the file imports with `use`, alias (as written, any case kept)
	 * => fully qualified name. Function and constant imports, trait `use` inside
	 * a class and closure `use` are not included.
	 *
	 * @return array<string, string>
	 */
	public function Imports(): array
	{
		$aImports = [];
		foreach ($this->aImports as $aImport) {
			$aImports[$aImport[0]] = $aImport[1];
		}

		return $aImports;
	}

	/** The file's namespace, without a leading or trailing backslash. */
	public function NamespaceName(): string
	{
		return $this->sNamespace;
	}

	/**
	 * @return array<int, string> the names of the methods the file declares
	 */
	public function MethodNames(): array
	{
		return array_map(static fn (array $aMethod): string => $aMethod['name'], $this->aMethods);
	}

	public function HasMethod(string $sMethod): bool
	{
		foreach ($this->aMethods as $aMethod) {
			if (strtolower($aMethod['name']) === strtolower($sMethod)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every call, in source order, as the specs the class doc describes.
	 *
	 * @return array<int, string>
	 */
	public function Calls(?string $sMethod = null): array
	{
		$aCalls = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sSpec = $this->callAt($i, $sClass, $sParent);
				if ($sSpec !== null) {
					$aCalls[] = $sSpec;
				}
			}
		}

		return $aCalls;
	}

	/**
	 * Does the method (or the file) contain this call, `X::m` resolved?
	 */
	public function HasCall(string $sSpec, ?string $sMethod = null): bool
	{
		$sWanted = $this->normalise($sSpec);
		foreach ($this->Calls($sMethod) as $sCall) {
			if (strtolower($sCall) === $sWanted) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The methods of the file that contain this call, by name, in source order.
	 * For a rule that binds every place a call is made: each of them must also
	 * do something else, or do it first.
	 *
	 * @return array<int, string>
	 */
	public function MethodsCalling(string $sSpec): array
	{
		$sWanted = self::normalise($sSpec);
		$aMethods = [];
		foreach ($this->aMethods as $aMethod) {
			for ($i = $aMethod['from']; $i < $aMethod['to']; $i++) {
				$sCall = $this->callAt($i, $aMethod['class'], $aMethod['parent']);
				if ($sCall !== null && strtolower($sCall) === $sWanted) {
					$aMethods[] = $aMethod['name'];
					break;
				}
			}
		}

		return $aMethods;
	}

	/**
	 * Is there a static call, of any method, on this class?
	 */
	public function HasCallOn(string $sClass, ?string $sMethod = null): bool
	{
		$sPrefix = strtolower(ltrim($sClass, '\\')).'::';
		foreach ($this->Calls($sMethod) as $sCall) {
			if (str_starts_with(strtolower($sCall), $sPrefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is there a call to a method of this name, static on any class or on any
	 * object? For a gate that is reached through `self::checkBulkAllowed(` in one
	 * tool and `static::` or `$this->` in another.
	 */
	public function HasCallTo(string $sName, ?string $sMethod = null): bool
	{
		foreach ($this->Calls($sMethod) as $sCall) {
			if (preg_match('/(?:::|->)'.preg_quote($sName, '/').'$/i', $sCall) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is each call there, and does the first of each come after the first of the
	 * one before? A call that is absent fails the question.
	 *
	 * @param array<int, string> $aSpecs
	 */
	public function CallsInOrder(array $aSpecs, ?string $sMethod = null): bool
	{
		return self::InOrder($this->Calls($sMethod), $aSpecs);
	}

	/**
	 * Do these calls occur in this order somewhere, each after the one before,
	 * not necessarily at its first occurrence? For a check that is repeated, the
	 * one that matters being the one after a given call; CallsInOrder() is the
	 * stricter question and the right one for "this gate before that action".
	 *
	 * @param array<int, string> $aSpecs
	 */
	public function CallsInSequence(array $aSpecs, ?string $sMethod = null): bool
	{
		$aCalls = array_map('strtolower', $this->Uses($sMethod));
		$iAt = 0;
		foreach ($aSpecs as $sSpec) {
			$iFound = array_search(self::normalise($sSpec), array_slice($aCalls, $iAt, null, true), true);
			if ($iFound === false) {
				return false;
			}
			$iAt = $iFound + 1;
		}

		return true;
	}

	/**
	 * The same question asked of calls gathered from several methods or files,
	 * concatenated in the order they run.
	 *
	 * @param array<int, string> $aCalls
	 * @param array<int, string> $aSpecs
	 */
	public static function InOrder(array $aCalls, array $aSpecs): bool
	{
		$aLowered = array_map('strtolower', $aCalls);
		$iAfter = -1;
		foreach ($aSpecs as $sSpec) {
			$iAt = array_search(self::normalise($sSpec), $aLowered, true);
			if ($iAt === false || $iAt <= $iAfter) {
				return false;
			}
			$iAfter = $iAt;
		}

		return true;
	}

	/**
	 * Constants read in code: `Fully\Qualified\Class::CONST` for a class constant,
	 * `UR_ACTION_READ` for a global one. `X::class` is not a constant.
	 *
	 * @return array<int, string>
	 */
	public function Constants(?string $sMethod = null): array
	{
		$aConstants = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sSpec = $this->constantAt($i, $sClass, $sParent);
				if ($sSpec !== null) {
					$aConstants[] = $sSpec;
				}
			}
		}

		return $aConstants;
	}

	/**
	 * Calls and constants together, in source order: the specs of Calls() and of
	 * Constants() for the question "does this check come before that value is
	 * used" when one is a call and the other a constant.
	 *
	 * @return array<int, string>
	 */
	public function Uses(?string $sMethod = null): array
	{
		$aUses = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sSpec = $this->callAt($i, $sClass, $sParent) ?? $this->constantAt($i, $sClass, $sParent);
				if ($sSpec !== null) {
					$aUses[] = $sSpec;
				}
			}
		}

		return $aUses;
	}

	/**
	 * The calls and constants inside the `catch` blocks of the method, in order:
	 * what the error path does.
	 *
	 * @return array<int, string>
	 */
	public function UsesInCatch(?string $sMethod = null): array
	{
		$aUses = [];
		foreach ($this->catchBlocks($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sSpec = $this->callAt($i, $sClass, $sParent) ?? $this->constantAt($i, $sClass, $sParent);
				if ($sSpec !== null) {
					$aUses[] = $sSpec;
				}
			}
		}

		return $aUses;
	}

	/**
	 * The string literals inside the `catch` blocks of the method.
	 *
	 * @return array<int, string>
	 */
	public function StringsInCatch(?string $sMethod = null): array
	{
		$aStrings = [];
		foreach ($this->catchBlocks($sMethod) as [$iFrom, $iTo]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				if ($this->aTokens[$i]->id === T_CONSTANT_ENCAPSED_STRING) {
					$aStrings[] = substr($this->aTokens[$i]->text, 1, -1);
				}
			}
		}

		return $aStrings;
	}

	/**
	 * The code of the `catch` blocks of the method, as Code() writes it.
	 */
	public function CodeInCatch(?string $sMethod = null): string
	{
		$sCode = '';
		foreach ($this->catchBlocks($sMethod) as [$iFrom, $iTo]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sCode .= $this->aTokens[$i]->text;
			}
		}

		return $sCode;
	}

	/**
	 * The classes the method (or file) catches, resolved: `catch (\Throwable $e)`
	 * and `catch (Throwable $e)` with `use Throwable;` are both `Throwable`;
	 * without the import the second is `Namespace\Throwable`, which is what PHP
	 * would catch too. Several types in one catch are each listed.
	 *
	 * @return array<int, string>
	 */
	public function CatchTypes(?string $sMethod = null): array
	{
		$aTypes = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				if ($this->aTokens[$i]->id !== T_CATCH) {
					continue;
				}
				for ($j = $i + 2; $j < $iTo && $this->aTokens[$j]->id !== T_VARIABLE && $this->aTokens[$j]->text !== ')'; $j++) {
					if (in_array($this->aTokens[$j]->id, self::NAME_TOKENS, true)) {
						$aTypes[] = $this->resolveClass($this->aTokens[$j]->text, $sClass, $sParent);
					}
				}
			}
		}

		return $aTypes;
	}

	/**
	 * @return array<int, array{0: int, 1: int, 2: string, 3: string}> the token ranges of the catch bodies
	 */
	private function catchBlocks(?string $sMethod): array
	{
		$aBlocks = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo, $sClass, $sParent]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				if ($this->aTokens[$i]->id !== T_CATCH) {
					continue;
				}
				while ($i < $iTo && $this->aTokens[$i]->text !== '{') {
					$i++;
				}
				$iStart = $i;
				$iBraces = 0;
				for (; $i < $iTo; $i++) {
					if ($this->aTokens[$i]->text === '{' || $this->aTokens[$i]->id === T_CURLY_OPEN) {
						$iBraces++;
					} elseif ($this->aTokens[$i]->text === '}' && --$iBraces === 0) {
						break;
					}
				}
				$aBlocks[] = [$iStart, $i, $sClass, $sParent];
			}
		}

		return $aBlocks;
	}

	/**
	 * CallsInOrder() over calls and constants.
	 *
	 * @param array<int, string> $aSpecs
	 */
	public function UsesInOrder(array $aSpecs, ?string $sMethod = null): bool
	{
		return self::InOrder($this->Uses($sMethod), $aSpecs);
	}

	private function constantAt(int $i, string $sClass, string $sParent): ?string
	{
		if ($sClass === '') {
			[$sClass, $sParent] = $this->classAt($i);
		}
		$oToken = $this->aTokens[$i];
		if (!in_array($oToken->id, self::NAME_TOKENS, true) || $this->precededBy($i, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS, T_INSTANCEOF, T_NAMESPACE, T_CONST])) {
			return null;
		}
		$oNext = $this->aTokens[$i + 1] ?? null;
		if ($oNext !== null && $oNext->id === T_DOUBLE_COLON) {
			$oMember = $this->aTokens[$i + 2] ?? null;
			$oAfter = $this->aTokens[$i + 3] ?? null;
			if ($oMember !== null && $oMember->id === T_STRING && strtolower($oMember->text) !== 'class' && ($oAfter === null || $oAfter->text !== '(')) {
				return $this->resolveClass($oToken->text, $sClass, $sParent).'::'.$oMember->text;
			}

			return null;
		}
		if ($oToken->id === T_STRING
			&& ($oNext === null || !in_array($oNext->text, ['(', '\\'], true) && $oNext->id !== T_VARIABLE)
			&& !in_array(strtolower($oToken->text), ['true', 'false', 'null'], true)
			&& !$this->isDeclarationName($i)) {
			return $oToken->text;
		}

		return null;
	}

	public function HasConstant(string $sSpec, ?string $sMethod = null): bool
	{
		$sWanted = $this->normalise($sSpec);
		foreach ($this->Constants($sMethod) as $sConstant) {
			if (strtolower($sConstant) === $sWanted) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The contents of the string literals in code, quotes removed. Comments do
	 * not appear; a docblock that quotes a string does not count.
	 *
	 * @return array<int, string>
	 */
	public function Strings(?string $sMethod = null): array
	{
		$aStrings = [];
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$oToken = $this->aTokens[$i];
				if ($oToken->id === T_CONSTANT_ENCAPSED_STRING) {
					$aStrings[] = substr($oToken->text, 1, -1);
				} elseif ($oToken->id === T_ENCAPSED_AND_WHITESPACE) {
					$aStrings[] = $oToken->text;
				}
			}
		}

		return $aStrings;
	}

	public function HasString(string $sString, ?string $sMethod = null): bool
	{
		return in_array($sString, $this->Strings($sMethod), true);
	}

	/**
	 * Does any string literal in code contain this text? For a sentence a tool
	 * says to its caller: it is in a literal, so a comment that quotes it does
	 * not hold it up, and it may be one piece of a longer message.
	 */
	public function HasStringContaining(string $sText, ?string $sMethod = null): bool
	{
		foreach ($this->Strings($sMethod) as $sString) {
			if (str_contains($sString, $sText)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The code of the method or file as token text with the comments and the
	 * whitespace gone, for the few questions about shape that are not a call
	 * (`is_a($x,$y,true)`). Strings are kept: this is code, not a list of calls.
	 */
	public function Code(?string $sMethod = null): string
	{
		$sCode = '';
		foreach ($this->scopes($sMethod) as [$iFrom, $iTo]) {
			for ($i = $iFrom; $i < $iTo; $i++) {
				$sCode .= $this->aTokens[$i]->text;
			}
		}

		return $sCode;
	}

	// ------------------------------------------------------------------

	private function index(): void
	{
		$iCount = count($this->aTokens);
		$aClassStack = [];   // [fqcn, parent, brace depth at which its body closes]
		$iDepth = 0;
		$bPendingClass = null;

		for ($i = 0; $i < $iCount; $i++) {
			$oToken = $this->aTokens[$i];

			if ($oToken->id === T_NAMESPACE && !$this->precededBy($i, [T_DOUBLE_COLON, T_OBJECT_OPERATOR]) && ($this->aTokens[$i + 1]->id ?? 0) !== T_NS_SEPARATOR) {
				$sName = '';
				for ($j = $i + 1; $j < $iCount && !in_array($this->aTokens[$j]->text, [';', '{'], true); $j++) {
					$sName .= $this->aTokens[$j]->text;
				}
				$this->sNamespace = trim($sName, '\\');
				continue;
			}

			if ($oToken->id === T_USE && $iDepth === 0 && $aClassStack === []) {
				$i = $this->readUse($i);
				continue;
			}

			if (in_array($oToken->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
				&& !$this->precededBy($i, [T_DOUBLE_COLON, T_NEW])
				&& isset($this->aTokens[$i + 1]) && $this->aTokens[$i + 1]->id === T_STRING) {
				$sName = $this->aTokens[$i + 1]->text;
				$sParent = '';
				$j = $i + 2;
				if (isset($this->aTokens[$j]) && $this->aTokens[$j]->id === T_EXTENDS) {
					$sParent = $this->aTokens[$j + 1]->text;
				}
				$sFqcn = ($this->sNamespace !== '' ? $this->sNamespace.'\\' : '').$sName;
				$bPendingClass = [$sFqcn, $sParent !== '' ? $this->resolveClass($sParent, $sFqcn, '') : ''];
				continue;
			}

			if ($oToken->text === '{' || $oToken->id === T_CURLY_OPEN || $oToken->id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$iDepth++;
				if ($bPendingClass !== null) {
					$aClassStack[] = [$bPendingClass[0], $bPendingClass[1], $iDepth, $i];
					$bPendingClass = null;
				}
				continue;
			}

			if ($oToken->text === '}') {
				if ($aClassStack !== [] && end($aClassStack)[2] === $iDepth) {
					$aClosed = array_pop($aClassStack);
					$this->aClassRanges[] = [$aClosed[0], $aClosed[1], $aClosed[3], $i];
				}
				$iDepth--;
				continue;
			}

			if ($oToken->id === T_FUNCTION && $aClassStack !== []) {
				$iName = $i + 1;
				if (($this->aTokens[$iName]->text ?? '') === '&') {
					$iName++;
				}
				if (($this->aTokens[$iName]->id ?? 0) !== T_STRING) {
					continue; // a closure
				}
				$aClass = end($aClassStack);
				// Past the signature to the body's brace, or to the `;` of an abstract one.
				$iParens = 0;
				$j = $iName + 1;
				for (; $j < $iCount; $j++) {
					$sText = $this->aTokens[$j]->text;
					if ($sText === '(') {
						$iParens++;
					} elseif ($sText === ')') {
						$iParens--;
					} elseif ($iParens === 0 && ($sText === '{' || $sText === ';')) {
						break;
					}
				}
				if (($this->aTokens[$j]->text ?? '') !== '{') {
					continue;
				}
				$iBraces = 0;
				$k = $j;
				for (; $k < $iCount; $k++) {
					$sText = $this->aTokens[$k]->text;
					if ($sText === '{' || $this->aTokens[$k]->id === T_CURLY_OPEN || $this->aTokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
						$iBraces++;
					} elseif ($sText === '}') {
						$iBraces--;
						if ($iBraces === 0) {
							break;
						}
					}
				}
				$this->aMethods[] = [
					'class' => $aClass[0],
					'parent' => $aClass[1],
					'name' => $this->aTokens[$iName]->text,
					'from' => $j + 1,
					'to' => $k,
				];
			}
		}
	}

	/**
	 * Reads one top-level `use` statement and returns the index of its `;`.
	 */
	private function readUse(int $i): int
	{
		$iCount = count($this->aTokens);
		$j = $i + 1;
		$sKind = 'class';
		if (isset($this->aTokens[$j]) && in_array($this->aTokens[$j]->id, [T_FUNCTION, T_CONST], true)) {
			$sKind = 'other';
			$j++;
		}

		$sPrefix = '';
		$aEntries = [];
		$sCurrent = '';
		$sAlias = null;
		$bAs = false;
		$bGroup = false;
		for (; $j < $iCount; $j++) {
			$oToken = $this->aTokens[$j];
			if ($oToken->text === ';') {
				break;
			}
			if ($oToken->text === '{') {
				$sPrefix = $sCurrent;
				$sCurrent = '';
				$bGroup = true;
			} elseif ($oToken->text === ',' || $oToken->text === '}') {
				if ($sCurrent !== '') {
					$aEntries[] = [$sPrefix.$sCurrent, $sAlias];
				}
				$sCurrent = '';
				$sAlias = null;
				$bAs = false;
			} elseif ($oToken->id === T_AS) {
				$bAs = true;
			} elseif ($bAs) {
				$sAlias = $oToken->text;
			} else {
				$sCurrent .= $oToken->text;
			}
		}
		if ($sCurrent !== '') {
			$aEntries[] = [$sPrefix.$sCurrent, $sAlias];
		}
		unset($bGroup);

		if ($sKind === 'class') {
			foreach ($aEntries as [$sName, $sAs]) {
				$sName = ltrim($sName, '\\');
				$sShort = $sAs ?? substr((string)strrchr('\\'.$sName, '\\'), 1);
				$this->aImports[strtolower($sShort)] = [$sShort, $sName];
			}
		}

		return $j;
	}

	/**
	 * @return array<int, array{0: int, 1: int, 2: string, 3: string}> from, to, class, parent
	 */
	private function scopes(?string $sMethod): array
	{
		if ($sMethod === null) {
			return [[0, count($this->aTokens), '', '']];
		}

		return $this->bodies($sMethod);
	}

	/**
	 * @return array<int, array{0: int, 1: int, 2: string, 3: string}>
	 */
	private function bodies(string $sMethod): array
	{
		$aBodies = [];
		foreach ($this->aMethods as $aMethod) {
			if (strtolower($aMethod['name']) === strtolower($sMethod)) {
				$aBodies[] = [$aMethod['from'], $aMethod['to'], $aMethod['class'], $aMethod['parent']];
			}
		}
		if ($aBodies === []) {
			throw new \LogicException("SourceCalls: {$this->sFile} declares no method {$sMethod}()");
		}

		return $aBodies;
	}

	private function callAt(int $i, string $sClass, string $sParent): ?string
	{
		if ($sClass === '') {
			[$sClass, $sParent] = $this->classAt($i);
		}
		$oToken = $this->aTokens[$i];
		$oNext = $this->aTokens[$i + 1] ?? null;
		if ($oNext === null) {
			return null;
		}

		// ->method(
		if (in_array($oToken->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
			if ($oNext->id === T_STRING && ($this->aTokens[$i + 2]->text ?? '') === '(') {
				return '->'.$oNext->text;
			}

			return null;
		}

		if (!in_array($oToken->id, self::NAME_TOKENS, true) && $oToken->id !== T_STATIC) {
			return null;
		}
		if ($this->precededBy($i, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS, T_NAMESPACE, T_INSTANCEOF])) {
			return null;
		}

		// Class::method(
		if ($oNext->id === T_DOUBLE_COLON) {
			$oMember = $this->aTokens[$i + 2] ?? null;
			if ($oMember !== null && $oMember->id === T_STRING && ($this->aTokens[$i + 3]->text ?? '') === '(') {
				return $this->resolveClass($oToken->text, $sClass, $sParent).'::'.$oMember->text;
			}

			return null;
		}

		// function(
		if ($oToken->id !== T_STATIC && $oNext->text === '(') {
			return ltrim($oToken->text, '\\');
		}

		return null;
	}

	/**
	 * The class whose body holds this token, for a question asked of the whole file.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function classAt(int $i): array
	{
		foreach ($this->aClassRanges as [$sClass, $sParent, $iFrom, $iTo]) {
			if ($i >= $iFrom && $i <= $iTo) {
				return [$sClass, $sParent];
			}
		}

		return ['', ''];
	}

	private function resolveClass(string $sName, string $sClass, string $sParent): string
	{
		$sLower = strtolower($sName);
		if ($sLower === 'self' || $sLower === 'static') {
			return $sClass;
		}
		if ($sLower === 'parent') {
			return $sParent;
		}
		if ($sName[0] === '\\') {
			return substr($sName, 1);
		}
		if (str_starts_with($sLower, 'namespace\\')) {
			return ltrim($this->sNamespace.'\\'.substr($sName, 10), '\\');
		}

		$iSlash = strpos($sName, '\\');
		$sFirst = strtolower($iSlash === false ? $sName : substr($sName, 0, $iSlash));
		if (isset($this->aImports[$sFirst])) {
			return $this->aImports[$sFirst][1].($iSlash === false ? '' : substr($sName, $iSlash));
		}

		return ltrim($this->sNamespace.'\\'.$sName, '\\');
	}

	/**
	 * @param array<int, int> $aIds
	 */
	private function precededBy(int $i, array $aIds): bool
	{
		return $i > 0 && in_array($this->aTokens[$i - 1]->id, $aIds, true);
	}

	/** A name that is being declared (`const X`, `function f`, a parameter type is not). */
	private function isDeclarationName(int $i): bool
	{
		return $this->precededBy($i, [T_CONST, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_USE, T_EXTENDS, T_IMPLEMENTS, T_NEW, T_INSTANCEOF, T_CASE]);
	}

	private static function normalise(string $sSpec): string
	{
		return strtolower(ltrim($sSpec, '\\'));
	}
}
