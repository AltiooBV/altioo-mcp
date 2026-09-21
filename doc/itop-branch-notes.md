# iTop branch notes — perishable facts

Companion to [`AGENTS.md`](../AGENTS.md). **Everything here expires.** `AGENTS.md` holds rules that
do not depend on a version; this file holds the values those rules need, each pinned to a branch and
a verification date.

```
VERIFIED    iTop 3.2.2 source, paths cited inline
AS OF       August 2026
REFRESH     at the start of every project, against the branch actually targeted
```

**How to use this file.** Never quote a value from here without confirming it on your target branch.
When a value here and the branch disagree, the branch wins — fix this file in the same commit
(`AGENTS.md` §12.5). If a rule you want to write has no version in it, it belongs in `AGENTS.md`,
not here.

**What this file is not.** It records **upstream facts about iTop**. It does **not** record what
this extension supports — that is [`.github/itop-support.json`](../.github/itop-support.json), which
declares itself the single source of truth and is what CI actually computes from. Nor does it
re-document this repository's own CI, which is [`doc/ci-itop-matrix.md`](ci-itop-matrix.md). When
either of those and this file disagree, **they win** (`AGENTS.md` §0, rank 2 over rank 3). NEVER
copy a branch or PHP value from here into a claim about this extension.

---

## 1. Release policy and branch status — `AGENTS.md` §1.1

| Track | Cadence | Support window |
|---|---|---|
| LTS | ~2 years | 3–4 years maintenance, then +1 year security-only |
| STS | months | until next release + 6 months security |

| Branch | Track | Upstream status |
|---|---|---|
| **3.2.x** | **LTS** | current LTS, latest 3.2.3-2 (Apr 2026) |
| 3.3.x | STS | next STS |
| 3.1.x | — | unmaintained (last 3.1.3, Apr 2025) |
| 3.0.x | — | unmaintained (last 3.0.4, Jan 2024) |
| 2.7.x | — | unmaintained (last 2.7.13, Sep 2025) |

**Which of these this extension claims** is not decided here — read
[`.github/itop-support.json`](../.github/itop-support.json). That file names branches, never
patches, and CI resolves each to its newest patch at run time.

**Worked failures — `latest:` wiki vs. the LTS.** Both found by grepping 3.2.2 after the wiki
claimed otherwise:

- `latest:` recommends a `delegated_authentication_endpoints` parameter in `module.<name>.php`. That
  parameter does not exist anywhere in 3.2.2.
- `latest:` refers to an `exec.php` at the web root. There is none in 3.2.2.

Use the version-pinned namespace rather than `latest:` wherever the answer is load-bearing. The
namespace is the branch with underscores and a trailing zero, not the branch as written anywhere
else: `3_2_0:install:security` and `3_3_0:install:security` both resolve; `3.2:install:security`
is a 404. Verified August 2026.

---

## 2. PHP ranges — `AGENTS.md` §1.2

**What iTop supports**, per **patch** rather than per branch:

| iTop | PHP |
|---|---|
| 3.2.0–3.2.2 | 8.1 → 8.3 (8.4 known issues) |
| 3.2.3-1 | 8.1 → 8.4 |
| 3.3.x | 8.2 → 8.4 |

**What this extension tests on** is a different statement, and lives in
[`.github/itop-support.json`](../.github/itop-support.json) (floor and ceiling per branch), bounded
by `composer.json` `"php"`. This table says what upstream permits; that file says what we claim.
Keep them consistent, but do not merge them — a widened upstream ceiling is news, not a policy
change.

The machine-readable floor and ceiling on any branch:

| Value | Where |
|---|---|
| iTop's floor | `PHP_MIN_VERSION`, `setup/setuputils.class.inc.php` |
| iTop's ceiling | `PHP_NOT_VALIDATED_VERSION`, same file — setup *warns* at or above it (`CheckResult::WARNING`) and installs anyway; only the floor raises `CheckResult::ERROR` |

---

## 3. Database engines — `AGENTS.md` §1.3

- MariaDB preferred. MySQL 5.7+ supported but being phased out.
- MySQL 8 removed the query cache — a measurable performance change on large instances.
- Galera unsupported on every branch to date (`GET_LOCK()` semantics).

---

## 4. Module dependency resolution — `AGENTS.md` §2.2

Source: `setup/modulediscovery.class.inc.php`, `DependencyIsResolved()`, confirmed at 3.2.2.

Each entry of the `dependencies` array is a **string expression**, not a single module id. The
resolver splits it on `( ) & |` and whitespace, replaces every `<module>/<version>` term with
`(true)` or `(false)`, and `eval`s the result. So `&`, `|`, `&&`, `||` and parentheses all work.

| Part | At 3.2.2 |
|---|---|
| Version operators | `<` `<=` `=` `>` `>=`, written before the version; `>=` when none is given |
| Comparison | `version_compare()` against the version in the depended-on module's own id |
| Missing module | the term is `(false)`; an expression that comes out false leaves the module unselectable |
| Real precedent | `itop-service-mgmt/2.7.1 || itop-service-mgmt-provider/2.7.1` in Combodo's own modules, for a class either module can supply |

**Quirk worth knowing.** A module named anywhere in the expression that is itself *selected* for
installation is treated as a prerequisite for ordering, whatever the boolean result — so naming a
module in an `||` branch still constrains load order when that module is being installed. See
`$bMissingPrerequisite` in the same method.

There is **no optional dependency** on any branch to date: the list decides selectability, and
adapting to what an instance has installed is a runtime question (`MetaModel::IsValidClass()`),
not something the declaration can express.

---

## 5. `extension.xml` — elements the parser reads — `AGENTS.md` §2.3

Source: `setup/extensionsmap.class.inc.php`, `iTopExtensionsMap::ReadDir()`. Six elements in 3.2.2;
everything else is silently ignored.

| Element | Maps to | Note |
|---|---|---|
| `extension_code` | `sCode` | + `version` forms the extension id |
| `label` | `sLabel` | setup UI |
| `description` | `sDescription` | setup UI |
| `version` | `sVersion` | |
| `mandatory` | `bMandatory` | compared to literal string `'true'` |
| `more_info_url` | `sMoreInfoUrl` | Hub "more information" link; MUST NOT be empty |

Confirmed absent in 3.2.2: `itop_version_min`, `php_min_version`, `license`.

---

## 6. Extension point deprecation status — `AGENTS.md` §3.2

Status as of 3.2. Re-grep `@deprecated` in `application/applicationextension.inc.php` on your branch.

| Interface | Status (3.2) |
|---|---|
| `iEventServiceSetup` | **preferred**, available 3.1+ |
| `iApplicationObjectExtension` | **deprecated 3.1.0** → events |
| `iPageUIExtension` | **deprecated 3.0.0** → `iPageUIBlockExtension` |
| all others in the §3.2 catalogue | supported at 3.2 |

Deprecated APIs keep working until the release that removes them — neither of the above had been
removed as of 3.2.2.

---

## 7. Lifecycle events — `AGENTS.md` §3.3

`iApplicationObjectExtension::OnDBInsert/OnDBUpdate/OnDBDelete/OnCheckToWrite…` are all
`@deprecated 3.1.0 N°4756`.

Core events, declared in `application/datamodel.application.xml` `<events>`; each names the method it
`<replaces>`:

| Event | Replaces |
|---|---|
| `EVENT_DB_BEFORE_WRITE` | `DBObject::OnInsert` |
| `EVENT_DB_CHECK_TO_WRITE` | `cmdbAbstractObject::DoCheckToWrite` |
| `EVENT_DB_AFTER_WRITE` | `DBObject::AfterInsert` |
| `EVENT_DB_CHECK_TO_DELETE` / `EVENT_DB_AFTER_DELETE` / `EVENT_DB_LINKS_CHANGED` / … | matching `DBObject` hooks |

---

## 8. Test harness pins — `AGENTS.md` §8.3

Read `tests/php-unit-tests/composer.json` on the target branch; values below are 3.2.2.

| Pin | Value at 3.2.2 |
|---|---|
| `phpunit/phpunit` | `^9` |
| config schema | `schema.phpunit.de/8.5` (9-era: `printerClass`, `convertErrorsToExceptions`) |

Consequence while the pin is `^9`: `@dataProvider` doc-comments only — `#[DataProvider]` attributes
are PHPUnit 10+ and the suite will not run them. Doc-comments are deprecated in 11 and removed in
12, so this needs revisiting when iTop's pin moves.

---

## 9. CI / unattended setup — `AGENTS.md` §12.3

This repository's own install-verification chain is documented in
[`doc/ci-itop-matrix.md`](ci-itop-matrix.md) — **read that, not this**. Only the upstream facts it
depends on are recorded here:

- `--install=0` performs no database connection in 3.2.2. The script's options have changed between
  branches — confirm the flag set before relying on it.
- **Modules absent from source tags**: at 3.2 and 3.3, `authent-token` ships in the packaged release
  but is in no source tag. A dependency on it is unsatisfiable when iTop is installed from a git
  tag, and the extension is dropped without a failure. Install from the packaged release.
- The class writing `priv_module_install` / `priv_extension_install` moved from
  `setup/moduleinstallation.class.inc.php` to `setup/moduleinstallation/` between 3.2 and 3.3. The
  **tables** did not move — which is why the verdict is taken from them, not from the setup log.
- **The computed module list changed shape between 3.2 and 3.3.** 3.2 prints
  `Computed modules to install:` as one comma-separated line; 3.3 prints one module per line. A
  check reading the single line after the heading reports a module unselectable on 3.3 that the
  setup had in fact selected. `install-itop.sh` reads the block to the next blank line and splits
  on both.
- Response file models: `setup/unattended-install/xml_setup/`. `--installation_xml` path is
  `datamodels/2.x/installation.xml` on 3.x branches.

---

## 10. Security config parameter names — `AGENTS.md` §6.9

Verified in `core/config.class.inc.php` at 3.2.2. These have been renamed between branches — grep
before use.

| Header / behaviour | Parameter |
|---|---|
| `X-Frame-Options` (default `SAMEORIGIN`) | `security_header_xframe` |
| `X-Content-Type-Options: nosniff` | `security.enable_header_xcontent_type_options` |
| Config editor lockout | `'itop-config' => ['config_editor' => 'disabled']` |

---

## 11. Source file map — `AGENTS.md` §14

Paths confirmed at 3.2.2. Stable across several majors, but confirm on your branch.

| Subsystem | File |
|---|---|
| Extension interfaces | `application/applicationextension.inc.php` |
| Event service | `sources/Service/Events/` |
| Core event declarations | `application/datamodel.application.xml` `<events>` |
| Event context tags | `core/contexttag.class.inc.php` |
| Install/upgrade hooks | `setup/moduleinstaller.class.inc.php`; invoked in `setup/runtimeenv.class.inc.php` |
| `extension.xml` parser | `setup/extensionsmap.class.inc.php` |
| Input, escaping, transactions | `application/utils.inc.php` |
| HTML sanitizer | `core/htmlsanitizer.class.inc.php` |
| Attribute types | `core/attributedef.class.inc.php` |
| Change attribution | `core/cmdbobject.class.inc.php` |
| Background jobs | `core/backgroundprocess.inc.php` |
| Module settings | `core/metamodel.class.php` |
| PHP floor/ceiling | `setup/setuputils.class.inc.php` |
| Security config params | `core/config.class.inc.php` |

---

## Licence

Copyright © 2026 Altioo. Licensed under
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/), as a companion to
[`AGENTS.md`](../AGENTS.md).

Every value in this file was verified against the iTop source on the date in the header and will go
out of date. If you are reading a copy, check its date before trusting it, and re-verify against the
branch you target.
