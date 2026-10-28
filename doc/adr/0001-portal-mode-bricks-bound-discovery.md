# 0001 — Portal mode: granted bricks bound discovery, scopes bound reads

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

Portal mode serves a portal-only user through the endpoint (see the README, "Portal-only users:
portal mode"). The iTop portal enforces two things, and they do different jobs:

- **Scopes** decide what can be read or written. The portal's object pages check only the scope
  and `UserRights` (`SecurityHelper::IsActionAllowed`), never a brick. So a portal user can open
  any in-scope object by URL, and can create any class they have a write scope for, whether or
  not a tile lists it.
- **Bricks** decide what is offered. A brick is granted when it is active and its `<security>`
  allowed and denied profile lists pass for the user's profiles; a denied profile always wins.
  A brick's own page refuses a user it is not granted to. Its OQL is intersected with the read
  scope, and so are a ManageBrick's tabs and grouping areas and each BrowseBrick level.

Operators read a missing tile as "they cannot see this", even though the portal does not enforce
it on the object pages. The decision is to follow that reading, because it is narrower:

- **describe, search by class and create** cover only the classes that a brick granted to the
  user reaches: a ManageBrick's class and its grouping areas' classes, a BrowseBrick level's
  class, a CreateBrick's class, a `create_from_this` class, and the subclasses of each. A class
  that no granted brick reaches is refused, even if the user has a read scope on it.
- **Reads by id, lookups and linked rows** follow the scopes, as the portal does. Otherwise a
  ticket's caller, service or organisation would stop resolving.
- **Brick lists** are run on the server, as the brick defines them and intersected with the read
  scope. The client names a list and never sends OQL. In a BrowseBrick, each parent id on a
  path is checked against its own level's results.
- **`visible/home` and `visible/navigation_menu` are layout**, and are ignored. The stock "search
  within tickets" FilterBrick works through `manage-all-tickets-for-portal-user`, which is hidden
  from both and still granted.
- If a portal's bricks cannot be loaded, portal mode serves nothing for that portal. It never
  falls back to scopes alone.

Rejected: mirroring the portal, where search follows the scopes and bricks only shape what
describe offers. It is never wider than the portal, but it is wider than what the operator
believes they configured.

Enforced by: `PortalPolicyTest::testAClassWithAReadScopeThatNoBrickReachesIsNotOffered`
(integration suite), on describe since 1.2.0: in the stock portal the user's own organisation
has a read scope and no brick, and is neither listed nor described. The step that adds search
by class extends it to search.
