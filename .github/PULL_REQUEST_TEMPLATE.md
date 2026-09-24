<!-- The boxes are the review, written down. An unticked one with a sentence saying why is a
     fine answer; a ticked one that is not true is the only wrong answer. Delete nothing. -->

## What changed, and why

<!-- The why is the half a diff cannot show. If an operator or a tool pack can see this
     change, say so here in the words they would use. -->

Closes #

## Surface

- [ ] **Nothing on the public surface moved** — no tool, resource or prompt identifier, no class
      marked `@api`, no module parameter default. If one did, it is named above, this is a
      breaking change, and the [CHANGELOG.md](https://github.com/AltiooBV/altioo-mcp/blob/main/CHANGELOG.md) entry says what an administrator
      has to do about it.
- [ ] **The changelog entry is in this pull request**, if anything an operator or a tool pack can
      see changed. It is what someone reads before scheduling a maintenance window, and one
      written later is one written from a diff.
- [ ] Documentation that stated the old behaviour was corrected in the same change — README rows,
      `doc/`, the dictionaries, and `AGENTS.md` where this revealed the guide was wrong.

## Checks

- [ ] `composer lint` is clean, and any reformat is a commit of its own rather than mixed into a
      logic change.
- [ ] `composer test:unit` passes. It needs neither iTop nor a database.
- [ ] `tools/ci/local/run.sh unit` was run before opening this, so CI is not the first to know.
- [ ] `composer test:integration` was run against a live instance — **required if this touches
      `MetaModel`, `UserRights` or the request pipeline.** Say which iTop patch and PHP version
      below. Without iTop's `ItopDataTestCase` those tests skip, which reads as green.
- [ ] New behaviour comes with a test; a fix comes with the test that would have caught it.
- [ ] Every new file carries the AGPL-3.0-or-later header.

Ran the integration suite on: <!-- e.g. iTop 3.2.2-1-17851, PHP 8.2 — or "not applicable" -->

## Commits

- [ ] Explicit paths were staged (`git commit -- path`), not `-a` or `add -A`, and
      `git diff --cached --stat` was read before each one.
- [ ] Nothing the setup generates is in here — no `env-production/`, no `conf/`.
- [ ] `git status` is clean, or what was left out is named above with the reason.
- [ ] Every commit a person or a model wrote carries the `Co-Authored-By:` trailer.

## AI assistance

<!-- Required, and one line is enough: which tool, and what it wrote. Welcome rather than
     tolerated - this whole extension was built this way, and CONTRIBUTING.md says so. What
     comes with the disclosure is that you take responsibility for the code as if you had typed
     it, you can explain it, and you have the right to submit it. -->

- [ ] No AI tool was involved, **or** the line above says which one was and what it wrote.
