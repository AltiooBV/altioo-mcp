# 0017 — A pack's element may be served in portal mode when the operator lists it

- Date: 2026-11-12
- Decided by: Guy Couronne, 2026-11-12 ("Yes, ADR and code"), on the proposal below
- Status: accepted
- Amended: 2026-11-12, after a red-team pass, by the maintainer's "do the whole backlog". (1) An element that took its
  identifier with `overrides()` is admitted by its **class name** only, never by the identifier: the operator's consent
  goes to a class, not to whatever holds a name. (2) An identifier entry admits a `PortalAware` element of any kind (tool,
  prompt, resource, template) that holds that identifier, because a pack's namespace is not owned and the setting is one
  flat list; the class-name form is the precise one and the README and `doc/extending.md` recommend it. (3) The audit
  row names the **identifier called** and the portal, not the class that ran (see the last bullet of "What does not
  change"). (4) `ElementContract` counts only the pack's own source, not this module's parent classes.
- Supersedes: the point "Portal mode is unchanged" of [ADR 0016](0016-what-the-core-tools-use-is-public.md).
  The rest of 0016 stands.

**Context.** Portal mode serves only this module's own elements: `PortalAccess::Serves()` admits an
element that implements `Service\Portal\PortalAware` and sits under `Core\`. The namespace check was
there because a pack could not reach the portal's rules: `PortalPolicy` and the mode-aware
`ObjectAccess` were internal. ADR 0016 made them public on 2026-11-11, so a pack can now build a tool
that answers by the portal's rules. The maintainer asked why portal mode should not be extended so
that people can add their own tools for the portal.

The risk is the one the namespace check guarded against. iTop's "Portal user" profile reads every
bizmodel class through `UserRights`. A pack tool that checks access with `UserRights` alone, as
`doc/extending.md` long advised, would show a portal user the whole CMDB. `PortalAware` is a
declaration, and the server cannot verify that a tool keeps it.

**The decision.**

1. **The operator opts in, element by element.** A new module parameter, `mcp_portal_extensions`, lists
   the pack elements portal mode may serve, by identifier (`acme_ticket_add_log_entry`) or by class
   name, the two forms `mcp_disabled_tools` accepts. Its default is empty, so an upgrade serves
   nothing new. A value that is not a list of strings opens nothing and is logged. It can only widen
   what portal mode serves, so failing closed means serving no pack element, not stopping the endpoint.
2. **`PortalAccess::Serves()` admits** an element that implements `PortalAware` and is either under
   `Core\` or listed. Listing an element that does not implement `PortalAware` serves nothing: the
   declaration and the operator's consent are both required. Every other filter still applies after
   it: `mcp_disabled_tools`, the toolsets, the grades, the token scope, the exclusions (ADR 0012).
3. **A portal-aware pack element answers through the portal's rules.** It asks
   `ObjectAccess::Current()`, which in portal mode is the portal's access, for every object it reads
   or writes (`Readable()`, `Bounded()`, `Hides()`, `RefusalToSet()` and the other `RefusalTo…()`
   methods), never `UserRights` alone. A tool written that way serves both modes with one code path,
   as the core tools do (ADR 0006).
4. **`Testing\ElementContract` reports a portal-aware pack element whose source does not call
   `ObjectAccess::Current()`.** It reads the PHP tokens, as this module's contract tests do (ADR 0014).
   The check is necessary, not sufficient. It catches the tool that never asks, not the one that asks and
   ignores the answer. The operator's listing is the control; the contract is the pack author's help.

**What does not change.**

- "This module's own" means a class defined in a file under its own `src/Core/`
  (`Helper\CoreElement::IsOwn()`, amended 2026-11-12), not one that declares the `Core\` PHP namespace:
  a pack can declare that from its own autoloader. The same test guards the reserved `core` tool
  namespace and `ElementContract`'s portal check.
- ADR 0006 and ADR 0010 bind this module's own tools: no `core_portal_*` copy of a console tool, and
  `core_my_activity` stays the one core element with no portal screen behind it. What a listed pack
  element serves is its author's design and the operator's choice for that instance.
- ADR 0011: a portal attachment is read and added, never deleted, and a pack element is held to it
  through `ObjectAccess::RefusalToAttach()` like the core's.
- A portal user still gets no right iTop does not give them. A mistake in a pack element reaches portal
  users only if the operator listed it. The configuration file shows that, and the audit row names
  the identifier the client called, and the portal, on every call. It does not name the class, so an override of a core
  identifier reads as the core tool there; the override is written to the log once, when the registry is collected
  (`MCPExtensionCollector::CollectAll()`, `src/Api/MCPExtensionCollector.php:84`: "<identifier>: <class> replaced <class>, as it declares"),
  and the class-name listing in the configuration file is what names the class. No column was added.

**Rejected alternatives.**

- **Serve every `PortalAware` element.** The declaration is unverifiable, and one careless pack would
  open the CMDB to every portal user on every instance it is installed on.
- **A per-pack switch** (list the provider, not the element). It is coarser than the operator needs:
  a pack that adds a read tool and a write tool should not get both by naming the pack.
- **Stop the endpoint on a malformed list,** as the exclusions do (ADR 0012). Exclusions take data away,
  so not honouring them would leak. This list only adds, so honouring none of it is the safe reading.

**Enforced by:** unit tests of `PortalAccess::Serves()` (core, listed, unlisted, listed without
`PortalAware`, malformed setting), the `ElementContract` check with a fixture that passes and one that
fails (and, for a subclass of a core tool, one that replaces `execute()` and one that does not),
`PortalExtensionsListingTest` for the override and kind rules and the log's causes, and the example pack's suite if it gains a portal-aware element. Whether a listed element
really keeps to the portal's rules is not machine-checkable.
