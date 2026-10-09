# Review personas — altioo-mcp

A review here runs the guide's passes — [`doc/itop-extension-guide.md`](../doc/itop-extension-guide.md),
"Review procedure — persona passes" — in the guide's order, with the passes below inserted where
each says. The guide's passes are the personas every iTop extension faces, and are not repeated
here; this file holds only what this extension adds.

**Why these.** Every guide pass takes a person's point of view, while this module's main client
is a program: an AI agent calling the MCP endpoint with a user's token. Nothing in the guide asks
what that client does, what can be made to do it, or where the data it reads goes next.
[`SECURITY.md`](../SECURITY.md), "Threat model", answers much of it, and these passes are what
checks that it still does.

**Findings** go beside this file, one per review, named `<date>-<kind>.md`. They are gitignored —
they name real gaps — and only this file is tracked. `exclude.txt` keeps the folder out of the
release archive.

## Added passes

### Hostile or steered agent client — red, after the guide's red team

- **Question:** a program holding a valid token, acting at machine speed and following
  instructions its user never gave — planted in a ticket, an email, a page it reads — what can it
  make the module do that the token's owner never asked for?
- **Open:** every tool's output (`src/Core/Tools/`, and the toolsets a pack registers) for fields
  someone other than the caller can write — descriptions, logs, email bodies, attachment text,
  names; then every tool that writes, deletes or sends, for what one planted instruction makes it
  do with the caller's rights. Check what holds without the client's cooperation: token scopes
  (`src/Service/TokenScopes.php`), `mcp_read_only` and `mcp_capabilities`, the write barriers in
  `SECURITY.md` ("The rule the write barriers reduce to"), and whether "dry run by default" is
  enforced by the server or only asked of the client. A control that works only if the model
  obeys a tool description is not a control.
- **Blocks:** release.

### Agent client and the person behind it — purple, after the guide's installing client / operator

- **Question:** a client that means well — do its tool descriptions, errors and dry-run results
  let it act correctly, with no silent partial write? One that wants its task done — does it reach
  a refused action another way, use more access or data than the task needs, or read a refused,
  dry-run or partial result as done? And does the person who delegated get only what they asked
  for, and an honest account of what was not done?
- **Open:** each tool's description and input schema as the only documentation the client has;
  each refusal it can return, looking for another tool, parameter or sequence reaching the same
  result; the bulk tools (`src/Abstract/AbstractBulkTool.php`) for partial application and how it
  is reported; the defaults a client gets without asking (`mcp_pagination_limit`, field lists,
  search scope); and the change history a write leaves (README, "Audit trail"), for whether it
  names the person and the client, not only an account.
- **Blocks:** release when a refused action succeeds another way or a partial result reads as
  success; otherwise backlog.

### Data owner — purple, after the agent client above

- **Question:** the people the data is about — callers, agents, contacts: where does their data go
  that iTop alone would not send it, is it limited to what the caller needed, and can the instance
  owner see it, switch it off and tell them?
- **Open:** every tool and resource that returns object data, against iTop's own visibility
  (profiles, organisation filtering, per-attribute read rights); what leaves through the client to
  its model provider, which README "Data flow and privacy" tells the instance owner — check it is
  still true; logs and audit rows (`AltiooEventMCPService`) for personal data they carry; and the
  settings that switch a route off (`mcp_disabled_tools`, `mcp_enabled_toolsets`,
  `mcp_allowed_profiles`).
- **Blocks:** release when personal data leaves the instance by a route the operator cannot see or
  switch off; otherwise backlog.

The guide's blue team reviews the findings of these passes like any other's.
