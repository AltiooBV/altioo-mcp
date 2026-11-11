#!/usr/bin/env python3
"""Set every @since in src/ to the version the symbol actually first appeared in.

Idempotent: it derives the answer from git rather than patching a previous run,
so it converges no matter what has been edited in between. Re-run after a batch
of new work and before cutting a release.

A docblock counts only if it is *attached* — its closing */ sits directly above
the declaration, allowing #[Attribute] lines in between. Without that rule a
method with no docblock of its own walks up into the class docblock, and the two
then fight over a single tag on every run.

Usage: tools/reconcile-since.py <module-root> <baseline-rev> <released> <next> [--follow-moves]

  <module-root>   the module directory, i.e. "." from the repository root
  <baseline-rev>  the git revision the released version was cut at, e.g. v0.9.0
  <released>      the version already published, written on anything present at
                  <baseline-rev> whose docblock there carried no @since of its own
  <next>          the version being prepared, written on anything newer

A symbol present at <baseline-rev> keeps the @since it carried there. That
tag was reconciled when <baseline-rev> was released, so it already names the
version the symbol first appeared in; writing <released> over it instead would
flatten every earlier release into the latest one - which is what happened the
first time this ran with a third version in the history (1.1.1, over a tree
whose tags said 1.0.0 and 1.1.0).

Moves. The lookup is by path, so a class that moved to another folder would read as new
and be stamped <next>. A release that reorganises src/ passes `--follow-moves` as a fifth
argument: the baseline is then indexed by class name, and by member name within the class,
and a symbol found anywhere in it keeps the @since it had there. A class or member that
exists only in the working tree is new. A member name found in several baseline classes with
different tags is reported and left unchanged.

Called by nothing: it rewrites source, so it is run by a person who then reads
the diff. Named in doc/release-checklist.md, under "@since tags".

@copyright   Copyright (C) 2026 Altioo
@license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
"""
import re, subprocess, sys, os

ROOT, BASE, RELEASED, NEXT = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
FOLLOW = len(sys.argv) > 5 and sys.argv[5] == '--follow-moves'

DECL_CLASS = re.compile(r'^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)')
DECL_MEMBER = re.compile(r'^\t(?:(?:final|abstract)\s+)?(?:public|protected)(?:\s+static)?\s+function\s+(\w+)')
RE_SINCE = re.compile(r'@since\s+\d+\.\d+\.\d+')


def baseline_source(rel):
    r = subprocess.run(['git', '-C', ROOT, 'show', f'{BASE}:{rel}'],
                       capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else None


def attached_docblock(lines, i):
    """(start, end) of the docblock closing directly above line i, else None."""
    j = i - 1
    while j >= 0 and lines[j].strip().startswith('#['):
        j -= 1
    if j < 0 or lines[j].strip() != '*/':
        return None
    end = j
    while j >= 0 and not lines[j].strip().startswith('/**'):
        j -= 1
    return (j, end) if j >= 0 else None


def baseline_since(old_lines, is_class, name):
    """The @since the declaration carried at <baseline-rev>, else None."""
    for j, line in enumerate(old_lines):
        m = DECL_CLASS.match(line) if is_class else DECL_MEMBER.match(line)
        if not m or m.group(1) != name:
            continue
        block = attached_docblock(old_lines, j)
        if block is None:
            return None
        for k in range(block[0], block[1] + 1):
            found = RE_SINCE.search(old_lines[k])
            if found:
                return found.group(0).split()[-1]
        return None
    return None


def baseline_index():
    """{class: since} and {class: {member: since}} over every file at <baseline-rev>."""
    classes, members = {}, {}
    names = subprocess.run(['git', '-C', ROOT, 'ls-tree', '-r', '--name-only', BASE, 'src'],
                           capture_output=True, text=True).stdout.split()
    for rel in names:
        if not rel.endswith('.php'):
            continue
        lines = baseline_source(rel).split('\n')
        current = None
        for j, line in enumerate(lines):
            mc, mm = DECL_CLASS.match(line), DECL_MEMBER.match(line)
            if not (mc or mm):
                continue
            block = attached_docblock(lines, j)
            since = None
            if block:
                for k in range(block[0], block[1] + 1):
                    found = RE_SINCE.search(lines[k])
                    if found:
                        since = found.group(0).split()[-1]
                        break
            if mc:
                current = mc.group(1)
                classes[current] = since or RELEASED
                members.setdefault(current, {})
            elif current:
                members[current][mm.group(1)] = since or RELEASED
    return classes, members


INDEX = baseline_index() if FOLLOW else None
ambiguous = []


def followed(cls, name, is_class):
    """The tag a moved symbol keeps, or None when it is new."""
    classes, members = INDEX
    if is_class:
        return classes.get(name)
    if name in members.get(cls, {}):
        return members[cls][name]
    found = {m[name] for m in members.values() if name in m}
    if len(found) > 1:
        ambiguous.append(f'{cls}::{name} {sorted(found)}')
        return 'ambiguous'
    return found.pop() if found else None


changed = files = 0
missing = []
for dirpath, _, filenames in os.walk(os.path.join(ROOT, 'src')):
    for fn in sorted(filenames):
        if not fn.endswith('.php'):
            continue
        path = os.path.join(dirpath, fn)
        rel = os.path.relpath(path, ROOT)
        old = baseline_source(rel)
        old_lines = old.split('\n') if old is not None else []
        lines = open(path, encoding='utf-8').read().split('\n')
        dirty = False

        cls = None
        for i, line in enumerate(lines):
            mc = DECL_CLASS.match(line)
            mm = DECL_MEMBER.match(line)
            if not (mc or mm):
                continue
            if mc:
                cls = mc.group(1)

            if FOLLOW:
                want = followed(cls, (mc or mm).group(1), bool(mc)) or NEXT
                if want == 'ambiguous':
                    continue
            elif old is None:
                want = NEXT                       # the whole file postdates the release
            elif mc:
                # File existed, so its class did, at whatever version it says.
                want = baseline_since(old_lines, True, mc.group(1)) or RELEASED
            elif re.search(r'function\s+' + mm.group(1) + r'\b', old):
                # A changed signature keeps its original @since; only absence is new.
                want = baseline_since(old_lines, False, mm.group(1)) or RELEASED
            else:
                want = NEXT

            block = attached_docblock(lines, i)
            if block is None:
                missing.append(f'{rel}:{i + 1}')
                continue

            start, end = block
            for k in range(start, end + 1):
                if RE_SINCE.search(lines[k]):
                    fixed = RE_SINCE.sub('@since ' + want, lines[k])
                    if fixed != lines[k]:
                        lines[k] = fixed
                        changed += 1
                        dirty = True
                    break
            else:
                missing.append(f'{rel}:{i + 1} (docblock, no @since)')

        if dirty:
            open(path, 'w', encoding='utf-8').write('\n'.join(lines))
            files += 1

for a in ambiguous:
    print('ambiguous, left unchanged: ' + a)
print(f'reconciled {changed} tags across {files} files')
if missing:
    print(f'{len(missing)} declarations carry no @since of their own:')
    for m in missing:
        print('  ' + m)
