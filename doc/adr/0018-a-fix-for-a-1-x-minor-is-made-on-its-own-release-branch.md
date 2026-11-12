# 0018 — A fix for a pre-2.0 minor is made on that minor's own `release/1.N` branch and carried across the 2.0 layout

- Date: 2026-11-12
- Decided by: Guy Couronne, 2026-11-12 ("Yes, all seven", then "Every minor" on the wording of the patches)
- Status: accepted

**Context.** [SECURITY.md](../../SECURITY.md) promises five years of security fixes for 1.0 to 1.3,
as a new patch of every minor still inside its window. 2.0 moved every class under `src/`
([ADR 0015](0015-2-0-lays-out-src-as-itop-does.md)), so a fix written against `main` does not apply
to a 1.x tree, and nothing said how the promise would be kept. Left unwritten, it is kept by whoever
remembers it, or not at all.

**The decision.**

1. At the 2.0.0 tag one maintenance branch is cut per supported minor, from the latest tag of that
   minor: `release/1.0` from `v1.0.2`, `release/1.1` from `v1.1.2`, `release/1.2` from `v1.2.0`,
   `release/1.3` from `v1.3.0`. A fix ships as a real patch of each minor still inside its window:
   1.0.x, 1.1.x, 1.2.x and 1.3.x. When a minor's window ends its branch is archived and gets no more tags.
2. A fix is written once and carried, not written again. A defect in code that 2.0 shares is fixed on
   `main` and carried back; one that exists only in the 1.x code is fixed on the oldest minor it
   affects and carried up, ending on `main` with a test that it is absent. The 1.x minors share the
   1.x layout, so carrying between them is a cherry-pick; only the step to or from `main` crosses the
   break, and its map is the [CHANGELOG](../../CHANGELOG.md) 2.0.0 old-to-new name table.
3. Each branch is tested as released, on the iTop branches and PHP versions its own
   `.github/itop-support.json` and workflows name. They differ: 1.0 claims iTop 3.2 only, 1.1 to 1.3
   claim 3.2 and 3.3.
4. The steps are in [CONTRIBUTING.md](../../CONTRIBUTING.md) ("Fixing a pre-2.0 line"), and the tag-day
   and patch-release rows are in [doc/release-checklist.md](../../doc/release-checklist.md).

**Rejected alternatives.**

- **A single `release/1.x` from `v1.3.0`, every fix tagged 1.3.x.** One tree to patch, but a client on
  1.0 to 1.2 would have to take a minor to get a fix, which is not what SECURITY.md's "a new patch of
  every minor" says. The maintainer chose to keep that wording as written.
- **State in SECURITY.md that 1.x gets critical fixes only.** It shortens a promise customers rely on,
  to save work nobody has yet been asked to do.
- **Cut the branches now.** Each base is a tag, so nothing is lost by waiting, and the branches have
  nothing to carry before 2.0.0 exists; it is tag-day work.

**Consequence to know.** Up to four patches per fix, one per supported minor, each with its own
changelog entry, tag and release run. The cost is real and accepted: it falls only on a fix that
applies to a minor's code, and each window ends on its own date, so the work shrinks as 1.0 (2031-09-20)
and then 1.1 and 1.2 leave support. SECURITY.md needs no change of promise, only a pointer to where
the patches are made.

**Enforced by:** nothing that can run. The `CONTRIBUTING.md` section and the checklist rows are
the whole check; `release.yml` already refuses a tag that disagrees with `extension.xml` or the
changelog, on any branch.
