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
- [ ] **Enable private vulnerability reporting** on that repository (Settings → Security), and
      replace the pending block in [SECURITY.md](../SECURITY.md) with the channel and the
      response times you commit to.
- [ ] **Fill in the support block** in the README (`## Support`) — where issues go, and what,
      if anything, is promised.
- [ ] **Capture the listing images.** The Hub shows an icon and screenshots; `assets/img/`
      holds `altioo-mcp.svg` for the icon, and screenshots have to be taken from a running
      instance. Two are enough: the token screen with an `MCP` scope ticked, and an
      `EventMCPService` list showing calls that were made.
- [ ] **Tag `v1.0.0`** once the run below is green, and attach the archive to the release.

## Every release

**Version.** The same `x.y.z` in `MCPHelper::VERSION`, `extension.xml` and
`module.altioo-mcp.php` — `ModuleMetadataTest` fails if they drift — with a
[CHANGELOG.md](../CHANGELOG.md) entry under a dated heading, not under `[Unreleased]`.

**Green CI.** Unit suite on PHP 8.2, 8.3 and 8.4; `composer validate --strict`;
`composer check-platform-reqs`; `composer audit --locked`.

**A real install.** Not a claim — a run:

1. Build the archive: `composer install --no-dev --optimize-autoloader`, then zip the module
   directory minus the entries in `exclude.txt`.
2. Unzip it into a clean iTop 3.2 at `<itop>/extensions/altioo-mcp/`.
3. Run the setup, tick the extension, complete it.
4. Create a personal token with an `MCP` scope; connect a real MCP client (see
   [clients.md](clients.md)); list tools; read one object; run one write with `simulate` left
   at its default and confirm nothing was written.
5. Check that an `EventMCPService` row was recorded for those calls.
6. Confirm `<itop>/extensions/altioo-mcp/src/` and `/vendor/` are **not** reachable over HTTP
   while `index.php` is.
7. Record which iTop patch and which PHP this ran on, in the changelog entry.

**Upgrade path.** Unzip over the previous version, re-run the setup, confirm the endpoint still
answers and no `EventMCPService` history was lost.

**Archive contents.** `vendor/` present and built with `--no-dev`; `README.md`, `SECURITY.md`,
`CHANGELOG.md`, `LICENSE` and `doc/` present; `tests/` present (iTop's own Extensions testsuite
scans `env-production/`); no `.git`, no `.DS_Store`, no `.idea`. The `package` job in CI checks
the parts of this that can be checked without a zip.

**Hub listing.** Update the text from [hub-listing.md](hub-listing.md) — in particular the
supported-versions line and the "tested on" line, which change per release.
