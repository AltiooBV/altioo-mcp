#!/usr/bin/env bash
#
# Calls every element of the example pack over HTTP, on an iTop where
# install-itop.sh installed it beside the module (PACK_SRC=doc/example-pack).
#
# The pack's own CI job (ci.yml, example-pack) is its contract suite, which needs
# no iTop. Everything that breaks between a pack's files and a running instance
# is invisible there: the setup skipping its datamodel file, its classmap being
# empty, its scope not existing. The first of those shipped: a comment before
# <itop_design> made the setup skip the file, so the toolset scope was never
# declared and a token asking for it was refused at login, while the contract
# suite stayed green. Step 2 below is that regression.
#
# What it asks, with a token per question (minted by pack-smoke.php):
#   1. a token scoped MCP sees the pack's tools and prompt, and each one answers
#   2. a token scoped to the pack's toolset logs in, and sees the pack only
#   3. a token scoped MCP-advisory is not served the pack's write tool (ADR 0022)
# acme_precanned_reply_render is called where Combodo's precanned-replies is
# installed; elsewhere it must be absent, and the run says which it was.
#
# Usage: ITOP_DIR=... [ITOP_ADMIN_USER=admin] tools/ci/pack-smoke.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

: "${ITOP_DIR:?set ITOP_DIR to the installed iTop}"
ITOP_ADMIN_USER="${ITOP_ADMIN_USER:-admin}"
MODULE_SRC="${MODULE_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
MODULE_CODE=$(sed -n 's#.*<extension_code>\(.*\)</extension_code>.*#\1#p' "$MODULE_SRC/extension.xml" | head -1)

HOST="${SMOKE_HOST:-127.0.0.1}"
PORT="${SMOKE_PORT:-8080}"
BASE="http://${HOST}:${PORT}"
ENDPOINT="${BASE}/env-${ITOP_ENV:-production}/${MODULE_CODE}/index.php"
PROTOCOL_VERSION="${MCP_PROTOCOL_VERSION:-2025-06-18}"

fail() { echo "::error::$1"; [ -f "${BODY:-}" ] && { echo "--- last response ---"; cat "$BODY"; echo; }; echo "--- server log ---"; tail -40 "$ITOP_DIR/ci-pack-httpd.log" 2>/dev/null || true; exit 1; }

echo "0. the fixture"
OUT=$(php "$MODULE_SRC/tools/ci/pack-smoke.php" "$ITOP_DIR" "$ITOP_ADMIN_USER") || { printf '%s\n' "$OUT"; fail "the fixture's checks failed"; }
FIXTURE=$(printf '%s\n' "$OUT" | sed -n 's/^pack-smoke: //p')
printf '%s\n' "$OUT" | grep -v '^pack-smoke: '
[ -n "$FIXTURE" ] || fail "pack-smoke.php printed no fixture"
field() { printf '%s' "$FIXTURE" | php -r '$a = json_decode(stream_get_contents(STDIN), true); $v = $a[$argv[1]] ?? ""; echo is_bool($v) ? ($v ? "1" : "") : $v;' "$1"; }
TICKET=$(field ticket_id); PRECANNED=$(field precanned); REPLY=$(field reply_id)
T_FULL=$(field token_full); T_TOOLSET=$(field token_toolset); T_TOOLSET_READ=$(field token_toolset_read); T_ADVISORY=$(field token_advisory)
if [ -n "${GITHUB_ACTIONS:-}" ]; then
  for T in "$T_FULL" "$T_TOOLSET" "$T_TOOLSET_READ" "$T_ADVISORY"; do echo "::add-mask::$T"; done
fi

# Same server as http-smoke.sh, for the same reasons: a session of its own, stopped by process group.
setsid env PHP_CLI_SERVER_WORKERS=4 php -S "${HOST}:${PORT}" -t "$ITOP_DIR" >"$ITOP_DIR/ci-pack-httpd.log" 2>&1 &
SERVER_PID=$!
BODY=$(mktemp)
trap 'kill -TERM -"$SERVER_PID" 2>/dev/null || kill -TERM "$SERVER_PID" 2>/dev/null || true; rm -f "$BODY"' EXIT INT TERM
for _ in $(seq 1 30); do
  if curl -fsS -o /dev/null "${BASE}/index.php" 2>/dev/null; then break; fi
  sleep 1
done

# rpc TOKEN METHOD PARAMS -> the HTTP status; the body is in $BODY.
rpc() {
  curl -s -o "$BODY" -w '%{http_code}' -X POST "$ENDPOINT" \
    -H "Authorization: Bearer $1" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
    -d '{"jsonrpc":"2.0","id":1,"method":"'"$2"'","params":'"$3"'}'
}
# The names a list answered, one per line. Literal PHP; $a and $argv are PHP's.
# shellcheck disable=SC2016
names() { php -r '$a = json_decode(file_get_contents($argv[1]), true); foreach ($a["result"][$argv[2]] ?? [] as $e) echo $e["name"], "\n";' "$BODY" "$1"; }
# Empty when the call succeeded; the error, or the tool's refusal text, when it did not.
# shellcheck disable=SC2016
call_error() { php -r '$a = json_decode(file_get_contents($argv[1]), true); if (!is_array($a)) { echo "not JSON"; return; } if (isset($a["error"])) { echo json_encode($a["error"]); return; } if ($a["result"]["isError"] ?? false) echo json_encode($a["result"]["content"]);' "$BODY"; }
call() { # TOKEN TOOL ARGS
  local sStatus
  sStatus=$(rpc "$1" tools/call '{"name":"'"$2"'","arguments":'"$3"'}')
  [ "$sStatus" = 200 ] || fail "tools/call $2 was answered HTTP $sStatus"
}
has() { printf '%s\n' "$1" | grep -qx "$2"; }

echo "1. a token scoped MCP: the pack's elements are listed, and each one answers"
STATUS=$(rpc "$T_FULL" initialize '{"protocolVersion":"'"$PROTOCOL_VERSION"'","capabilities":{},"clientInfo":{"name":"ci-pack","version":"0"}}')
[ "$STATUS" = 200 ] || fail "initialize with an MCP token was answered HTTP $STATUS"
rpc "$T_FULL" tools/list '{}' >/dev/null
TOOLS=$(names tools)
for TOOL in acme_ticket_search_mine acme_ticket_add_log_entry; do
  has "$TOOLS" "$TOOL" || fail "tools/list does not list $TOOL - the pack's provider was not discovered"
done
echo "   $(printf '%s\n' "$TOOLS" | grep -c '^acme_') pack tools among $(printf '%s\n' "$TOOLS" | grep -c .)"
rpc "$T_FULL" prompts/list '{}' >/dev/null
has "$(names prompts)" acme_triage_my_queue || fail "prompts/list does not list acme_triage_my_queue"

rpc "$T_FULL" prompts/get '{"name":"acme_triage_my_queue","arguments":{}}' >/dev/null
[ -z "$(call_error)" ] || fail "prompts/get acme_triage_my_queue failed: $(call_error)"
grep -q 'acme_ticket_search_mine' "$BODY" || fail "acme_triage_my_queue did not return its message"
echo "   acme_triage_my_queue: prompts/get answered"

call "$T_FULL" acme_ticket_search_mine '{"output_fields":"title"}'
[ -z "$(call_error)" ] || fail "acme_ticket_search_mine failed: $(call_error)"
grep -q "Pack smoke ticket" "$BODY" || fail "acme_ticket_search_mine did not list the ticket the account raised (#$TICKET)"
echo "   acme_ticket_search_mine: lists ticket #$TICKET"

call "$T_FULL" acme_ticket_add_log_entry '{"id":'"$TICKET"',"message":"pack smoke rehearsal"}'
[ -z "$(call_error)" ] || fail "acme_ticket_add_log_entry (dry run) failed: $(call_error)"
call "$T_FULL" acme_ticket_add_log_entry '{"id":'"$TICKET"',"message":"pack smoke entry","simulate":false}'
[ -z "$(call_error)" ] || fail "acme_ticket_add_log_entry failed: $(call_error)"
call "$T_FULL" core_object_get '{"class":"UserRequest","id":'"$TICKET"',"output_fields":"public_log"}'
grep -q 'pack smoke entry' "$BODY" || fail "the entry acme_ticket_add_log_entry wrote is not in the ticket's public log"
grep -q 'pack smoke rehearsal' "$BODY" && fail "the dry run of acme_ticket_add_log_entry wrote to the ticket"
echo "   acme_ticket_add_log_entry: the dry run wrote nothing, the real call wrote the entry"

if [ -n "$PRECANNED" ]; then
  has "$TOOLS" acme_precanned_reply_render || fail "precanned-replies is installed and acme_precanned_reply_render is not listed"
  call "$T_FULL" acme_precanned_reply_render '{"ticket_class":"UserRequest","ticket_id":'"$TICKET"',"reply_id":'"$REPLY"'}'
  [ -z "$(call_error)" ] || fail "acme_precanned_reply_render failed: $(call_error)"
  grep -q 'Hello CI, about Pack smoke ticket' "$BODY" || fail "acme_precanned_reply_render did not fill in the reply"
  echo "   acme_precanned_reply_render: filled in reply #$REPLY"
else
  # Its contract where the class does not exist: absent, not refused.
  has "$TOOLS" acme_precanned_reply_render && fail "acme_precanned_reply_render is listed on an iTop without PrecannedReply"
  echo "   acme_precanned_reply_render: absent, as it must be - Combodo's precanned-replies is not installed here, so it is not called"
fi

echo "2. a token scoped to the pack's toolset logs in and sees the pack only"
STATUS=$(rpc "$T_TOOLSET" tools/list '{}')
[ "$STATUS" = 200 ] || fail "a token scoped MCP-toolset-acme-servicedesk was answered HTTP $STATUS - is the pack's scope declared?"
TOOLS=$(names tools)
has "$TOOLS" acme_ticket_search_mine || fail "the toolset token does not see acme_ticket_search_mine"
OTHERS=$(printf '%s\n' "$TOOLS" | grep -v '^acme_' | grep . || true)
[ -z "$OTHERS" ] || fail "the toolset token sees tools outside the pack: $(printf '%s ' "$OTHERS")"
echo "   $(printf '%s\n' "$TOOLS" | grep -c .) tools, all the pack's"
call "$T_TOOLSET" acme_ticket_search_mine '{}'
[ -z "$(call_error)" ] || fail "acme_ticket_search_mine with the toolset token failed: $(call_error)"
rpc "$T_TOOLSET_READ" tools/list '{}' >/dev/null
TOOLS=$(names tools)
has "$TOOLS" acme_ticket_search_mine || fail "the toolset+MCP-read token does not see acme_ticket_search_mine"
has "$TOOLS" acme_ticket_add_log_entry && fail "the toolset+MCP-read token sees the pack's write tool"
echo "   with MCP-read: the read tools only"

echo "3. a token scoped MCP-advisory is not served the pack's write tool"
rpc "$T_ADVISORY" tools/list '{}' >/dev/null
TOOLS=$(names tools)
has "$TOOLS" acme_ticket_search_mine || fail "the advisory token does not see acme_ticket_search_mine"
has "$TOOLS" acme_ticket_add_log_entry && fail "the advisory token is served acme_ticket_add_log_entry (ADR 0022)"
echo "   acme_ticket_add_log_entry absent, acme_ticket_search_mine served"

echo "the example pack answers over HTTP"
