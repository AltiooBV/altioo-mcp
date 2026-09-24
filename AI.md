# Working in this repository as an assistant

For any AI assistant, whichever harness it runs in — Claude Code, Codex, Cursor, Copilot,
Gemini, Cline, Aider, a DeepSeek or local model behind one of those, or a person pasting
context into a chat window. `CLAUDE.md` is Claude Code's entry point and imports this file;
this is the one to read otherwise.

**This file is deliberately thin.** `AGENTS.md` §0 forbids a second copy of anything the
repository already states — a duplicate is a future contradiction with nobody assigned to
notice it — so almost everything below is a pointer rather than a summary. Follow the pointers.

## 1. Read `AGENTS.md` before the first edit

§0 ("Repository context — MUST read first") and "Using this guide" (rules 1–8). It is the
development guide this repository follows, it sets its own precedence order, and it names the
files to read before writing anything. **No tool loads it automatically** — this file and
`CLAUDE.md` are the only things that will put it in front of you.

## 2. It governs documentation-only changes

§0's precondition for writing **any** document into this repository is to list `doc/` and
`.github/` first and confirm you are not duplicating one that is already there.
`CONTRIBUTING.md` introduces the guide as "the rules the code is written to", which reads
narrower than the scope is. The scope is every change.

This file exists because of that mistake: a code-of-conduct task was judged out of scope for
the guide without the guide being opened, so the `doc/` listing never happened and a pull
request template went in restating `CONTRIBUTING.md` instead of linking it. Do not repeat it.

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

Add a pointer to this file, never a copy. As of writing: Claude Code reads `CLAUDE.md` (present),
Gemini CLI `GEMINI.md`, GitHub Copilot `.github/copilot-instructions.md`, Cursor `.cursor/rules/`,
Cline `.clinerules`, Windsurf `.windsurfrules`; Codex and several others read `AGENTS.md`, which
here is the generic guide rather than a repository brief. Check your own tool's documentation
rather than trusting that list — it moves. DeepSeek has no convention of its own; what applies is
whatever harness runs it.

## 6. Neither this file nor `CLAUDE.md` ships

Both are in `exclude.txt`. They describe how this repository is worked on, which an installed
copy on a customer instance has no use for — the same reason `AGENTS.md` is kept out, minus the
licence half of it.
