#!/usr/bin/env bash
#
# Combodo's own validation suite for third-party modules, run against the
# instance install-itop.sh just built.
#
# It is their test, run against our module, which makes it the one check in the
# matrix we did not write and cannot accidentally make agree with us. That is
# also why the config is not edited: what it asserts has to stay theirs.
#
# What this script adds is tolerance of one thing, and only one:
# module_integration.xml.dist names its test files explicitly, and a branch can
# name one that the branch does not ship. 3.3.0 does - it lists
# integration-tests/iTopModulesDependencyValidationServiceTest.php, which exists
# in no 3.3.0 archive, in no 3.3.0 source tag, and on develop either. PHPUnit
# treats a missing <file> as fatal at config-load time, so one absent file takes
# the whole suite with it and the two tests that do exist never run.
#
# Dropping the step over that would gate nothing on every branch; letting it stay
# red would be an expected red, which nobody reads. So the listed files are
# partitioned into present and missing, the present ones run under an otherwise
# untouched copy of Combodo's config, and the missing ones are named in the
# output. The step still fails if the suite fails, if the config names no files
# at all, or if none of them exist - the cases where "tolerated" would mean
# "checked nothing". It needs no attention the day Combodo ships the file: it is
# present, so it runs.
#
#   ITOP_DIR=/path/to/itop tools/ci/module-validation.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

ITOP_DIR="${ITOP_DIR:?ITOP_DIR must point at the installed iTop}"
HARNESS="$ITOP_DIR/tests/php-unit-tests"
CONFIG=module_integration.xml.dist

[ -f "$HARNESS/$CONFIG" ] || {
	echo "::error::$HARNESS/$CONFIG is missing - the harness was not overlaid from the tag"
	exit 1
}

cd "$HARNESS"
composer install --no-interaction --no-progress >/dev/null

# The <file> entries of the testsuite, in the order the config lists them -
# scoped to <testsuites>, because <filter><whitelist> names files with the same
# tag and those are coverage targets, not tests. A config that names no test
# files is a config whose shape has changed: fail rather than run an empty suite
# and report it green.
aListed=()
while IFS= read -r sFile; do
	aListed+=("$sFile")
done < <(awk '/<testsuites>/{f=1} /<\/testsuites>/{f=0} f' "$CONFIG" \
	| grep -oE '<file>[^<]+</file>' | sed -E 's#</?file>##g')

if [ ${#aListed[@]} -eq 0 ]; then
	echo "::error::$CONFIG names no <file> entries - its shape has changed, and this script reads it"
	exit 1
fi

aPresent=()
aMissing=()
for sFile in "${aListed[@]}"; do
	if [ -f "$sFile" ]; then aPresent+=("$sFile"); else aMissing+=("$sFile"); fi
done

if [ ${#aMissing[@]} -gt 0 ]; then
	echo "::warning::$CONFIG names ${#aMissing[@]} test file(s) this iTop does not ship; they cannot run:"
	printf '  %s\n' "${aMissing[@]}"
fi

if [ ${#aPresent[@]} -eq 0 ]; then
	echo "::error::none of the ${#aListed[@]} test files $CONFIG names exist - nothing would be validated"
	exit 1
fi

echo "running ${#aPresent[@]} of ${#aListed[@]} test files Combodo's $CONFIG names:"
printf '  %s\n' "${aPresent[@]}"

# Their config, minus the lines naming files that are not there. A copy rather
# than an edit, beside the original because every path in it - the bootstrap and
# the <file> entries alike - resolves relative to the config's own directory.
# Everything else it sets is what decides how strict the run is:
# convertWarningsToExceptions and the E_ALL ini among them, which is why the
# suite is not run with --no-configuration and a file list instead.
sFiltered="$CONFIG.present-only"
cp "$CONFIG" "$sFiltered"
for sFile in "${aMissing[@]}"; do
	awk -v sDrop="<file>$sFile</file>" '
		/<testsuites>/{f=1} /<\/testsuites>/{f=0}
		!(f && index($0, sDrop)) {print}
	' "$sFiltered" > "$sFiltered.tmp"
	mv "$sFiltered.tmp" "$sFiltered"
done

php vendor/bin/phpunit -c "$sFiltered"
