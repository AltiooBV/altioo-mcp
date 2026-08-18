# Checking the extension against real iTop installations

Unit tests answer "is this code right about itself". They cannot answer the question an
administrator actually has, which is "will this install on my iTop and still work". That one
is only answered by installing it — and by installing it on every version the listing claims,
because the version that breaks is rarely the one being developed against.

[`itop-matrix.yml`](../.github/workflows/itop-matrix.yml) does that on every pull request, and
on a schedule, which matters more: nothing in this repository changes when Combodo publishes
3.2.4, and that is exactly when the claim on the Hub listing quietly stops being true.

## What is checked, and why each one is separate

| Step | What it would catch |
|---|---|
| A dry run (`--install=0`) selects `altioo-mcp` | The setup **silently** dropping the extension — an unsatisfiable dependency does not fail a setup, it removes a checkbox. This one runs as its own job, first, with no database at all |
| iTop's unattended setup completes | A module declaration that no longer parses, an XML delta the compiler rejects |
| `--check-consistency=1` | A datamodel this module contributes to that compiles but is not coherent |
| A row in `priv_module_install`, at this version | The setup completing without installing the module, or installing a stale copy left in `extensions/` by an earlier run |
| A row in `priv_extension_install`, `source = extensions` | The module arriving by some route other than the one a user's install takes |
| `env-production/altioo-mcp` exists | Recorded as installed, but not compiled to where iTop loads it from |
| iTop's `ModuleIntegration` suite | Dictionary entries that do not resolve in the compiled environment. Combodo's test, run against our module |
| The module's own `Integration` suite | Everything that needs a live `MetaModel`, `UserRights` and a database |
| `itop-smoke.php` | Module settings, the audit class, the token scopes and the profile — the pieces the security model is made of, checked in the instance rather than in a fixture |
| `http-smoke.sh` | The endpoint over the wire: refused without a credential, `initialize` and `tools/list` with one |

Everything after the first two rows exists because **"the setup succeeded" and "the module was
installed" are different statements**. A setup that cannot select an extension logs one line
about it, exits `0`, and prints `installed!` exactly like a run that installed everything.

The verdict is taken from `priv_module_install` and `priv_extension_install` rather than from
the setup's output. Those are the tables iTop itself consults to answer "what is installed
here", they are written only for modules that were, and their columns have not moved — whereas
the class that writes them moved from `setup/moduleinstallation.class.inc.php` to
`setup/moduleinstallation/` between 3.2 and 3.3, which is exactly the kind of change that
quietly turns a log-scraping check into a check of nothing. The setup log is still read, but
only on the failure path, to say *why*: it is the one place that names the modules which made
the extension unselectable.

## Which versions

[`.github/itop-support.json`](../.github/itop-support.json) declares **branches**, never
patches:

```json
{ "branch": "3.2", "php": ["8.2", "8.4"] }
```

[`resolve-itop-versions.php`](../tools/ci/resolve-itop-versions.php) turns each branch into the
newest patch of that branch at run time, by reading the tags of `Combodo/iTop`. So the Monday
run installs whatever Combodo released last week without anybody editing a workflow. Run it
locally to see what CI will use:

```bash
php tools/ci/resolve-itop-versions.php
```

Three fields change the outcome:

- **`php`** — the floor and the ceiling of what `composer.json` allows. The versions in
  between are covered by the unit matrix in `ci.yml`; installing on all of them would be
  three times the minutes for the same answer.
- **`allow_prerelease`** — resolve to a beta or rc when the branch has no stable patch yet.
  Those jobs are `continue-on-error`: a beta breaking is something to know on the Monday it
  breaks, not something to fail a contributor's pull request with.
- **`pin`** — an exact tag, when a patch is known broken and the fault is not ours. Say why,
  in the file, next to the pin.

This file is also the answer to "which versions do you support" on the Hub listing and in the
README. Keeping one list rather than three is the point of it.

One nuance worth stating plainly: iTop 3.2's setup treats PHP 8.4 as *not yet validated by
Combodo* and says so as a warning, not an error, so the install proceeds. A green 8.4 job is
therefore our claim about this module on that PHP, not Combodo's claim about iTop on it. The
warning is in the setup log of every such run.

## Two jobs, and why the first one has no database

`installable` runs first, on every supported version, with no MariaDB service attached. It
downloads the release, puts the module in `extensions/` and asks the setup to compute what it
*would* install (`--install=0`). That path opens no database connection — verified by running
it with credentials pointing at a database that does not exist — and it answers the question
that actually fails: **would this extension be selected at all**. About a minute, against the
several that a real install costs, and `install` does not start until every version has
answered.

`install` then does the rest: the real setup, iTop's module validation suite, this module's
integration suite, the datamodel checks and the HTTP smoke.

One thing the dry run makes obvious, and it is the reason the verdict later comes from the
database: **`--install=0` prints `installed!` at the end, exactly like a real run.** That string
means the setup reached the end of its script. It has never meant that anything was installed.

## The packaged release, never a git tree

The iTop under test is always the SourceForge archive — what an administrator downloads.

The git tree is the tempting alternative and it is wrong here. Combodo does not assemble a
release from that repository alone: **`authent-token` is in no source tag**, not at 3.2.2 and
not at 3.2.3 (`datamodels/2.x/` holds 50 entries there against 58 in the archive). This module
declares `authent-token/2.2.1` as a dependency, so on a source tree the setup finds it
unsatisfiable, drops the extension without failing, and everything downstream then tests an
iTop that does not contain the module. That is not a hypothetical: it is what the first real
run of this pipeline did, and what the dry-run assertion caught.

The tag is still fetched, for exactly one thing: `tests/php-unit-tests/`, which packaged
releases do not carry and which holds the `ItopDataTestCase` every integration test here
extends. `install-itop.sh` overlays that one directory onto the release and checks
`ItopDataTestCase.php` actually arrived — without it the integration suite would skip itself
and report green. iTop's own code is left exactly as published.

Release and tag do not always share a label — the archive `iTop-3.2.2-1-17851.zip` is published
under a tag called `3.2.2` — so the resolver reads SourceForge's listing for the release and
then pairs a tag to it, trying the exact label and then the label without its re-spin suffix.

## The trap in the response file

`<selected_extensions>` looks like the place to name this module. It is not, and naming it
there installs *less*, not more.

`InstallationFileService` walks the extensions it discovers in `extensions/` and skips any code
already present in that list, on the reasoning that `installation.xml` has handled it. But
`installation.xml` is a core file that lists core extensions; it has never heard of
`altioo-mcp`. Named in `selected_extensions`, the module is skipped by both paths and installed
by neither.

Left out, it is discovered as an *unpackaged extension* and added — which is precisely what
happens when somebody ticks it in the wizard. That is why the response file in
`install-itop.sh` carries an empty `<selected_extensions>`, and why the dry run asserts the
module appears in the computed list rather than trusting that it did.

## Running it on a laptop

Everything CI does is in `tools/ci/`, and none of it is GitHub-specific beyond the `::group::`
markers. Against a local MariaDB:

```bash
composer install --no-dev --optimize-autoloader

# just the question "would it install", no database needed
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=3.2) \
  DRY_RUN_ONLY=1 ITOP_DIR=/tmp/itop tools/ci/install-itop.sh

# the whole thing, against a local MariaDB
ITOP_ZIP_URL=$(php tools/ci/resolve-itop-versions.php --zip=3.2) \
  ITOP_TAG=3.2.3-2 ITOP_DIR=/tmp/itop DB_PWD=root tools/ci/install-itop.sh
```

Then, from `/tmp/itop/tests/php-unit-tests` (`composer install` there once):

```bash
php vendor/bin/phpunit --no-configuration --bootstrap unittestautoload.php --testdox /tmp/itop/env-production/altioo-mcp/tests/php-unit-tests/Integration
```

`install-itop.sh` copies this working copy through `exclude.txt`, the same list the release
archive is built from, so what gets installed is what ships — `vendor/` included, `tools/` and
`.github/` not. A module that only installs with its development files present is a module
that does not install.

## What this does not check

- **Apache.** The HTTP smoke uses PHP's built-in server, so it checks the application, not a
  web server's configuration. That `src/` and `vendor/` are unreachable while `index.php`
  answers depends on `.htaccess` and stays a manual step in the
  [release checklist](release-checklist.md).
- **Upgrades.** Installing over a previous version of this module, and confirming no
  `AltiooEventMCPService` history is lost, is still done by hand. It is the obvious next job:
  install the previous release tag, then re-run the setup with the current tree.
- **A real MCP client.** `tools/list` over curl is not Claude Desktop. The client matrix in
  [clients.md](clients.md) is human work.
