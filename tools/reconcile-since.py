#!/usr/bin/env python3
"""Set every @since in src/ to the version the symbol actually first appeared in.

Idempotent: it derives the answer from git rather than patching a previous run,
so it converges no matter what has been edited in between. Re-run after a batch
of new work and before cutting a release.

A docblock counts only if it is *attached* — its closing */ sits directly above
the declaration, allowing #[Attribute] lines in between. Without that rule a
method with no docblock of its own walks up into the class docblock, and the two
then fight over a single tag on every run.

Usage: reconcile_since.py <module-root> <baseline-rev> <released> <next>
"""
import re, subprocess, sys, os

ROOT, BASE, RELEASED, NEXT = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]

DECL_CLASS = re.compile(r'^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+\w+')
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


changed = files = 0
missing = []
for dirpath, _, filenames in os.walk(os.path.join(ROOT, 'src')):
    for fn in sorted(filenames):
        if not fn.endswith('.php'):
            continue
        path = os.path.join(dirpath, fn)
        rel = os.path.relpath(path, ROOT)
        old = baseline_source(rel)
        lines = open(path, encoding='utf-8').read().split('\n')
        dirty = False

        for i, line in enumerate(lines):
            mc = DECL_CLASS.match(line)
            mm = DECL_MEMBER.match(line)
            if not (mc or mm):
                continue

            if old is None:
                want = NEXT                       # the whole file postdates the release
            elif mc:
                want = RELEASED                   # file existed, so its class did
            else:
                # A changed signature keeps its original @since; only absence is new.
                want = RELEASED if re.search(r'function\s+' + mm.group(1) + r'\b', old) else NEXT

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

print(f'reconciled {changed} tags across {files} files')
if missing:
    print(f'{len(missing)} declarations carry no @since of their own:')
    for m in missing:
        print('  ' + m)
