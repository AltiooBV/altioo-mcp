# Checking what an upgrade does to an instance that already exists

The [iTop matrix](ci-itop-matrix.md) installs this module into an empty database. That run
cannot lose anything, because there is nothing there to lose. The question it leaves
unanswered is the one every client past their first install has: **what happens to the
instance I already have.**

[`upgrade.yml`](../.github/workflows/upgrade.yml) answers it by doing what they do — install
an older version, use it, drop the new files over it, re-run the setup — and then looking for
what should still be there.

## Why an upgrade needs its own test

A module upgrade in iTop is a recompilation. The datamodel is rebuilt from the XML and the
schema is altered to match, and **that is a destructive operation with no confirmation step**:
an attribute that stopped being declared takes its column with it, a renamed class leaves its
table behind and starts an empty one. The setup reports success either way, because from where
it stands both outcomes are correct — it cannot tell an attribute removed on purpose from one
removed by accident.

Neither can CI, unless something was put into the instance first and looked for afterwards.
That is all the fixture is.

## The three runs

| Baseline | Why |
|---|---|
| The **oldest** release tag | The skip-version path. A client going 1.0 → 3.0 calls the installer **once**, with `1.0.0` — the jump nobody tests and everybody eventually makes |
| The **newest** release tag | The ordinary one-step upgrade |
| The **same version, twice** | A failing setup aborts half way; the administrator fixes the cause and re-runs. Every migration therefore executes twice, so the second run has to pass as cleanly as the first |

The third is not a separate job: every baseline runs `upgrade-module.sh` twice and verifies the
fixture after each.

Only the third is exercisable today. The first two need a release tag and there is none yet, so
until `v1.0.0` exists the table describes what these runs will do rather than what they do —
see [Running it](#running-it) below for the hand-driven form.

## What is seeded, and what each thing catches

[`upgrade-fixture.php`](../tools/ci/upgrade-fixture.php), before the upgrade:

| Fixture | What its loss would mean |
|---|---|
| Three `AltiooEventMCPService` rows | The audit trail did not survive the schema alter |
| One value with an accent and an apostrophe, one at the 255-character limit | A column recreated with a different charset or length; a value that went through unescaped SQL |
| The class's list of attribute codes | An attribute was removed between the two versions — reported **by name**, because "some data is missing" is not an actionable failure |
| A `PersonalToken` with the `MCP` scope | The scope this module adds to a *core* class by delta stopped being an allowed value, so every client's token silently lost its access |
| `mcp_max_document_bytes` set away from its default | The administrator's tuning reverted. iTop preserves module settings on upgrade **only** when the setup is handed the previous configuration file (`DoCreateConfig`, the `upgrade` branch) — get that wrong and every client's configuration goes back to defaults, with no error anywhere |

## What the upgrade script asserts

[`upgrade-module.sh`](../tools/ci/upgrade-module.sh), after the setup:

- the setup exited `0` and printed `installed!` — necessary, and on its own worth nothing
  (see the same point in [ci-itop-matrix.md](ci-itop-matrix.md));
- `priv_module_install` now reads the working copy's version;
- **it holds at least two rows** for the module. An upgrade adds to that history; if the older
  row is gone, the instance was reinstalled rather than upgraded — which would also explain any
  fixture data that survived, and would quietly turn this whole workflow into a second
  fresh-install test;
- `priv_extension_install` still says `source = extensions`;
- `env-production/altioo-mcp/` exists and its `extension.xml` agrees with the database.

Three details of the setup call matter enough to name:

- **no `--clean`** — that flag drops the database, which is the data under test;
- **`<previous_configuration_file>`** — without it, module settings are not preserved;
- **`<sample_data>0</sample_data>`** — the demo data was loaded at install; an upgrade does not
  load it again.

And the module files are copied over the old ones **without `--delete`**, on purpose: that is
what a client unzipping into `extensions/` does, leftovers and all.

## Running it

This workflow has never run on GitHub — a pull request does not trigger it. See
[ci-itop-matrix.md](ci-itop-matrix.md#status-two-of-the-four-workflows-have-run-on-github),
which records which of the four have.

There are no release tags yet, so the scheduled and push runs have no baseline and the jobs
**skip** — visibly, with a summary line saying why, rather than passing green on nothing.

Until the first tag, drive it by hand from any earlier commit:

```
gh workflow run upgrade.yml -f baseline_ref=<sha-or-branch>
```

Locally, against a MariaDB of your own — the same two scripts CI calls:

```bash
composer install --no-dev --optimize-autoloader

# the baseline: an older checkout, installed the ordinary way
git worktree add /tmp/baseline <older-ref>
composer install --no-dev --optimize-autoloader -d /tmp/baseline
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=3.2) \
  ITOP_TAG=3.2.3-2 ITOP_DIR=/tmp/itop MODULE_SRC=/tmp/baseline DB_PWD=root \
  tools/ci/install-itop.sh

# use the instance, upgrade it, look for what you left there
ITOP_DIR=/tmp/itop DB_PWD=root php tools/ci/upgrade-fixture.php /tmp/itop seed
ITOP_DIR=/tmp/itop DB_PWD=root tools/ci/upgrade-module.sh
ITOP_DIR=/tmp/itop DB_PWD=root php tools/ci/upgrade-fixture.php /tmp/itop verify
```

`upgrade-module.sh` refuses to run against a tree that is not an installed iTop, and against an
instance where this module is not installed at all — both are the same mistake, made early.

## What this still does not check

- **A client's own data.** The fixture is what this module writes plus one core object. An
  instance with 200 000 tickets and eleven other extensions is a different upgrade.
- **Downgrade.** Unsupported, stated as such in the README. Nothing tests it because the
  answer is "restore the backup".
- **The browser.** That the console still shows the profile, the module's parameters and the
  audit rows after an upgrade is a step in the [release checklist](release-checklist.md), not a
  job here. There is no menu to look for: this module declares none.
