# 0018 — A fix for a pre-2.0 line is made on `release/1.x` and carried across the 2.0 layout

- Date: 2026-11-12
- Decided by: Guy Couronne, 2026-11-12 ("Yes, all seven"), on the recommendation below
- Status: accepted

**Context.** [SECURITY.md](../../SECURITY.md) promises five years of security fixes for 1.0 to 1.3.
2.0 moved every class under `src/` ([ADR 0015](0015-2-0-lays-out-src-as-itop-does.md)), so a fix
written against `main` does not apply to a 1.x tree, and nothing said how the promise would be
kept. Left unwritten, it is kept by whoever remembers it, or not at all.

**The decision.**

1. At the 2.0.0 tag a `release/1.x` branch is cut from `v1.3.0`. A fix for a pre-2.0 line is made
   there and released as a 1.3.x patch. There is no branch per minor: 1.3 contains 1.0 to 1.2, and
   a client on an older minor reaches the fix by moving to 1.3.x.
2. A fix is written once and carried across the break, not written twice. The map is the
   [CHANGELOG](../../CHANGELOG.md) 2.0.0 old-to-new name table. The default order is `main` first, then
   `release/1.x`; a defect that exists only in the 1.x code goes to `release/1.x` first, and
   `main` gets a test that it is absent.
3. The 1.x tree is tested as it was released, on the iTop branches and PHP versions its own
   `.github/itop-support.json` and workflows name, not on 2.0's.
4. The steps are in [CONTRIBUTING.md](../../CONTRIBUTING.md) ("Fixing a pre-2.0 line") and the tag-day and
   patch-release rows are in [doc/release-checklist.md](../../doc/release-checklist.md).

**Rejected alternatives.**

- **State in SECURITY.md that 1.x gets critical fixes only.** It shortens a promise customers
  rely on, to save work nobody has yet been asked to do.
- **One maintenance branch per minor (`release/1.0` … `release/1.3`).** Four trees to patch for
  what is one tree plus a version bump, and no one is held on 1.0 by a layout.
- **Cut the branch now.** `v1.3.0` is the base whenever it is cut, and the branch has nothing to
  carry until 2.0.0 exists; it is tag-day work.

**Consequence to know.** SECURITY.md has said that a fix is issued "as a new patch of every minor
still inside its support period". Under this decision it is issued as a 1.3.x patch, and the 1.0 to
1.2 windows keep running because an upgrade to 1.3.x is a minor upgrade inside 1.x. That is a
narrower reading, and SECURITY.md now says it in one sentence.

**Enforced by:** nothing that can run. The `CONTRIBUTING.md` section and the checklist rows are
the whole check; `release.yml` already refuses a tag that disagrees with `extension.xml` or the
changelog, on any branch.
