#!/usr/bin/env bash
#
# Says whether this repository's copies of the shared documents still match
# the template's.
#
# doc/itop-extension-guide.md and doc/itop-branch-notes.md are the same text
# in every extension made from altioo-itop-extension: the guide carries the
# review personas every iTop extension faces, and a repository's
# review/review-personas.md adds to them rather than repeating them. Each
# repository holds its own copy, and nothing kept the copies in step - they
# were diffed and copied by hand, and a copy that fell behind, or was edited
# without going back upstream, said nothing.
#
# A difference is reported, not resolved. Either side can be the newer one -
# a repository that re-verifies the branch notes against a new iTop release
# is ahead of the template until it sends them upstream - so the message says
# both ways out.
#
#   tools/ci/shared-docs-check.sh            warn on a difference, exit 0
#   SHARED_DOCS_STRICT=true tools/ci/...     exit 1 on a difference
#
# A template that cannot be fetched is a warning in both modes: it says
# nothing about this repository. TEMPLATE_REPO and TEMPLATE_REF name another
# upstream, for a fork of the template.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

TEMPLATE_REPO="${TEMPLATE_REPO:-AltiooBV/altioo-itop-extension}"
TEMPLATE_REF="${TEMPLATE_REF:-main}"
STRICT="${SHARED_DOCS_STRICT:-false}"
aShared=(doc/itop-extension-guide.md doc/itop-branch-notes.md)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

iDiffering=0
for f in "${aShared[@]}"; do
	sUrl="https://raw.githubusercontent.com/${TEMPLATE_REPO}/${TEMPLATE_REF}/${f}"
	if ! curl -fsSL --max-time 30 -o "$TMP/upstream" "$sUrl"; then
		echo "::warning::could not fetch ${f} from ${TEMPLATE_REPO}@${TEMPLATE_REF}; not compared"
		continue
	fi
	if [ ! -f "$f" ]; then
		echo "::warning file=${f}::${f} is missing here; the template has it"
		iDiffering=$((iDiffering + 1))
		continue
	fi
	if cmp -s "$f" "$TMP/upstream"; then
		echo "ok   ${f} matches ${TEMPLATE_REPO}@${TEMPLATE_REF}"
		continue
	fi
	iDiffering=$((iDiffering + 1))
	sCount="$(diff "$TMP/upstream" "$f" | grep -c '^[<>]' || true)"
	echo "::warning file=${f}::${f} differs from ${TEMPLATE_REPO}@${TEMPLATE_REF} (${sCount} lines). Bring the template's copy in, or send this one upstream - whichever is newer."
	diff -u --label "template/${f}" --label "${f}" "$TMP/upstream" "$f" | sed -n '1,40p' || true
done

if [ "$iDiffering" -gt 0 ] && [ "$STRICT" = true ]; then
	echo "${iDiffering} shared document(s) differ from the template" >&2
	exit 1
fi
