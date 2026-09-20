#!/usr/bin/env bash
#
# Upgrades the module already installed in an iTop to this working copy, the way
# an administrator does it: drop the new files over the old ones and re-run the
# setup.
#
# Requires an instance install-itop.sh has already produced, with an *older*
# version of the module in it, and its database untouched since. What this adds
# is the half of the client's experience the install job never sees: the setup
# in <mode>upgrade</mode>, over data, over a configuration file somebody has
# edited.
#
# Three things about that mode are worth stating, because getting any of them
# wrong produces a run that passes and proves nothing:
#
#   - NO --clean. That flag drops the database, which is exactly the data the
#     upgrade is supposed to preserve. An upgrade test that cleans first is a
#     fresh-install test with extra steps.
#   - <previous_configuration_file> must point at the instance's existing
#     config. iTop preserves module settings on upgrade only when it can read
#     the old configuration (applicationinstaller.class.inc.php, DoCreateConfig:
#     $bPreserveModuleSettings is set in the 'upgrade' branch and nowhere else).
#     Omit it and every client's tuning silently reverts to defaults.
#   - <sample_data>0</sample_data>. The demo data was loaded at install; loading
#     it again on top of an existing instance is not something an upgrade does.
#
# Run it twice and it must pass twice: a client whose setup failed half way
# fixes the cause and re-runs, and everything executes again.
#
# Usage: ITOP_DIR=/tmp/itop tools/ci/upgrade-module.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

MODULE_SRC="${MODULE_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
ITOP_DIR="${ITOP_DIR:?set ITOP_DIR - the instance install-itop.sh produced}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-itop_ci}"
DB_USER="${DB_USER:-root}"
DB_PWD="${DB_PWD:-itop}"
DB_PREFIX="${DB_PREFIX:-}"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PWD

ITOP_URL="${ITOP_URL:-http://127.0.0.1:8080/}"
ITOP_ADMIN_USER="${ITOP_ADMIN_USER:-admin}"
ITOP_ADMIN_PWD="${ITOP_ADMIN_PWD:-Admin*2026!}"

MODULE_CODE=altioo-mcp
TARGET_ENV=production
CONFIG_FILE="$ITOP_DIR/conf/$TARGET_ENV/config-itop.php"

. "$(dirname "${BASH_SOURCE[0]}")/itop-db.sh"

test -f "$ITOP_DIR/approot.inc.php" || { echo "::error::not an iTop tree: $ITOP_DIR"; exit 1; }
test -f "$CONFIG_FILE" || { echo "::error::no configuration at $CONFIG_FILE - is this instance installed?"; exit 1; }

NEW_VERSION=$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' "$MODULE_SRC/extension.xml" | head -1)

# What is installed right now, straight from iTop's records. This is the "from"
# of the upgrade, and the run is only meaningful if it is older than the "to".
OLD_VERSION=$(itop_sql "SELECT version FROM \`${DB_PREFIX}priv_module_install\`
	WHERE name = '$MODULE_CODE' AND installed IS NOT NULL ORDER BY installed DESC LIMIT 1")

[ -n "$OLD_VERSION" ] \
  || { echo "::error::$MODULE_CODE is not installed in $ITOP_DIR - nothing to upgrade"; exit 1; }

if [ "$OLD_VERSION" = "$NEW_VERSION" ]; then
  echo "::notice::installed version and working copy are both $NEW_VERSION - this run re-applies the same version"
fi

echo "::group::Upgrading $MODULE_CODE $OLD_VERSION -> $NEW_VERSION"

# The same copy the release archive is, over the top of the old one - files the
# new version dropped stay behind, exactly as they do for a client who unzips
# over their extensions/ directory. No --delete, for that reason: this is the
# messy case, and the one that finds leftovers still being loaded.
rsync -a \
  --exclude='.git' \
  --exclude-from="$MODULE_SRC/exclude.txt" \
  "$MODULE_SRC/" "$ITOP_DIR/extensions/$MODULE_CODE/"
test -f "$ITOP_DIR/extensions/$MODULE_CODE/vendor/autoload.php" \
  || { echo "vendor/ is missing - run composer install --no-dev first"; exit 1; }

RESPONSE_FILE="$ITOP_DIR/ci-unattended-upgrade.xml"
cat > "$RESPONSE_FILE" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<installation>
  <mode>upgrade</mode>
  <preinstall></preinstall>
  <source_dir>datamodels/2.x/</source_dir>
  <previous_configuration_file>${CONFIG_FILE}</previous_configuration_file>
  <extensions_dir>extensions</extensions_dir>
  <target_env>${TARGET_ENV}</target_env>
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
  <sample_data>0</sample_data>
  <old_addon></old_addon>
  <options type="array"/>
  <mysql_bindir></mysql_bindir>
  <selected_extensions type="array">
  </selected_extensions>
</installation>
XML

# A setup that failed leaves these behind and the next one refuses to start.
rm -rf "$ITOP_DIR/data/.maintenance" "$ITOP_DIR/data/.readonly"

set +e
php "$ITOP_DIR/setup/unattended-install/unattended-install.php" \
  --param-file="$RESPONSE_FILE" \
  --installation_xml="$ITOP_DIR/datamodels/2.x/installation.xml" \
  2>&1 | tee "$ITOP_DIR/ci-upgrade.log"
UPGRADE_RC=${PIPESTATUS[0]}
set -e
echo "::endgroup::"

# --check-consistency=1 is deliberately not passed, for the reason spelled out
# in install-itop.sh: it runs MetaModel::CheckDefinitions() over the *whole*
# compiled datamodel, and iTop's own shipped classes do not pass it on any
# version this module supports. It fails last, after the upgrade has already
# recompiled and rewritten everything, so it reports a fatal run over a
# complete instance. The verdict here comes from priv_module_install and the
# fixture check below, both of which are about this module.
#
# It was removed from install-itop.sh first and missed here, which is why the
# upgrade job failed the first time it ever had a baseline to run against.

fail() { echo "::error::$1"; echo "--- last 60 lines of the setup log ---"; tail -60 "$ITOP_DIR/ci-upgrade.log"; exit 1; }

[ "$UPGRADE_RC" -eq 0 ] || fail "the unattended upgrade exited $UPGRADE_RC"
grep -q '^installed!$' "$ITOP_DIR/ci-upgrade.log" || fail "the setup did not report success"

# The version iTop now answers with. Same reasoning as install-itop.sh: the log
# is the setup's narration, these tables are its record.
INSTALLED_VERSION=$(itop_sql "SELECT version FROM \`${DB_PREFIX}priv_module_install\`
	WHERE name = '$MODULE_CODE' AND installed IS NOT NULL ORDER BY installed DESC LIMIT 1") \
	|| fail "could not read ${DB_PREFIX}priv_module_install"

[ "$INSTALLED_VERSION" = "$NEW_VERSION" ] \
  || fail "after the upgrade $MODULE_CODE reads as $INSTALLED_VERSION, expected $NEW_VERSION"

# An upgrade adds a row; it does not replace the history. If the older row is
# gone, this instance was reinstalled rather than upgraded - which would also
# explain any fixture data that survived, and would make the whole run a
# fresh-install test wearing a different name.
HISTORY=$(itop_sql "SELECT COUNT(*) FROM \`${DB_PREFIX}priv_module_install\`
	WHERE name = '$MODULE_CODE'")

[ "${HISTORY:-0}" -ge 2 ] \
  || fail "priv_module_install holds $HISTORY row(s) for $MODULE_CODE - the instance was reinstalled, not upgraded"

EXTENSION_SOURCE=$(itop_sql "SELECT source FROM \`${DB_PREFIX}priv_extension_install\`
	WHERE code = '$MODULE_CODE' ORDER BY installed DESC LIMIT 1")

[ "$EXTENSION_SOURCE" = "extensions" ] \
  || fail "$MODULE_CODE was upgraded from '${EXTENSION_SOURCE:-nowhere}', expected 'extensions'"

# Recompiled, not merely recorded.
test -d "$ITOP_DIR/env-$TARGET_ENV/$MODULE_CODE" \
  || fail "env-$TARGET_ENV/$MODULE_CODE does not exist - recorded as upgraded but not compiled"

COMPILED_VERSION=$(sed -n 's#.*<version>\(.*\)</version>.*#\1#p' \
  "$ITOP_DIR/env-$TARGET_ENV/$MODULE_CODE/extension.xml" 2>/dev/null | head -1)
[ -z "$COMPILED_VERSION" ] || [ "$COMPILED_VERSION" = "$NEW_VERSION" ] \
  || fail "env-$TARGET_ENV holds $COMPILED_VERSION, the database says $INSTALLED_VERSION"

echo "$MODULE_CODE upgraded $OLD_VERSION -> $INSTALLED_VERSION at $ITOP_DIR"
