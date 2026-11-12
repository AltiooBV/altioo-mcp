# 0008 — What an agent does with the tools is tested with a model, beside the suites that test the server

- Date: 2026-10-30, revised 2026-11-04, accepted 2026-11-12
- Decided by: Guy Couronne, 2026-11-12, with the scope under "Accepted" below
- Status: accepted

The unit and integration suites check what the server answers. Nothing checks what a client
makes of it: [ci-itop-matrix.md](../ci-itop-matrix.md), "What this does not check", leaves a real
MCP client to the human matrix in [clients.md](../clients.md), which checks that a client
connects, not what it does next. What breaks there returns no error. A tool description is
reworded (as the portal's describe tool was, twice, while portal mode was written), `mcp/sdk`
moves a pre-1.0 minor, tools merge as they did under
[0006](0006-one-tool-surface-the-portal-is-a-policy.md), or the client ships a new model. The
agent then picks another tool, stops at the dry run and reports the ticket resolved, or reaches
for a tool the task never needed. Every call succeeds and the audit trail looks normal. Docusign
reports the same class of failure from production, and the harness that caught it
([Making MCP reliable and safe in production](https://www.docusign.com/blog/developers/making-mcp-reliable-and-safe-in-production),
read 2026-10-30).

The proposal has two parts: a check with no model that says when the tool surface changed, and a
harness that drives a real model against a disposable iTop and checks what it did.

- **The surface check.** The integration suite compares what `tools/list` and the server
  instructions answer, for a console token and a portal token, against a committed copy. Any
  difference fails until the copy is updated in the same change, which is the reminder to run the
  harness. It also catches an `mcp/sdk` bump that changes the output without touching this
  repository's text, and it needs no key, so it runs on every pull request.
- **Where.** `tools/evals/`. `tools/` stays out of the archive (`exclude.txt`), and `tests/` would
  not: it ships, so iTop's own suite can find it (guide §8.2). It is a small PHP runner, with the
  cases as data, speaking JSON-RPC over HTTP. It does not use `mcp/sdk`'s client: a bump would
  then move both ends of the connection and could hide what it broke.
- **The instance.** A new `evals` subcommand of `tools/ci/local/run.sh`, on the iTop `matrix`
  installs. It re-runs the setup when the module's code has changed, which `integration` does not
  do, so a reworded description is the one tested. It serves the endpoint for the length of the
  run and restores a database dump before each case, then loads the case's fixtures. Never a
  customer instance: the harness is a client, and what it reads goes to the model provider. README
  "Data flow and privacy" holds only while the data it sends is synthetic.
- **A case** is one prompt, the token, its scope and mode (console or portal), the fixtures, and
  four checks:
  - *order*: only the orderings that matter, such as a dry run before `simulate=false`. Calls
    around them are free: a model that calls `core_current_user` first has done nothing wrong;
  - *allowed set*: any tool outside the set the case names fails it;
  - *step budget*: done within a fixed number of calls, not retried until something works;
  - *end state*: read from the instance after the run, never taken from the agent's account of
    it.
- **A grader model**, from another family than the one under test, is used only where the answer
  is prose. That includes the failure this harness exists for: the server dry-runs unless told
  otherwise (`Service\Write\DryRun`), so an agent that stops there and reports the ticket resolved leaves the
  instance unchanged, and only its message is wrong. The grader gets the narrowest question the
  end state leaves open: nothing landed, so does this message claim it did?
- **Red cases** come from the hostile-agent pass in `review/review-personas.md` (named, not
  linked: `review/` stays out of the archive and this file does not):
  instructions planted in a description, a case log entry or an attachment; a test pack whose
  tool description steers. They allow no committed write and no tool outside the set, in any run:
  no threshold applies, since one write in ten runs is a finding and not a 90% pass. They do not
  replace the server-side barriers, and they do not prove one. A rule that holds only when the
  model obeys is still no control, whatever the eval shows. Proving a barrier takes a scripted
  call in the integration suite, so these cases stay few.
- **Purple cases** check that a refused action is not reached another way, and that a dry run,
  a refusal or a partial bulk result is never reported to the user as done.
- **A case has to be able to fail.** Before a red case's run, the same calls made by script, with
  no model, must commit the write it forbids. If they do not, the token or a barrier already stops
  it, the case tests nothing, and it is reported invalid rather than passed; what it shows belongs
  in the integration suite. A new tool-choice case is trusted once a deliberately degraded
  description has turned it red.
- **What a result is compared with.** Each case runs several times, since a model's run is not
  repeatable, against the last accepted run on the same dated model id. A fixed threshold alone
  does not see drift: with four passes needed out of five, a case that falls from 90% to 60%
  still passes a third of the time, while twenty healthy cases at 90% show at least one false
  red in about four releases out of five. A case that differs is run again on both commits before
  anything is concluded. A new model on the same commit is reported as model drift, not as a
  regression in this repository.
- **When.** By hand, when the surface check says the surface changed, and before a release, as a
  step in [release-checklist.md](../release-checklist.md). Each run reports tokens and latency per
  case, which is the first measure of what clipping and `mcp_pagination_limit` save.
- **What a failure does.** A committed write in a red case, a refused action reached another way,
  or a dry run, refusal or partial result reported as done stops the release until a person has
  read the transcript and either changed the server or recorded why it is not the server's to
  stop. The record goes where `review/review-personas.md` keeps findings, and its added passes
  say what blocks a release. Any other drift is a judgement, recorded the same way.
- **What this does not test.** A model and a bare loop are not a client. Claude Desktop, Cursor
  and the rest add their own prompts, may ignore server instructions and may defer loading tools.
  The human matrix in [clients.md](../clients.md) stays.

Rejected: a blocking check on every pull request. Each run costs money, a model's result varies
from run to run, and a fork's pull request has no API key. A flaky gate gets ignored or
switched off. The surface check is the part that can block, because it has none of these.

Rejected: a grader model for every case. Whether a ticket moved, or a write landed, can be read
exactly from iTop, and a grader would turn an exact answer into a scored one.

Rejected: comparing transcripts or wording, or a full expected sequence of calls. They change
with every model update, which is the drift the harness exists to survive.

Rejected, for now: Promptfoo, which Docusign extended rather than forked. It would bring a Node
toolchain into a PHP repository for a loop this small. Reconsider it if the runner outgrows that.

## Accepted

Decided by Guy Couronne on 2026-11-12, on a smaller scope than the proposal: the part that needs
no key now, and a small harness that runs only with the maintainer's own key. What is and is not
automated:

- **Automated, on every pull request, no key: the surface check.**
  `tests/php-unit-tests/Integration/ToolSurfaceSnapshotTest.php` builds the server for each token
  grade (`MCP`, `MCP-read`, `MCP-write`, `MCP-delete`) and for portal mode, and compares what a
  client is served (the handshake, server instructions included, `tools/list`, the resources,
  resource templates and prompts) with `tests/php-unit-tests/Integration/Snapshots/surface-<grade>.json`.
  It compares this module's own elements only, sorted by name, so a pack on the instance and the
  registry's scan order do not move it. One copy holds on iTop 3.2 and 3.3 (checked on both, which
  answers the question below), so there is no per-branch copy. A difference fails with one line per
  changed path and the command that regenerates the copy; the diff is what a person reads and accepts.
  `CONTRIBUTING.md` says how.
- **By hand, with a key: the harness.** `tools/evals/`, a small PHP runner that plays scenario
  files (`tools/evals/scenarios/*.json`) against a Claude model and an endpoint named by the
  environment, records every tool call and checks them. Three scenarios: a write rehearsed before
  it is committed, a read-only question that stays read-only, and a portal user's task that starts
  from `core_portal_list`. It skips, exit 0, without `ANTHROPIC_API_KEY`. It is not a gate, runs in
  no workflow, and nothing here has yet been run against a model: its model-free parts (scenario
  parsing, the expectation checks, the skip) are under `EvalScenariosTest`.
- **Not built, from the proposal above:** the `evals` subcommand of `run.sh` (the maintainer points
  the runner at a disposable instance himself, and the data a scenario expects is described in the
  scenario, not loaded), restoring a dump between cases, repeated runs and the comparison with a
  last accepted run, the red and purple cases, the validity check that a case can fail, and the step
  in `release-checklist.md`. Each stays as proposed above, to be built when a run shows it earns its
  cost; until then a person reads the report.

The four items the proposal left open, answered:

- **Which models.** Claude, a current id. The runner defaults to `claude-sonnet-5-5` and takes
  `--model` or `MCP_EVAL_MODEL`. No grader model.
- **Whose key.** The maintainer's, from his environment, typed nowhere and committed nowhere. Not in
  CI, unless he adds a secret and a workflow himself.
- **The grader.** Deterministic checks on the tool calls (allowed set, step budget, required calls,
  forbidden calls, first call, order). The proposal's grader model for prose answers is not built:
  what the agent told the user is read from the report by a person.
- **Where baselines live.** In the scenario files, and in the surface snapshots. There is no
  stored result of a past run to compare with.

Still open, and set from what the first runs show: how many runs per case, how far a case may move,
and the cost of a run (three scenarios, one run each, are small; the figure belongs here once there
is one).

Enforced by: `ToolSurfaceSnapshotTest` (the surface check, in the integration suite, so on every
pull request that runs it), and `EvalScenariosTest` (the harness's own parts). The harness's runs
and the `release-checklist.md` step are not enforced by anything.
