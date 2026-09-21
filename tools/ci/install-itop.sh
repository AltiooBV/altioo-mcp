#!/usr/bin/env bash
#
# Installs an iTop of a given tag, with this working copy of the module in its
# extensions/ directory, using iTop's own unattended installer.
#
# The point is not that a script can install iTop. It is that the module is
# picked up, compiled and installed *by the setup itself* - the same code path
# an administrator runs - and that the run says so out loud. A setup that
# quietly skips an extension it cannot select still exits 0 and still prints
# "installed!", so the checks at the bottom of this file are the actual test.
#
# The iTop is the packaged release:
#
#   ITOP_ZIP_URL=https://...  the archive, as an administrator downloads it
#   ITOP_TAG=3.2.3-2          optional: the matching git tag, for its test harness
#
# Not the git tree, and this is worth being explicit about because the git tree
# is the tempting choice. Combodo does not assemble a release from that
# repository alone: authent-token - which this module declares a dependency on -
# is in no source tag, at 3.2.2 or at 3.2.3. Install from a source tree and the
# setup finds the dependency unsatisfiable, drops this extension without failing,
# and every check downstream is then testing an iTop that does not have the
# module in it.
#
# ITOP_TAG adds one thing to that release: tests/php-unit-tests/, which packaged
# releases do not carry and which holds the ItopDataTestCase every integration
# test here extends. Without it those tests skip themselves - green, and proving
# nothing.
#
# Set DRY_RUN_ONLY=1 to stop after the checks that need no database - see the
# dry run below. That is the first gate in CI: it answers "would this extension
# be installed at all" in under a minute, on every supported version, with no
# database service, no schema and nothing to tear down.
#
# Not iTop's own setup/unattended-install/install-itop.sh, and the reason is
# worth stating because that script is the obvious thing to reach for. It is a
# wrapper for an administrator standing inside an unzipped iTop with a response
# file already filled in, installing once: it defaults installation.xml, clears
# the maintenance lock, and calls unattended-install.php with --use_itop_config.
# Nothing in it accepts --install=0 or --clean=1, so the dry-run gate below and a
# repeatable re-install are both out of reach through it; and --use_itop_config, which it hardcodes, silently
# prefers an existing config-itop.php over the response file, which is wrong
# every time a workspace is reused.
#
# The install itself is theirs either way - this calls unattended-install.php
# with the documented options. What is here is everything around it that a
# script shipped inside an iTop cannot do: fetch the right release, put the
# module in extensions/, write the response file, and check afterwards that the
# module is actually installed.
#
# Usage: ITOP_ZIP_URL=... tools/ci/install-itop.sh
#        ITOP_ZIP_URL=... DRY_RUN_ONLY=1 tools/ci/install-itop.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

ITOP_ZIP_URL="${ITOP_ZIP_URL:?set ITOP_ZIP_URL - tools/ci/resolve-itop-versions.php --zip=3.2 prints one}"
ITOP_TAG="${ITOP_TAG:-}"
MODULE_SRC="${MODULE_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

# itop_sql(), shared with upgrade-module.sh so that both read iTop's install
# records through the same path. Sourced here rather than where it is used, so
# that a missing file fails in the first second of a run and not after an
# install.
. "$(dirname "${BASH_SOURCE[0]}")/itop-db.sh"
ITOP_DIR="${ITOP_DIR:-${RUNNER_TEMP:-/tmp}/itop}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-itop_ci}"
DB_USER="${DB_USER:-root}"
DB_PWD="${DB_PWD:-itop}"
DB_PREFIX="${DB_PREFIX:-}"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PWD

# Under DRY_RUN_ONLY the settings above are never connected to. They still have
# to be present and syntactically sane, because the response file is the same
# one a real install uses - which is the point of running the same script.
DRY_RUN_ONLY="${DRY_RUN_ONLY:-}"

ITOP_URL="${ITOP_URL:-http://127.0.0.1:8080/}"
ITOP_ADMIN_USER="${ITOP_ADMIN_USER:-admin}"
ITOP_ADMIN_PWD="${ITOP_ADMIN_PWD:-Admin*2026!}"

MODULE_CODE=altioo-mcp
# Extracted the way release.yml extracts it, from the file the setup itself
# reads. Used below to tell "installed" from "installed, but the copy from the
# run before this one".
MODULE_VERSION=$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' "$MODULE_SRC/extension.xml" | head -1)

echo "::group::Fetching iTop $ITOP_ZIP_URL"
rm -rf "$ITOP_DIR"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT INT TERM

# The archive holds INSTALL, LICENSE and web/; web/ is the document root.
curl -fsSL "$ITOP_ZIP_URL" -o "$STAGE/itop.zip"
unzip -q "$STAGE/itop.zip" -d "$STAGE/tree"
mv "$STAGE/tree/web" "$ITOP_DIR"
test -f "$ITOP_DIR/approot.inc.php" || { echo "not an iTop tree: $ITOP_DIR"; exit 1; }

# A release with no authent-token is one this module cannot be installed on, and
# the setup would say so only in a log line. Better to say it here, where the
# cause is one line away from the message.
test -d "$ITOP_DIR/datamodels/2.x/authent-token" \
  || echo "::warning::this release ships no authent-token module - the dry run below will say what that costs"

# The test harness, from the matching tag. Only tests/php-unit-tests/, and only
# over a release that does not have one: iTop's own code stays exactly as it was
# published.
if [ -n "$ITOP_TAG" ] && [ ! -d "$ITOP_DIR/tests/php-unit-tests" ]; then
  echo "adding the test harness from tag $ITOP_TAG"
  curl -fsSL "https://github.com/Combodo/iTop/archive/refs/tags/${ITOP_TAG}.tar.gz" \
    | tar -xz -C "$STAGE" "iTop-${ITOP_TAG}/tests" 2>/dev/null \
    || { echo "::error::tag $ITOP_TAG has no tests/ to take a harness from"; exit 1; }
  mkdir -p "$ITOP_DIR/tests"
  mv "$STAGE/iTop-${ITOP_TAG}/tests/php-unit-tests" "$ITOP_DIR/tests/"
  test -f "$ITOP_DIR/tests/php-unit-tests/src/BaseTestCase/ItopDataTestCase.php" \
    || { echo "::error::the harness from $ITOP_TAG has no ItopDataTestCase - integration tests would skip"; exit 1; }
fi
echo "::endgroup::"

echo "::group::Placing the module"
# Copied through the same exclusion list the release archive uses, so what the
# setup sees here is what an administrator unzips - vendor/ included, tools/
# and .github/ not. A module that only installs with its development files
# present is a module that does not install.
mkdir -p "$ITOP_DIR/extensions/$MODULE_CODE"
rsync -a --delete \
  --exclude='.git' \
  --exclude-from="$MODULE_SRC/exclude.txt" \
  "$MODULE_SRC/" "$ITOP_DIR/extensions/$MODULE_CODE/"
test -f "$ITOP_DIR/extensions/$MODULE_CODE/vendor/autoload.php" \
  || { echo "vendor/ is missing - run composer install --no-dev first"; exit 1; }
echo "::endgroup::"

echo "::group::Unattended install"
# No <selected_extensions>, which means the default choices from
# installation.xml. Two things follow from that, both wanted.
#
# The defaults are what iTop's own test suite asks to be installed - all the
# Configuration Management options, Service Management for Enterprises, simple
# ticket management and its portal, simple change management, known errors and
# the light FAQ - so its ItopDataTestCase fixtures find the classes they expect.
#
# And listing this module there would be the intuitive thing and the wrong one: InstallationFileService skips any extension code it
# finds in that list on the grounds that installation.xml already handled it,
# and installation.xml - a core file - has never heard of us. Left out, the
# module is discovered in extensions/ and added as an "unpackaged extension",
# which is exactly what happens when a human ticks it in the wizard. The
# assertions below are what stop that silently ceasing to be true.
#
# The module choices mirror what iTop's own test suite asks for, so its
# ItopDataTestCase fixtures have the classes they expect.
RESPONSE_FILE="$ITOP_DIR/ci-unattended-install.xml"
cat > "$RESPONSE_FILE" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<installation>
  <mode>install</mode>
  <preinstall></preinstall>
  <source_dir>datamodels/2.x/</source_dir>
  <extensions_dir>extensions</extensions_dir>
  <target_env>production</target_env>
  <workspace_dir></workspace_dir>
  <database>
    <server>${DB_HOST}:${DB_PORT}</server>
    <user>${DB_USER}</user>
    <pwd>${DB_PWD}</pwd>
    <name>${DB_NAME}</name>
    <db_tls_enabled></db_tls_enabled>
    <db_tls_ca></db_tls_ca>
    <prefix>${DB_PREFIX}</prefix>
  </database>
  <url>${ITOP_URL}</url>
  <graphviz_path>/usr/bin/dot</graphviz_path>
  <admin_account>
    <user>${ITOP_ADMIN_USER}</user>
    <pwd>${ITOP_ADMIN_PWD}</pwd>
    <language>EN US</language>
  </admin_account>
  <language>EN US</language>
  <sample_data>1</sample_data>
  <old_addon></old_addon>
  <options type="array"/>
  <mysql_bindir></mysql_bindir>
  <selected_extensions type="array">
  </selected_extensions>
</installation>
XML

rm -rf "$ITOP_DIR/data/.maintenance" "$ITOP_DIR/data/.readonly"

# The dry run. It reads the module declarations, resolves dependencies, decides
# which extensions are selectable, and checks the setup's own environment
# prerequisites - and it opens no database connection at all while doing it,
# which is why it is also a CI job of its own that runs before anything with a
# MariaDB attached to it. The question it answers, "would this extension be
# installed at all", is the one that actually fails.
#
# Note what it prints when it is done: "installed!", exactly like a real run.
# That string is the setup saying it reached the end of its script, not that
# anything was installed - which is why the verdict further down is taken from
# the database rather than from here.
php "$ITOP_DIR/setup/unattended-install/unattended-install.php" \
  --param-file="$RESPONSE_FILE" \
  --installation_xml="$ITOP_DIR/datamodels/2.x/installation.xml" \
  --install=0 \
  > "$ITOP_DIR/ci-dryrun.log" 2>&1 \
  || { echo "::error::the setup refused the run before installing anything"; cat "$ITOP_DIR/ci-dryrun.log"; exit 1; }

# The module list follows its heading and runs to the next blank line. Its shape
# is not stable across branches - 3.2 prints the whole list on one
# comma-separated line, 3.3 prints one module per line - so the block is read
# whole rather than the single line after the heading, and split on both commas
# and newlines. The field is then matched whole: a substring test would be
# satisfied by any module whose name merely starts the same way.
if ! awk '/^(Computed modules to install|Modules to install)/{f=1} f{print} f&&/^[[:space:]]*$/{f=0}' \
       "$ITOP_DIR/ci-dryrun.log" \
     | tr ',' '\n' | tr -d '[:blank:]' | grep -qx "$MODULE_CODE"; then
  echo "::error::$MODULE_CODE would not be installed - the setup discovered it and did not select it"
  # The reason, in the setup's own words. It logs a warning naming the modules
  # that made the extension unselectable and then carries on as if nothing had
  # happened.
  grep -iE "extension|$MODULE_CODE" "$ITOP_DIR/ci-dryrun.log" | tail -20
  exit 1
fi

if [ -n "$DRY_RUN_ONLY" ]; then
  echo "::endgroup::"
  echo "$MODULE_CODE $MODULE_VERSION would be installed on this iTop"
  exit 0
fi

set +e
php "$ITOP_DIR/setup/unattended-install/unattended-install.php" \
  --param-file="$RESPONSE_FILE" \
  --installation_xml="$ITOP_DIR/datamodels/2.x/installation.xml" \
  --clean=1 \
  2>&1 | tee "$ITOP_DIR/ci-install.log"
INSTALL_RC=${PIPESTATUS[0]}
set -e
echo "::endgroup::"

fail() { echo "::error::$1"; echo "--- last 60 lines of the setup log ---"; tail -60 "$ITOP_DIR/ci-install.log"; exit 1; }

# 1. The installer's own verdict: every step ran and it says so.
#
#    --check-consistency=1 was passed here and has been removed. It runs
#    MetaModel::CheckDefinitions() over the *whole* compiled datamodel, and iTop's
#    own shipped classes do not pass it: ActionNotification.language and
#    SynchroReplica.dest_class each declare a default of '' that is not among their
#    allowed values, and TemporaryObjectDescriptor's 'details' ZList names an
#    attribute code 'meta' the class does not have. A vanilla install carrying no
#    extension at all reports the same three, so the flag could never be green on
#    any version this module supports.
#
#    It also failed in the least useful way available. The consistency check runs
#    last, so iTop writes the config, compiles env-production and installs the
#    module, and only then prints "installation failed!" instead of "installed!"
#    and exits non-zero - a complete instance underneath a fatal-looking run, which
#    is how it went unnoticed locally: the directories were there, so it looked
#    like it had worked.
#
#    What the flag was for is not lost, only narrowed to checks that say something
#    about *this* module: priv_module_install and priv_extension_install below are
#    iTop's own record of what it installed, itop-smoke.php checks that the
#    compiled datamodel still holds what the controller reads, the integration
#    suite runs against the installed instance, and the endpoint is called over
#    HTTP. A checker that cannot tell this module's classes from Combodo's was
#    never what would catch a mistake here.
[ "$INSTALL_RC" -eq 0 ] || fail "the unattended install exited $INSTALL_RC"
grep -q '^installed!$' "$ITOP_DIR/ci-install.log" || fail "the setup did not report success"

# 2. iTop's own record of what it installed.
#
#    Everything above reads the setup's narration - English sentences, in a log,
#    printed by a file that moved from setup/moduleinstallation.class.inc.php to
#    setup/moduleinstallation/ between 3.2 and 3.3. priv_module_install and
#    priv_extension_install are what iTop consults afterwards to answer "what is
#    installed here"; they are written only for modules that were, and their
#    columns have not moved. Asking them is the difference between believing the
#    setup and checking it.
#

INSTALLED_VERSION=$(itop_sql "SELECT version FROM \`${DB_PREFIX}priv_module_install\`
	WHERE name = '$MODULE_CODE' AND installed IS NOT NULL ORDER BY installed DESC LIMIT 1") \
	|| fail "could not read ${DB_PREFIX}priv_module_install"

[ -n "$INSTALLED_VERSION" ] \
  || fail "$MODULE_CODE has no row in ${DB_PREFIX}priv_module_install - the setup completed without installing it"

# Presence is not enough. A copy left in extensions/ by an earlier run installs
# just as happily as the one under test and satisfies every other check here.
[ "$INSTALLED_VERSION" = "$MODULE_VERSION" ] \
  || fail "$MODULE_CODE is installed at $INSTALLED_VERSION, but this working copy declares $MODULE_VERSION"

# The extension row is written from extension.xml, for the extensions the setup
# chose, and records where each came from. 'extensions' is the route a user's
# install takes; anything else means this ran against a copy we did not place.
EXTENSION_SOURCE=$(itop_sql "SELECT source FROM \`${DB_PREFIX}priv_extension_install\`
	WHERE code = '$MODULE_CODE' ORDER BY installed DESC LIMIT 1") \
	|| fail "could not read ${DB_PREFIX}priv_extension_install"

[ "$EXTENSION_SOURCE" = "extensions" ] \
  || fail "$MODULE_CODE was installed from '${EXTENSION_SOURCE:-nowhere}', expected 'extensions'"

# 3. And the code is where iTop loads it from at runtime.
[ -d "$ITOP_DIR/env-production/$MODULE_CODE" ] \
  || fail "env-production/$MODULE_CODE does not exist - the module was recorded as installed but not compiled"

echo "$MODULE_CODE $INSTALLED_VERSION installed at $ITOP_DIR"
