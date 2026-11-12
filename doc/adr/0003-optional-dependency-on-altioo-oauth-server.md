# 0003 — altioo-oauth-server is an optional dependency, declared and advertised

- Date: 2026-10-29
- Decided by: Guy Couronne
- Status: accepted

[altioo-oauth-server](https://github.com/AltiooBV/altioo-oauth-server) makes iTop its own OAuth
2.1 authorization server, issuing ordinary `PersonalToken`s, and protects this module's endpoint
among others. Until now altioo-mcp declared no dependency on it and did not know it by name:
an operator installing both copied a URL from that module's README into
`mcp_protected_resource_metadata`, so that a client with only a "Connect" button finds the
authorization server from the `401`.

The decision is that altioo-mcp names it, as an optional dependency, in both of the ways that
exist:

- **Declared to iTop's setup.** iTop has no optional dependency (`doc/itop-branch-notes.md`,
  section 4). The nearest is `'itop-structure/3.2.0 || altioo-oauth-server/0.1.0'` in
  `module.altioo-mcp.php`: always true, since every installation has `itop-structure`, so it
  never stops an install; and a module named in it that is being installed is set up first.
- **Advertised at runtime.** When `mcp_protected_resource_metadata` is empty and the module is
  installed - its `AltiooOAuthClient` class compiled - the `401` points at the document it
  serves for this endpoint, `…/altioo-oauth-server/index.php?route=protected-resource&for=mcp`.
  A value in the setting always wins: a proxy in front of iTop, or a resource the operator
  renamed in that module's configuration, is theirs to say.

It stays optional in both directions. Without it, altioo-mcp behaves exactly as before. The
OAuth server keeps no code path specific to altioo-mcp (its own ADR 0003); this record changes
nothing on its side. Portal mode still works with an administrator-minted token (README,
"Giving a portal user a token").

Supersedes the line of the portal-mode design note (§3.6, "It stays optional") saying that
altioo-mcp declares no dependency on that module and does not detect it by name.

Rejected: the setup declaration alone, which orders the install and changes nothing an operator
would notice; and the runtime half alone, which works but leaves the relation invisible to
anyone reading the module's declaration.

Enforced by: `OAuthServerDiscoveryTest` (unit suite) — the advertised URL, the setting winning,
nothing without the module, and the dependency kept in its always-satisfied form.

Amended 2026-11-12 (Guy Couronne): seeing and revoking one's own token stays with the companion
(*Connected applications*) and iTop's own *My Account → Personal Tokens*. This module adds no
page for it, which would be a new HTTP entry point; a portal-only user without the companion
asks an administrator (README, "Granting access").
