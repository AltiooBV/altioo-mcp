# 0022 — An advisory credential is not served a tool pack's write tools

- Date: 2026-11-19
- Decided by: Guy Couronne, 2026-11-19 ("yes for advisory", on the persona review's PA-1)
- Status: accepted

**Context.** A token scoped `MCP-advisory` must never write. The scope was enforced inside each write tool: `DryRun::Simulated()` turns
the call into a rehearsal whatever the caller sent. This module's own tools all call it. A tool pack's write tool does so only if its
author knew to, and nothing could check it: the documentation did not say so, the example pack did not call it, and a pack's tool written
from them committed under an advisory credential with `simulate: false` (review 2026-11-18, PA-1). The README promised that every
write tool it can reach rehearses.

**The decision.** An `MCP-advisory` credential is **not served a pack's write-graded tools.** A pack's element is one that is not this
module's own (`Helper\CoreElement::IsOwn()`); write-graded is any tool whose annotations do not make it read-only (a tool that claims
nothing grades as delete, as before). It is decided where the served set is decided, `Service\Server\ServedElements::isHidden()`, so
such a tool is absent from `tools/list`, from the registry the SDK routes by, and from `core_current_user`'s account of what is served; called by
name it is answered exactly as a tool that does not exist. A pack's read-only tools are still served. Core tools are unchanged: they are
served and rehearse through `DryRun::Simulated()`. A pack that does call `DryRun::Simulated()` is withheld too, since the server cannot
know that it does. The `ElementContract` warning for a write tool that never calls it stays: it is what tells the author, and it is still
the author's duty for every non-advisory reason (the audit row's `simulate`, `forced_by_credential`).

Rejected: serving the tool and trusting the pack (the state this replaces: a guarantee as good as the weakest installed pack).
Rejected for now: an opt-in by which a pack declares that it rehearses, so that an advisory credential may be served that one. Nothing
installed needs it, and an opt-in is a declaration the server cannot verify, which is the fault of the state replaced. **If a real pack
needs it, that is the path:** a declared capability on the tool base class, honoured by `isHidden()`, with this record amended.

**Operator-visible.** An instance with packs installed that issues advisory tokens finds the pack's write tools gone from those tokens
and the CHANGELOG says so. `mcp_disabled_tools` is no longer the interim advice.

Enforced by: `AdvisoryPackToolsTest` (the pack's write tool and one that claims nothing are hidden, its read tool and every core tool are
served, a non-advisory credential is served as before).
