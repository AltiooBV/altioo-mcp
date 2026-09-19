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
| A dry run (`--install=0`) selects `altioo-mcp` | The setup **silently** dropping the extension — an unsatisfiable dependency does not fail a setup, it removes a checkbox. This one runs as its own job, before any database exists |
| iTop's unattended setup completes | A module declaration that no longer parses, an XML delta the compiler rejects |
| `--check-consistency=1` | A datamodel this module contributes to that compiles but is not coherent |
| A row in `priv_module_install`, at this version | The setup completing without installing the module, or installing a stale copy left in `extensions/` by an earlier run |
| A row in `priv_extension_install`, `source = extensions` | The module arriving by some route other than the one a user's install takes |
| `env-production/altioo-mcp` exists | Recorded as installed, but not compiled to where iTop loads it from |
| iTop's `ModuleIntegration` suite | Dictionary entries that do not resolve in the compiled environment. Combodo's test, run against our module |
| The module's own `Integration` suite | Everything that needs a live `MetaModel`, `UserRights` and a database |
| `itop-smoke.php` | Module settings, the audit class, the token scopes and the profile — the pieces the security model is made of, checked in the instance rather than in a fixture |
| `http-smoke.sh` | The endpoint over the wire: refused without a credential — asked of both the compiled URL and the one under `extensions/`, since a boot that fatals answers `500` there and `401` nowhere — then `initialize` and `tools/list` with a credential |

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

One nuance worth stating plainly, and it now turns on which patch the resolver lands on. Up to
3.2.2, iTop's setup treated PHP 8.4 as *not yet validated by Combodo* and said so as a warning,
not an error, so the install proceeded — and a green 8.4 job was our claim about this module on
that PHP rather than Combodo's claim about iTop on it. 3.2.3-1 moved
`PHP_NOT_VALIDATED_VERSION` from `8.4.0` to `8.5.0`, so the newest 3.2 patch validates 8.4 and
that warning is gone from the setup log. It returns the moment a `pin` holds the branch at
3.2.2 or earlier, which is the case this paragraph exists for: read the setup log of the run
rather than assuming either way.

## The order of the jobs

Cheapest and most decisive gate first, so that a failure costs the minutes it deserves:

| Job | What it needs | What it answers |
|---|---|---|
| `unit` | PHP | is this tree worth installing |
| `versions` | PHP, the GitHub API | which iTop releases are we talking about |
| `installable` | PHP, the iTop archive — **no database** | would the setup select this extension at all |
| `install` | + MariaDB | does the setup compile and install it, and does it then work |

`unit` repeats the unit suite that `ci.yml` already runs across the PHP matrix, on one PHP
version. The duplication is deliberate and should not be tidied away: `ci.yml` is a different
workflow, so its result cannot gate a job in this one, and without the gate every install job
starts — MariaDB service, release download, full setup, once per supported version — on a tree
whose unit suite is red. The gate costs under a minute; what it prevents costs several per
version. `ci.yml` remains the place the *matrix* question is answered, in parallel with all of
this.

`installable` runs before any database exists, on every supported version, with no MariaDB
service attached. It downloads the release, puts the module in `extensions/` and asks the setup
to compute what it *would* install (`--install=0`). That path opens no database connection —
verified by running it with credentials pointing at a database that does not exist — and it
answers the question that actually fails: **would this extension be selected at all**. About a
minute, against the several that a real install costs, and `install` does not start until every
version has answered.

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

## Why not iTop's own `install-itop.sh`

`setup/unattended-install/` ships two things. `unattended-install.php` is the installer, and it
is what actually runs here — called with its documented options, not reimplemented.
`install-itop.sh` next to it is a wrapper, and that one is not used.

It is written for an administrator inside an unzipped iTop with a response file already filled
in, installing once. Stripped of argument handling it defaults `installation.xml`, clears the
maintenance lock, and calls the PHP with `--use_itop_config`. Four things follow that rule it
out for CI:

- no way to pass **`--install=0`**, which is the whole database-free `installable` job;
- no way to pass **`--clean=1`**, so a re-install is not repeatable;
- no way to pass **`--check-consistency=1`**;
- **`--use_itop_config` is hardcoded**, and it overrides the response file's database settings,
  URL and language from an existing `config-itop.php` whenever one is present — harmless on a
  fresh runner, wrong on any reused workspace.

Everything else in `install-itop.sh` here is work that cannot live in a script shipped inside
an iTop: choosing and fetching a release, placing this module in `extensions/`, writing the
response file, and checking afterwards that the module is installed. Two of its 140 lines are
the call to Combodo's installer.

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
markers. What is not portable is the machine underneath: the workflows want a PHP inside the
range `composer.json` declares, a MariaDB, and somewhere disposable to install an iTop into,
and few working machines have all three — the one this was last run on has PHP 8.5, which the
extension does not support.

[`tools/ci/local/run.sh`](../tools/ci/local/run.sh) supplies all three from Docker and needs
nothing else installed:

```bash
tools/ci/local/run.sh unit            # ci.yml's unit job and its linter, on 8.2
tools/ci/local/run.sh unit 8.4        # the same, on the ceiling of the range
tools/ci/local/run.sh matrix          # itop-matrix.yml, iTop 3.2, on 8.2
tools/ci/local/run.sh matrix 3.3 8.4  # another branch, another PHP
tools/ci/local/run.sh integration     # the integration suite alone, seconds
tools/ci/local/run.sh down            # remove the containers, the volume, the network
```

It runs the same `tools/ci/` scripts the workflow steps run; the only thing it adds is the
machine. The image is built once per PHP version and the installed iTop is kept in a Docker
volume, which is what makes `integration` a two-second loop rather than a ten-minute one — the
loop an integration test actually gets written in. `matrix` records a verdict per step and
carries on, the way `fail-fast: false` lets the real matrix finish.

**Expect `unattended install` to fail on iTop 3.2.3-2.** The setup runs with
`--check-consistency=1`, and that release's own datamodel does not pass it: `ActionNotification`
declares a default language outside its allowed values, `SynchroReplica` the same for
`dest_class`, and `TemporaryObjectDescriptor` puts an unknown `meta` in its details ZList. None
of the three is ours — installing the same release with an empty `extensions/` and a database of
its own reports exactly the same three. The module still compiles, gets its rows in
`priv_module_install` and `priv_extension_install`, and serves tools over HTTP, which is why the
steps after it are worth reading rather than skipping.

On a machine that does have a PHP in range and a MariaDB, the scripts still run directly:

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
- **Upgrades.** Covered by a separate workflow now — [ci-upgrade.md](ci-upgrade.md). It is
  separate because it costs two installs per baseline and answers a different question: not
  "does this install" but "what happens to the instance somebody already has".
- **A real MCP client.** `tools/list` over curl is not Claude Desktop. The client matrix in
  [clients.md](clients.md) is human work.
