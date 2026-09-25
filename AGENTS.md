# AGENTS.md — working in this repository as an assistant

For any AI assistant, whichever harness it runs in — Claude Code, Codex, Cursor, Copilot,
Gemini, Cline, Aider, a DeepSeek or local model behind one of those, or a person pasting
context into a chat window. Several tools load this file by name without being told to, which
is why the repository brief lives here rather than under a vendor's filename; `CLAUDE.md`
imports it for the one tool that looks elsewhere.

**It is deliberately thin.** The development guide's §0 forbids a second copy of anything the
repository already states — a duplicate is a future contradiction with nobody assigned to
notice it — so almost everything below is a pointer rather than a summary. Follow the pointers.

## 1. Read the development guide before the first edit

[`doc/itop-extension-guide.md`](doc/itop-extension-guide.md), §0 ("Repository context — MUST
read first") and "Using this guide" (rules 1–8). It covers what an iTop extension may and may
not do, it sets the precedence order between the repository's own files, and it names which of
them to read before writing anything.

**It is the generic guide** — the same document in every repository that adopts it, knowing
nothing about this one, which is why it is not the file you are reading. Everything it says that
could expire is in [`doc/itop-branch-notes.md`](doc/itop-branch-notes.md) instead, dated.

## 2. The guide governs documentation-only changes

Its §0 precondition for writing **any** document into this repository is to list `doc/` and
`.github/` first and confirm you are not duplicating one that is already there. A task that
touches no PHP is still in scope, and `CONTRIBUTING.md` says so under "The rules every change is
written to".

This file exists because that was got wrong: a code-of-conduct task was judged out of scope for
the guide without the guide being opened, so the `doc/` listing never happened and a pull request
template went in restating `CONTRIBUTING.md` instead of linking it. Do not repeat it.

## 3. Disclose yourself in the pull request

Required, not optional, and `CONTRIBUTING.md` under "AI-assisted contributions" says what comes
with the disclosure. The commit trailer it specifies is load-bearing rather than ceremonial —
the same file explains why under "How this extension is built". Never omit or rewrite it.

## 4. Two things about this tree that nothing else states

- **`vendor/` is untracked and can lag `composer.lock`.** A red unit suite is a stale vendor
  tree before it is a bug. Compare `vendor/composer/installed.json` against the lock and run
  `composer install` before believing a failure: `mcp/sdk` sat a minor behind once and produced
  two failures that read as protocol bugs.
- **The PHP you are running may be outside the declared range.** `composer.json` declares it and
  `config.platform.php` pins resolution to the floor, so composer will not warn you. Say which
  PHP a result came from, and use the Docker runner `CONTRIBUTING.md` names for the real range.

## 5. If your harness has its own instruction file

Add a pointer to this file, never a copy. As of writing: Claude Code reads `CLAUDE.md` (present,
and a one-line import of this file), Gemini CLI `GEMINI.md`, GitHub Copilot
`.github/copilot-instructions.md`, Cursor `.cursor/rules/`, Cline `.clinerules`, Windsurf
`.windsurfrules`. Check your own tool's documentation rather than trusting that list — it moves.
DeepSeek has no convention of its own; what applies is whatever harness runs it.

## 6. What ships and what does not

Neither this file nor `CLAUDE.md` is in the release archive, and neither is the guide. The guide
is kept out for two reasons, `exclude.txt` says which; these two for one of them only. They
describe how this repository is worked on, and an administrator unpacking the archive in a
maintenance window has no use for instructions addressed to whoever edits it.
