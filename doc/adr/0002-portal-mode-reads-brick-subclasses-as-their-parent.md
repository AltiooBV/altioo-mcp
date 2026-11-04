# 0002 — Portal mode reads a brick subclass as the stock type it extends

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

Portal mode decides what it offers from the bricks granted to the user
([0001](0001-portal-mode-bricks-bound-discovery.md)). It reads three kinds of brick, because
they are the ones whose configuration says what data they show: a ManageBrick's OQL and tabs,
a BrowseBrick's levels and their `create_from_this` actions, a CreateBrick's class and rules.
FilterBrick, AggregatePageBrick and UserProfileBrick hold no data of their own and reach
nothing.

An extension can declare its own brick class (`xsi:type`), and that class can extend one of
the three. The decision is that **by default such a subclass is read as the type it extends**:

- it declares its data the way its parent does, in the same XML, and an operator who
  configured it expects it to behave like its parent;
- it cannot widen what is read: reads stay bounded by the scopes, as the portal's own object
  pages are, whatever the subclass's controller adds;
- a class extending none of the three - one built on `PortalBrick` directly - reaches nothing,
  and neither does a `create_from_this` whose class is chosen by a method. What those do is
  PHP, and is never guessed.

The brick is still loaded and its grant still tested by iTop's own `BrickCollection`: a brick
class that cannot be loaded outside the portal's kernel stops the whole portal from being read,
and portal mode then serves nothing for it.

**The exception belongs to the brick's own extension** (decided the same day). A subclass whose
controller reads its configuration differently is not read as its parent only when the
extension that ships it says so, by supplying its portal-mode equivalent through the pack API
(design §3.3, "Extensions that add portal features"). That extension is the one that knows
what its controller does. No module setting does it: the operator would have to know a brick's
PHP to fill one in, and portal mode keeps no configuration of its own. Until the pack API
offers that, every subclass is read as its parent.

Rejected: recognising the exact stock classes only. It guesses nothing, but drops every
customisation made by subclassing, including the ones that change only the layout.

Enforced by: `PortalModeTest::testTheStockBrickTypesAreReadAndNothingElse` (unit suite) and
`PortalPolicyTest::testACustomBrickClassIsReadAsTheStockTypeItExtends` (integration suite), on
`PortalPolicy::BrickKind()`, which every brick goes through.
