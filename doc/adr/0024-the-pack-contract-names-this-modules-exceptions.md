# 0024 — The pack contract names this module's exceptions, and the SDK's 0.9 names are aliased until 3.0

- Date: 2026-11-20
- Decided by: Guy Couronne, 2026-11-20 ("A and C together", on the SDK 0.10 check, `review/2026-11-20-sdk-0.10/check.md`)
- Status: accepted

**Context.** `doc/extending.md` told a pack to throw `Mcp\Exception\ToolCallException` and `Mcp\Exception\ResourceReadException`, so
two classes of `mcp/sdk` were part of the pack contract. `mcp/sdk` 0.10 moved them (to `Mcp\Server\Exception\`) with no alias, and says the
move is "ahead of separate packages": the namespaces, and perhaps the package a pack pins, will move again. Without a change every pack
written to the guide answers each call of a tool that throws with `-32603 Error while executing tool`, and its `catch` of the old name
silently stops matching. The SDK declares both classes `final`, in 0.9 and in 0.10, so this module cannot give packs a subclass.

**The decision.**

1. **The pack contract names this module's types, not the SDK's.** `Api\ToolRefusal` and `Api\ResourceRefusal` (`@api`, since 2.1.0):
   `throw ToolRefusal::Because('...')`. Because the SDK's classes are `final`, they are not exception types that extend the SDK's: `Because()`
   returns the SDK's own exception, which is what the SDK catches and answers with the message, and `Is()` asks whether something thrown is one.
   The SDK's name is written in those two files and nowhere a pack or this module's tools look. The module's own code throws through them
   (core tools, `AbstractObjectSearch`, `AbstractBulkTool`, the resources). Where the module *catches* what the SDK throws it keeps catching
   the SDK's class.
2. **The SDK's 0.9 names are aliased until 3.0.** `sdk-aliases.php` at the module root, loaded by composer's `autoload.files`, gives the 24
   server-side names of `Mcp\Exception\` (19 that moved to `Server\`, and the five base names a pack could have caught, mapped to their `Server`
   variants) back, so a pack written against 0.9 behaves as it did. Eager, because PHP does not autoload a class for a `catch` or an
   `instanceof`. It is deprecated: `Testing\ElementContract` warns a pack that names one, or throws the SDK's class directly. Removed in 3.0.
3. **Any later SDK rename of a name a pack can reach goes on the alias list, with its test**, in the release that takes the SDK that renames it:
   `sdk-aliases.php`, `SdkAliasesTest::ALIASED`, the CHANGELOG. A name a pack cannot reach (the builder, the registry, the events, the client) does not.
   `ToolAnnotations` and `Annotations` (`Mcp\Schema\`) stay in the contract as the SDK's own types: they are data a pack needs to grade its tools.
4. **The dev pin is `MCPModule::SDK_CONSTRAINT`**, held to `composer.json` by `ModuleMetadataTest`.

**Rejected.**

- **Aliases only.** Packs would change their code twice: once to the new SDK names, once when the SDK moves them again or the contract drops them.
- **Staying on 0.9 until 3.0.** 2.x would get no SDK fixes; the two advisories on `mcp/sdk` so far came three months apart. Whatever the next one
  touched would have to be ported in an emergency, as a patch.
- **A subclass of the SDK's exceptions** (the first form of this record's decision): the SDK declares them `final`.
- **A module exception the SDK does not know, converted at the edge.** The SDK maps arguments to parameters by reflection on the handler it is
  given, so a wrapper closure cannot stand in for `execute()` without losing its signature.

**Consequences.** A pack on 2.0 or 2.1 keeps working and gets a warning from its own contract test; in 3.0 the aliases go and the pack must have
moved. The module is the only place that names the SDK's exceptions, so a rename costs two classes and a table.

Enforced by: `SdkAliasesTest` (every old name resolves to the class it moved to, the file holds exactly the table, composer loads it and `exclude.txt`
does not drop it, a pack written against 0.9 is answered as before through the real SDK), `ElementContractCheckerTest` (the two warnings), and
`ModuleMetadataTest::testThePackConstraintOnTheSdkIsTheOneTheModuleRequires`. That the module's own code throws through the two classes is not
machine-checked.
