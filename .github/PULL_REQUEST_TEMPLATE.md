<!-- Each box names a requirement and links where it is written. Nothing here restates the rule,
     so this file cannot drift out of agreement with CONTRIBUTING.md - read the link if a box is
     not obvious. An unticked box with a sentence saying why is a fine answer; a ticked one that
     is not true is the only wrong answer. Delete nothing. -->

## What changed, and why

<!-- The why is the half a diff cannot show. Name anything an operator or a tool pack can see. -->

Closes #

## Surface

- [ ] No identifier, `@api` class or module parameter default moved — or it did, it is named
      above, and the migration is in the entry below.
      → [what counts as breaking](https://github.com/AltiooBV/altioo-mcp/blob/main/CHANGELOG.md)
- [ ] The [CHANGELOG.md](https://github.com/AltiooBV/altioo-mcp/blob/main/CHANGELOG.md) entry is in this pull request, if anything visible
      changed. → [why it is not written later](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#getting-a-change-in)
- [ ] Documentation that stated the old behaviour was corrected here too, the development guide
      included where this revealed it was wrong.
      → [§12.5](https://github.com/AltiooBV/altioo-mcp/blob/main/doc/itop-extension-guide.md)

## Checks

- [ ] `composer lint` clean, any reformat in a commit of its own.
      → [Before a pull request](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#before-a-pull-request)
- [ ] `composer test:unit` passes, and `tools/ci/local/run.sh unit` was run before opening this.
      → [the commands](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#before-a-pull-request)
- [ ] `composer test:integration` run against a live instance if this touches `MetaModel`,
      `UserRights` or the request pipeline — and the versions named below, because without iTop's
      `ItopDataTestCase` these skip and a skip reads as green.
      → [what the suites need](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#before-a-pull-request)
- [ ] A test comes with it. → [new behaviour, and fixes](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#before-a-pull-request)
- [ ] Every new file carries the licence header. → [Licence](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#licence)

Ran the integration suite on: <!-- iTop patch + PHP version, or "not applicable" -->

## Commits

- [ ] Explicit pathspecs staged, `git diff --cached --stat` read, nothing the setup generates
      included, `git status` clean or the remainder named above.
      → [Commits](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#commits)
- [ ] The trailer is on every commit a person or a model wrote.
      → [why it is load-bearing](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#how-this-extension-is-built)

## AI assistance

<!-- One line: which tool, and what it wrote. Welcome rather than tolerated. -->

- [ ] No AI tool was involved, **or** the line above says which one and what it wrote.
      → [what comes with the disclosure](https://github.com/AltiooBV/altioo-mcp/blob/main/CONTRIBUTING.md#ai-assisted-contributions)
