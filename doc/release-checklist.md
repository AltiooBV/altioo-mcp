# Release checklist

What has to be true before an archive is published, and what the release run has to prove.
The unchecked items below are the ones that cannot be closed inside the repository.

## Still open before the first publication

- [ ] **Publish the repository.** `https://github.com/AltiooBV/altioo-mcp` currently
      404s, and it is the value of `more_info_url` in `extension.xml`, of `homepage` and
      `support.*` in `composer.json`, of `doc.manual_setup` / `doc.more_information` in the
      module declaration, of the link line at the top of the README, and of
      `MCPHelper::SOURCE_URL`, which is the AGPL 13 source offer served to callers. One dead
      link is the first thing an evaluator clicks. If the final URL differs, change it in those
      six places — `ModuleMetadataTest` asserts they agree. Once the URL resolves, add the
      `[Unreleased]` and `[x.y.z]` link-reference definitions to the foot of
      [CHANGELOG.md](../CHANGELOG.md); until then the headings render their brackets literally,
      which is preferable to shipping compare links that 404. The brackets themselves are not
      optional — [release.yml](../.github/workflows/release.yml) matches on them.
- [ ] **Enable private vulnerability reporting** on that repository (Settings → Security).
      One toggle, and it belongs to the same sitting as creating the repository.
      [SECURITY.md](../SECURITY.md) points at `/security/advisories/new` and commits to 5
      working days to acknowledge and 90 days to a fix; the link 404s until the setting is on,
      and SECURITY.md calls GitHub the *preferred* channel, so until then the channel named
      first is the one that does not work. **Not a publication blocker:**
      <security@altioo.com> is live and published beside it in SECURITY.md, the README support
      table and `composer.json` `support.email`, so a reporter always has a route that works.
- [x] **Create `security@altioo.com`** — done. It is published in
      SECURITY.md and in the README support table as the alternative to GitHub.
- [ ] **Capture the listing images.** The Hub shows an icon and screenshots; `assets/img/`
      holds `altioo-mcp.svg` for the icon, and screenshots have to be taken from a running
      instance. Two are enough: the token screen with an `MCP` scope ticked, and an
      `AltiooEventMCPService` list showing calls that were made.
- [x] **Name the publishers** — done. [security-summary.md](security-summary.md) §2 answers
      "who can publish a release" by mechanism — a `v*` tag push, nothing by hand — and now
      also by name: **Guy Couronne (Altioo)**, sole holder of tag-push rights at 1.0.0. An
      approver asking the question is asking about people, not about a workflow file. Revisit
      the row whenever that stops being true, rather than at the next release.
- [ ] **Tag `v1.0.0`** once the run below is green. The archive, its SHA-256, the SBOM and the
      licence inventory are attached by [release.yml](../.github/workflows/release.yml); nothing
      is uploaded by hand.

## Every release

**Version.** The same `x.y.z` in `MCPHelper::VERSION`, `extension.xml` and
`module.altioo-mcp.php` — `ModuleMetadataTest` fails if they drift — with a
[CHANGELOG.md](../CHANGELOG.md) entry under a dated heading, not under `[Unreleased]`.

**Dates, on the day of the tag and not before.** Two files carry one, and both were once
written ahead of a release that had not happened:

1. **[CHANGELOG.md](../CHANGELOG.md)** — fold everything under `[Unreleased]` into the version
   heading and date it `## [x.y.z] - YYYY-MM-DD`. Anything left under `[Unreleased]` ships in
   the tag without appearing in its notes, which is how a reader ends up unable to tell what a
   version contains. [release.yml](../.github/workflows/release.yml) refuses the tag on either
   count, so this is checked rather than remembered.
2. **[SECURITY.md](../SECURITY.md)** — set the release date and the five-year end date in
   *Support period*, and the row in *Supported versions*. This is the one date with an outside
   commitment attached: dated early it promises less than five years, and five years is the
   Cyber Resilience Act's floor rather than a target.

Write both from the tag's own date. A date guessed in advance is wrong by however long the
release then slips, and nothing downstream will notice.

**`@since` tags.** [`tools/reconcile-since.py`](../tools/reconcile-since.py) rewrites every
`@since` in `src/` to the version the symbol actually first appeared in, deriving the answer
from git rather than from the previous run, so it converges however the tags have been edited
in between. Nothing calls it — it rewrites source, so a person runs it and reads the diff:

For 1.0.0 there is no baseline revision to compare against — `v1.0.0` is what this release
creates — and the answer is known anyway: every symbol first appeared in it. Pass the commit
being tagged as the baseline, so everything present is written `1.0.0` and nothing is newer:

```bash
tools/reconcile-since.py . HEAD 1.0.0 1.1.0
```

From 1.1.0 on, the baseline is the tag being replaced:

```bash
tools/reconcile-since.py . v1.0.0 1.0.0 1.1.0
```

**Branch notes.** Re-verify [itop-branch-notes.md](itop-branch-notes.md) against the branches
this release actually claims, and update the date at its head. It is the one document that
holds facts with a shelf life — branch status, PHP ranges, the newest patch of each branch —
and `AGENTS.md` §12.5 makes this a release gate. Stale notes here become the supported-versions
claim in the README and on the Hub listing.

**Green CI.** The linter (`composer lint`, Combodo's standard); the unit suite on PHP 8.2, 8.3
and 8.4; `composer validate --strict`; `composer check-platform-reqs`; `composer audit
--locked` — which [release.yml](../.github/workflows/release.yml) also runs on the tag build,
since a tag ref matches neither of ci.yml's triggers.

**Green iTop matrix.** [itop-matrix.yml](../.github/workflows/itop-matrix.yml) installs the
module with iTop's unattended setup on the newest patch of every branch in
[.github/itop-support.json](../.github/itop-support.json), runs the integration suite there and
calls the endpoint over HTTP. Check the run resolved the versions you mean to claim — it prints
them in the job summary — and that its `installable` and `install` jobs are green for every
branch you mean to claim.

Read the prerelease jobs individually rather than trusting the overall colour: `allow_prerelease`
entries run `continue-on-error`, so a branch resolving to a beta or rc reports green whether it
passed or not. [ci-itop-matrix.md](ci-itop-matrix.md) explains what each check is for.

**The archive is built by CI, not by hand.** Pushing a `v*` tag runs
[release.yml](../.github/workflows/release.yml), which refuses a tag that disagrees with
`extension.xml`, builds the production vendor tree on PHP 8.2, assembles the zip from
`exclude.txt`, and attaches four files to the release: the archive, its `.sha256`, a CycloneDX
`sbom.cyclonedx.json` and `licenses.json`. The last two also go *inside* the archive, so an
instance found in a year's time can answer what it is running without reaching the internet.
`workflow_dispatch` runs the same build without publishing, which is how to exercise it before
the tag exists.

Publish the SHA-256 wherever the download is announced. `vendor/` ships, so "the file I
downloaded is the file CI built" has to be a question with an answer.

**A real install.** CI installs on every supported branch and proves the endpoint answers;
what it cannot do is use the thing. This is the part that needs hands — start from the
published archive, not from a build tree:

1. Download the archive the release workflow built and check it against the published SHA-256.
   (Building it locally — `composer install --no-dev --optimize-autoloader`, then zip minus
   `exclude.txt` — is for debugging the packaging, not for publishing.)
2. Unzip it into a clean iTop 3.2 at `<itop>/extensions/altioo-mcp/`.
3. Run the setup, tick the extension, complete it.
4. Create a personal token with an `MCP` scope; connect a real MCP client (see
   [clients.md](clients.md)); list tools; read one object; run one write with `simulate` left
   at its default and confirm nothing was written.
5. Check that an `AltiooEventMCPService` row was recorded for those calls.
6. Confirm `<itop>/env-production/altioo-mcp/src/` and `/vendor/` are **not** reachable over
   HTTP while `index.php` is. Check the compiled tree first: the environment root grants PHP for
   its whole subtree, so that is where this fails. Then repeat it under
   `<itop>/extensions/altioo-mcp/`, which holds the same files.
7. Record which iTop patch and which PHP this ran on, in the **Tested on** line of the
   changelog entry for this version. That line is the single home for the answer: the README
   points at it and the Hub listing is copied from it.

**Upgrade path.** Automated: the [Upgrade workflow](../.github/workflows/upgrade.yml) installs the
previous release, seeds data, upgrades to the working copy and checks nothing was lost — see
[ci-upgrade.md](ci-upgrade.md). Before a release, run it once from the tag being replaced
(`workflow_dispatch`, `baseline_ref`) and read its summary. There is no tag to replace before
the first release: until `v1.0.0` exists the scheduled and push runs skip for want of a
baseline, so drive it by hand from an earlier commit and record which one you used. What stays manual is the part that
needs a browser: log into the upgraded instance and confirm the console still shows the module's
three surfaces — the **MCP Services User** profile under *Administration → User Management →
Profiles*, the module's parameters in the configuration editor, and the **MCP Service Call**
audit rows. This module declares no menu; there is nothing to look for in the sidebar.

**Archive contents.** `vendor/` present and built with `--no-dev`; `README.md`, `SECURITY.md`,
`CHANGELOG.md`, `LICENSE` and `doc/` present; `tests/` present (iTop's own Extensions testsuite
scans `env-production/`); `sbom.cyclonedx.json` and `licenses.json` present; no `.git`, no
`.DS_Store`, no `.idea`, no `tools/`. The `package` job in CI checks the parts of this that can
be checked without a zip, and the release workflow checks the zip itself.

**Hub listing.** Update the text from [hub-listing.md](hub-listing.md) — in particular the
supported-versions line and the "tested on" line, which change per release.

**Audit summary.** Re-read [security-summary.md](security-summary.md) against what the release
actually changed. It is the page an approver reads instead of the README, and the rows most
likely to have gone stale are the dependency licences, the footprint, and anything the release
added to the HTTP surface. It links rather than restates, so in a quiet release there is
usually nothing to do — confirm that rather than assume it.
