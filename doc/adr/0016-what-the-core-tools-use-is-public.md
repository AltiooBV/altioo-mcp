# 0016 — What the core elements use is public: a pack builds with the same classes as the core tools

- Date: 2026-11-11
- Decided by: Guy Couronne, 2026-11-11 ("everything that the core tools depends on, make it public
  so that for extending, it's allowed")
- Status: accepted
- Supersedes: rule 3 of [ADR 0015](0015-2-0-lays-out-src-as-itop-does.md), "`@api` covers what a pack
  needs and nothing else". The rest of 0015 stands.

**Context.** ADR 0015 narrowed `@api` to `src/Api/` and the four `Service` classes the example
pack calls. The persona review of 2.0 on 2026-11-11 found what that cost a pack author. A pack
tool that reads object data with iTop's own API does not apply the operator's exclusions (ADR 0012),
because `ExclusionPolicy` was no longer on the surface, and nothing else a core tool asks before it
answers was either: the barrier on access-granting classes, the change-log reservation, the
access question in either mode, the write policies. A pack could subclass a core tool (ADR 0014
says packs do) but could not call what that tool calls.

**The decision.** A class is on the surface a pack may build on, marked `@api`, when it is:

1. under `src/Api/`, as before;
2. a class that an element under `src/Core/` (a tool, resource, template or prompt) depends on;
3. a class that a public or protected signature of a class from 1 or 2 names as a parameter or
   return type, or that one of them extends or implements, so that a pack can call what it was given.
   A type named only in a docblock does not count, as it does not for `LayerDependencyTest`;
4. `Service\Write\RestValue`, which no core element uses but the example pack does, as it has since 1.x.

Everything else stays `@internal`: the controller and its gates, the server, the background process,
the audit summaries the controller writes, and the internals those surface classes use without
exposing. A member of a surface class can be `@internal` too: the `Remember()` and `Forget()` methods
that hold a request's state belong to `Service\Server\RequestScope`, and say so. `Core` itself stays
`@internal`. A pack subclasses a core tool as ADR 0014 describes, and its signatures never narrow,
but the tools themselves are not a library to call.

The reason is the one the maintainer gave: a pack should be allowed to do what a core tool does,
with the same classes, rather than reimplement it with iTop's API and miss a check.

**What follows.**

- **Semver now covers 42 classes outside `Api/`**, not four. Restructuring any of them is a major
  release. The layer order of ADR 0015 still holds, and a class can move down a layer only in a major.
- **A pack that returns object data asks `Service\Rights\ExclusionPolicy`**, as a core tool does. The
  rule of ADR 0012, "absent on every route", now applies to a pack's routes too, and
  [doc/extending.md](../extending.md) says how.
- **Portal mode is unchanged.** `Service\Portal\PortalAware` and `PortalPolicy` are on the surface, but
  `PortalAccess::Serves()` still serves only this module's own elements in portal mode, whatever
  implements the interface. Serving a pack's element to portal users is a separate decision.
- **ADR 0014 is unchanged.** A security check stays in the tool's own source. A pack tool calls the same
  checks in its own source, and the example pack's suite is where that is shown.

**Rejected alternatives.**

- **Keep the four classes and add `ExclusionPolicy` alone.** That closes the gap the review found and no
  other. The next check a pack needs would be another request to widen the list.
- **Facades in `Api/`** over the checks. ADR 0015 already rejected them, for the same reason: a wrapper to
  maintain with no behaviour of its own.
- **Make `Core` public too.** Packs subclass core tools but have no reason to call one, and every
  helper method of a tool would become a promise.

**Enforced by:** `PublicSurfaceTest`. Its `EXTRA_SURFACE` lists the classes outside `Api/`. A test fails
when an element under `src/Core/` depends on a class that is not on the surface, when a surface class's
public signature names one that is not, and when a listed class is used by none of `src/Core`, a
surface signature and the example pack. The list stays written out rather than derived, so growing
the surface is a reviewed change.
