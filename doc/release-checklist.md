# Release checklist

What has to be true before an archive is published, and what the release run has to prove.
The items marked **pending** are the ones that cannot be closed inside the repository.

## Still open before the first publication

- [ ] **Publish the repository.** `https://github.com/altioo/mcp-server-extension` currently
      404s, and it is the value of `more_info_url` in `extension.xml`, of `homepage` and
      `support.*` in `composer.json`, of `doc.manual_setup` / `doc.more_information` in the
      module declaration, and of the link line at the top of the README. One dead link is the
      first thing an evaluator clicks. If the final URL differs, change it in those five
      places — `ModuleMetadataTest` asserts they agree.
- [ ] **Enable private vulnerability reporting** on that repository (Settings → Security).
      [SECURITY.md](../SECURITY.md) already points at
      `/security/advisories/new` and commits to 5 working days to acknowledge and 90 days to a
      fix; the link 404s until the setting is on.
- [x] **Create `security@altioo.com`** — done (2026-08-18). It is published in
      SECURITY.md and in the README support table as the alternative to GitHub.
- [ ] **Capture the listing images.** The Hub shows an icon and screenshots; `assets/img/`
      holds `altioo-mcp.svg` for the icon, and screenshots have to be taken from a running
      instance. Two are enough: the token screen with an `MCP` scope ticked, and an
      `AltiooEventMCPService` list showing calls that were made.
- [ ] **Tag `v1.0.0`** once the run below is green. The archive, its SHA-256, the SBOM and the
      licence inventory are attached by [release.yml](../.github/workflows/release.yml); nothing
      is uploaded by hand.

## Every release

**Version.** The same `x.y.z` in `MCPHelper::VERSION`, `extension.xml` and
`module.altioo-mcp.php` — `ModuleMetadataTest` fails if they drift — with a
[CHANGELOG.md](../CHANGELOG.md) entry under a dated heading, not under `[Unreleased]`.

**Green CI.** Unit suite on PHP 8.2, 8.3 and 8.4; `composer validate --strict`;
`composer check-platform-reqs`; `composer audit --locked`.

**Green iTop matrix.** [itop-matrix.yml](../.github/workflows/itop-matrix.yml) installs the
module with iTop's unattended setup on the newest patch of every branch in
[.github/itop-support.json](../.github/itop-support.json), runs the integration suite there and
calls the endpoint over HTTP. Check the run resolved the versions you mean to claim — it prints
them in the job summary — and that the packaged-archive job ran, which it does not do on pull
requests. [ci-itop-matrix.md](ci-itop-matrix.md) explains what each check is for.

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
6. Confirm `<itop>/extensions/altioo-mcp/src/` and `/vendor/` are **not** reachable over HTTP
   while `index.php` is.
7. Record which iTop patch and which PHP this ran on, in the changelog entry.

**Upgrade path.** Unzip over the previous version, re-run the setup, confirm the endpoint still
answers and no `AltiooEventMCPService` history was lost. Still manual; see the last section of
[ci-itop-matrix.md](ci-itop-matrix.md) for what automating it would take.

**Archive contents.** `vendor/` present and built with `--no-dev`; `README.md`, `SECURITY.md`,
`CHANGELOG.md`, `LICENSE` and `doc/` present; `tests/` present (iTop's own Extensions testsuite
scans `env-production/`); `sbom.cyclonedx.json` and `licenses.json` present; no `.git`, no
`.DS_Store`, no `.idea`, no `tools/`. The `package` job in CI checks the parts of this that can
be checked without a zip, and the release workflow checks the zip itself.

**Hub listing.** Update the text from [hub-listing.md](hub-listing.md) — in particular the
supported-versions line and the "tested on" line, which change per release.
