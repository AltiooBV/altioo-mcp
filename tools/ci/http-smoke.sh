#!/usr/bin/env bash
#
# Calls the endpoint over HTTP, the way a client does.
#
# The unit suite proves the pipeline is right and the integration suite proves
# it is right against a real MetaModel. Neither one goes through a web server,
# and every incident this extension has had in the field started there: a
# request that never reached the controller, or an answer the client could not
# read. What is checked is that the wire is intact - both URLs refuse a call
# without a credential, one accepts a call with one, and answers a real MCP
# method.
#
# Usage: ITOP_DIR=... ITOP_TOKEN=... [PORTAL_SMOKE=...] tools/ci/http-smoke.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

: "${ITOP_DIR:?set ITOP_DIR to the installed iTop}"
: "${ITOP_TOKEN:?set ITOP_TOKEN to a personal token carrying an MCP scope}"

MODULE_SRC="${MODULE_SRC:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
MODULE_CODE=$(sed -n 's#.*<extension_code>\(.*\)</extension_code>.*#\1#p' "$MODULE_SRC/extension.xml" | head -1)

HOST="${SMOKE_HOST:-127.0.0.1}"
PORT="${SMOKE_PORT:-8080}"
BASE="http://${HOST}:${PORT}"
ITOP_ENV="${ITOP_ENV:-production}"
PROTOCOL_VERSION="${MCP_PROTOCOL_VERSION:-2025-06-18}"

# Two URLs serve the same file, and both are reachable: the module's .htaccess
# grants index.php in whichever tree it is copied into. Only the first is
# published to clients, so the token flow runs there.
#
# The second gets an unauthenticated call of its own because it is the one with
# a failure mode the first cannot have. index.php used to require
# __DIR__.'/vendor/autoload.php' before booting iTop; served from the compiled
# tree that is the same absolute file iTop's startup requires, and served from
# extensions/ it is not, so the package was loaded twice, PHP fatalled on the
# redeclared autoloader class, and every call to that URL was a 500. From the
# wire that bug is a status code, which is all this step reads.
ENDPOINT="${BASE}/env-${ITOP_ENV}/${MODULE_CODE}/index.php"
ALT_ENDPOINT="${BASE}/extensions/${MODULE_CODE}/index.php"

INITIALIZE='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"'"$PROTOCOL_VERSION"'","capabilities":{},"clientInfo":{"name":"ci","version":"0"}}}'

# PHP's built-in server, not Apache: this checks the application, and pulling
# in a web server would mean checking its configuration instead. The .htaccess
# rules that hide src/ and vendor/ are Apache's job and are verified in the
# release checklist against a real one - php -S ignores them, so no conclusion
# about them is drawn here. Note that this cuts both ways for the two URLs
# above: php -S serves them both because it ignores the deny, and on Apache
# they are both reachable because the module's own .htaccess grants them back.
#
# Started in a session of its own, and stopped by process group rather than by
# process: PHP_CLI_SERVER_WORKERS makes the server fork workers, and they do not
# die with their parent - killing it orphans four processes that go on holding
# the port. On Actions that is invisible, the machine being discarded with them.
# Anywhere the machine is reused it is worse than a leak: the next run's server
# cannot bind, curl reaches the *previous* server, and the checks below pass or
# fail against a tree and a database that are not the ones under test. setsid
# execs in place here rather than forking, so $! is the leader of the new group
# and -"$SERVER_PID" names every worker in it.
setsid env PHP_CLI_SERVER_WORKERS=4 php -S "${HOST}:${PORT}" -t "$ITOP_DIR" >"$ITOP_DIR/ci-httpd.log" 2>&1 &
SERVER_PID=$!
trap 'kill -TERM -"$SERVER_PID" 2>/dev/null || kill -TERM "$SERVER_PID" 2>/dev/null || true' EXIT INT TERM

for _ in $(seq 1 30); do
  if curl -fsS -o /dev/null "${BASE}/index.php"; then break; fi
  sleep 1
done

fail() { echo "::error::$1"; echo "--- server log ---"; tail -40 "$ITOP_DIR/ci-httpd.log"; exit 1; }

echo "1. the console answers"
curl -fsS -o /dev/null -w '   HTTP %{http_code}\n' "${BASE}/index.php" \
  || fail "iTop itself did not answer over HTTP"

echo "2. every endpoint refuses an unauthenticated call"
for URL in "$ENDPOINT" "$ALT_ENDPOINT"; do
  PATH_ONLY="${URL#"$BASE"}"
  STATUS=$(curl -s -o /dev/null -w '%{http_code}' \
    -X POST "$URL" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -d "$INITIALIZE")
  echo "   HTTP $STATUS   $PATH_ONLY"
  [ "$STATUS" = "401" ] \
    || fail "an unauthenticated call to $PATH_ONLY was answered $STATUS, expected 401"
done

# What the refusals before the login look like on the wire, and which of them leave an audit row (review
# 2026-11-18: RT-5, RT-8, HA-13). A malformed Authorization header is no credential: a 401, never the warning
# iTop's own Basic login raises on it. A bad token is audited under the method `exceptions`, as the README says.
# A CORS preflight sets no cookie. A refusal's body is the code and the words, not the audit row's own fields.
echo "2b. malformed credentials are refused 401, a refusal is audited, a preflight sets no cookie"
REFUSAL_BODY=$(mktemp)
for AUTH in 'Bearer' 'Basic !!!!' "Basic $(printf 'nocolon' | base64)"; do
  STATUS=$(curl -s -o "$REFUSAL_BODY" -w '%{http_code}' -X POST "$ENDPOINT" \
    -H 'Content-Type: application/json' -H "Authorization: ${AUTH}" -d "$INITIALIZE")
  echo "   HTTP $STATUS   Authorization: ${AUTH}"
  [ "$STATUS" = "401" ] || fail "a malformed Authorization header ('${AUTH}') was answered $STATUS, expected 401"
  grep -q 'Undefined array key' "$REFUSAL_BODY" "$ITOP_DIR/log/error.log" 2>/dev/null && fail "a malformed Authorization header reached iTop's Basic login"
done
BODY_REFUSED=$(curl -s -X POST "$ENDPOINT" -H 'Content-Type: application/json' -H 'Authorization: Bearer not-a-token' -d "$INITIALIZE")
case "$BODY_REFUSED" in *mcpMethod*|*requestParams*|*mcpName*) fail "a refusal names the audit row's own fields: $BODY_REFUSED" ;; esac
PREFLIGHT_HEADERS=$(mktemp)
curl -s -D "$PREFLIGHT_HEADERS" -o /dev/null -X OPTIONS "$ENDPOINT" -H 'Origin: https://app.example' -H 'Access-Control-Request-Method: POST'
grep -qi '^set-cookie:' "$PREFLIGHT_HEADERS" && fail "a CORS preflight was answered with a Set-Cookie"
if [ -n "${DB_NAME:-}" ] && [ -n "${DB_HOST:-}" ]; then
  # shellcheck source=itop-db.sh
  . "$MODULE_SRC/tools/ci/itop-db.sh"
  EXCEPTION_ROWS=$(itop_sql "SELECT COUNT(*) FROM \`${DB_PREFIX:-}priv_altioo_event_mcp_service\` WHERE mcp_method = 'exceptions' AND status = 'error'")
  echo "   rows under the method 'exceptions': ${EXCEPTION_ROWS:-0}"
  [ "${EXCEPTION_ROWS:-0}" -ge 4 ] || fail "the refusals before the login left fewer audit rows than the README says (every one but a preflight)"
else
  echo "   no DB_HOST/DB_NAME: the audit rows are not read back"
fi

echo "3. initialize, with a token"
HEADERS=$(mktemp); BODY=$(mktemp)
curl -s -D "$HEADERS" -o "$BODY" \
  -X POST "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d "$INITIALIZE"

grep -q '"protocolVersion"' "$BODY" || { echo "--- response ---"; cat "$BODY"; fail "initialize did not return a protocol version"; }
echo "   server: $(grep -o '"serverInfo":{[^}]*}' "$BODY" || echo 'no serverInfo')"

# The endpoint keeps no session, so initialize issues no id (SDK 0.10 serves a handshake-era client
# without sessions unless the server asks for them, and this one does not). Read anyway, so that a
# later call carries one if a change ever starts issuing it, and so that the change is noticed here.
SESSION=$(grep -i '^mcp-session-id:' "$HEADERS" | tr -d '\r' | cut -d' ' -f2- || true)
[ -z "$SESSION" ] || fail "initialize issued a session id ($SESSION): this endpoint keeps no session, so none is expected"

curl -s -o /dev/null \
  -X POST "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  ${SESSION:+-H "Mcp-Session-Id: ${SESSION}"} \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

echo "4. tools/list returns tools"
curl -s -o "$BODY" \
  -X POST "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
  ${SESSION:+-H "Mcp-Session-Id: ${SESSION}"} \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

# Decoded rather than grepped. The word appears inside the answer itself - the
# bulk tools declare a per-object status of {"ok","error"} in their output
# schema - so a substring test on the body reports a JSON-RPC error on a call
# that returned every tool correctly. Only a top-level `error` member is one.
# Literal PHP passed to `php -r`; $a and $argv are PHP variables, not shell ones.
# shellcheck disable=SC2016
RPC_ERROR=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo isset($a["error"]) ? json_encode($a["error"]) : "";' "$BODY")
[ -z "$RPC_ERROR" ] || { echo "--- response ---"; cat "$BODY"; fail "tools/list returned a JSON-RPC error: $RPC_ERROR"; }
# shellcheck disable=SC2016
COUNT=$(php -r '$a=json_decode(file_get_contents($argv[1]),true); echo count($a["result"]["tools"] ?? []);' "$BODY")
echo "   $COUNT tools advertised"
[ "$COUNT" -gt 0 ] || { echo "--- response ---"; cat "$BODY"; fail "tools/list advertised no tools"; }

# The audit row of a call, read back from the database: the client the call declared in its
# _meta (protocol 2026-07-28 sends it on every request and never sends initialize), the
# objects the read returned, and the account it ran as. Needs the database the checks above
# were installed into, so it is skipped, and says so, where the caller did not export one.
echo "4b. the audit row names the client, the objects read and the account"
if [ -n "${DB_NAME:-}" ] && [ -n "${DB_HOST:-}" ]; then
  # shellcheck source=itop-db.sh
  . "$MODULE_SRC/tools/ci/itop-db.sh"
  AUDIT_TABLE="${DB_PREFIX:-}priv_altioo_event_mcp_service"
  curl -s -o "$BODY" \
    -X POST "$ENDPOINT" \
    -H "Authorization: Bearer ${ITOP_TOKEN}" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
    ${SESSION:+-H "Mcp-Session-Id: ${SESSION}"} \
    -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"core_object_search_by_class","arguments":{"class":"Organization","limit":2},"_meta":{"io.modelcontextprotocol/clientInfo":{"name":"smoke-client","version":"9.9"}}}}'
  # shellcheck disable=SC2016
  RPC_ERROR=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo isset($a["error"]) || ($a["result"]["isError"] ?? false) ? json_encode($a) : "";' "$BODY")
  [ -z "$RPC_ERROR" ] || fail "the tools/call that the audit check makes was refused: $RPC_ERROR"
  AUDIT_WHERE="mcp_name = 'core_object_search_by_class' ORDER BY id DESC LIMIT 1"
  AUDIT_CLIENT=$(itop_sql "SELECT client FROM \`${AUDIT_TABLE}\` WHERE ${AUDIT_WHERE}")
  AUDIT_READ=$(itop_sql "SELECT read_objects FROM \`${AUDIT_TABLE}\` WHERE ${AUDIT_WHERE}")
  AUDIT_USER=$(itop_sql "SELECT user_id FROM \`${AUDIT_TABLE}\` WHERE ${AUDIT_WHERE}")
  echo "   client='${AUDIT_CLIENT}' read_objects='${AUDIT_READ}' user_id='${AUDIT_USER}'"
  [ "$AUDIT_CLIENT" = "smoke-client 9.9" ] || fail "the audit row does not name the client the call declared in _meta"
  case "$AUDIT_READ" in "Organization: "*) ;; *) fail "the audit row does not list the Organizations the call returned" ;; esac
  case "$AUDIT_USER" in ''|0) fail "the audit row holds no user_id" ;; esac
else
  echo "   no DB_HOST/DB_NAME: the audit row is not read back"
fi

# A real write through the endpoint, and what it leaves. The change's own line is iTop's default - the
# user's name, exactly as the console and REST write it - because Combodo's anonymizer finds a person's
# case-log entries by that exact name (doc/adr/0009); the tool is the audit row's mcp_name and the
# reason is its change_comment. The change is found from the audit row, which is also the query that
# replaces `userinfo LIKE '%(MCP:%'`.
echo "4c. a write leaves the plain name on the change and the reason on the audit row"
if [ -n "${DB_NAME:-}" ] && [ -n "${DB_HOST:-}" ]; then
  curl -s -o "$BODY" \
    -X POST "$ENDPOINT" \
    -H "Authorization: Bearer ${ITOP_TOKEN}" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
    ${SESSION:+-H "Mcp-Session-Id: ${SESSION}"} \
    -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"core_object_update","arguments":{"class":"Organization","id":1,"fields":{"code":"SMOKE4C"},"comment":"smoke reason","simulate":false}}}'
  # shellcheck disable=SC2016
  RPC_ERROR=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo isset($a["error"]) || ($a["result"]["isError"] ?? false) ? json_encode($a) : "";' "$BODY")
  [ -z "$RPC_ERROR" ] || fail "the write that the change check makes was refused: $RPC_ERROR"
  AUDIT_WHERE="e.mcp_name = 'core_object_update' AND e.change_id <> 0 ORDER BY e.id DESC LIMIT 1"
  CHANGE_USERINFO=$(itop_sql "SELECT c.userinfo FROM \`${DB_PREFIX:-}priv_change\` c JOIN \`${AUDIT_TABLE}\` e ON e.change_id = c.id WHERE ${AUDIT_WHERE}")
  CHANGE_ORIGIN=$(itop_sql "SELECT c.origin FROM \`${DB_PREFIX:-}priv_change\` c JOIN \`${AUDIT_TABLE}\` e ON e.change_id = c.id WHERE ${AUDIT_WHERE}")
  AUDIT_COMMENT=$(itop_sql "SELECT e.change_comment FROM \`${AUDIT_TABLE}\` e WHERE ${AUDIT_WHERE}")
  echo "   change userinfo='${CHANGE_USERINFO}' origin='${CHANGE_ORIGIN}' change_comment='${AUDIT_COMMENT}'"
  case "$CHANGE_USERINFO" in ''|*"MCP"*|*"smoke reason"*) fail "the change's line is not the user's plain name" ;; esac
  [ "$CHANGE_ORIGIN" = "custom-extension" ] || fail "the change's origin is not custom-extension"
  [ "$AUDIT_COMMENT" = "smoke reason" ] || fail "the reason did not reach the audit row"
else
  echo "   no DB_HOST/DB_NAME: the change is not read back"
fi

# The endpoint keeps no session, so ending one is not offered: DELETE is 405 with an Allow header, not
# a 200 that ended nothing (NoSessionTeardownMiddleware).
echo "4c2. DELETE is answered 405: there is no session to end"
DELETE_HEADERS=$(mktemp)
DELETE_STATUS=$(curl -s -D "$DELETE_HEADERS" -o /dev/null -w '%{http_code}' \
  -X DELETE "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H "Mcp-Session-Id: ${SESSION:-4d7e6c1a-2b3f-4c5d-8e9f-0a1b2c3d4e5f}")
echo "   HTTP ${DELETE_STATUS}"
[ "$DELETE_STATUS" = "405" ] || fail "DELETE was answered ${DELETE_STATUS}, not 405"
grep -qi '^allow:.*POST' "$DELETE_HEADERS" || fail "the 405 carries no Allow header naming POST"

# A JSON-RPC batch is refused whatever the protocol version: the audit row cannot describe N calls
# (BatchRefusal). HTTP 400 and -32600, and a row of its own.
echo "4c3. a batch is refused: 400, -32600, one row"
BATCH_STATUS=$(curl -s -o "$BODY" -w '%{http_code}' \
  -X POST "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
  -d '[{"jsonrpc":"2.0","id":1,"method":"tools/list"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]')
# shellcheck disable=SC2016
BATCH_CODE=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo $a["error"]["code"] ?? "";' "$BODY")
echo "   HTTP ${BATCH_STATUS}, error ${BATCH_CODE}"
[ "$BATCH_STATUS" = "400" ] || fail "a batch was answered ${BATCH_STATUS}, not 400"
[ "$BATCH_CODE" = "-32600" ] || fail "a batch did not answer -32600 Invalid Request"
if [ -n "${DB_NAME:-}" ] && [ -n "${DB_HOST:-}" ]; then
  BATCH_ROWS=$(itop_sql "SELECT COUNT(*) FROM \`${AUDIT_TABLE}\` e WHERE e.mcp_method = 'batch'")
  [ "${BATCH_ROWS:-0}" -ge 1 ] || fail "the refused batch left no audit row"
fi

# server/discover, which SDK 0.10 answers on the 2026-07-28 leg, is a request like any other: refused without a
# credential, and with one it says the revisions, the capabilities and the instructions, and lists no element.
echo "4c4. server/discover: 401 without a credential; with one, no tool listed"
DISCOVER='{"jsonrpc":"2.0","id":5,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"smoke","version":"0"},"io.modelcontextprotocol/clientCapabilities":{}}}}'
DISCOVER_STATUS=$(curl -s -o "$BODY" -w '%{http_code}' \
  -X POST "$ENDPOINT" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: server/discover' \
  -d "$DISCOVER")
echo "   without a credential: HTTP ${DISCOVER_STATUS}"
[ "$DISCOVER_STATUS" = "401" ] || fail "server/discover without a credential was answered ${DISCOVER_STATUS}, not 401"
DISCOVER_STATUS=$(curl -s -o "$BODY" -w '%{http_code}' \
  -X POST "$ENDPOINT" \
  -H "Authorization: Bearer ${ITOP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: server/discover' \
  -d "$DISCOVER")
# shellcheck disable=SC2016
DISCOVER_SHAPE=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); $r = $a["result"] ?? []; echo isset($r["supportedVersions"], $r["capabilities"]) && !isset($r["tools"]) ? "ok" : "wrong: ".json_encode($a);' "$BODY")
echo "   with a token: HTTP ${DISCOVER_STATUS}, ${DISCOVER_SHAPE}"
[ "$DISCOVER_STATUS" = "200" ] || fail "server/discover with a token was answered ${DISCOVER_STATUS}, not 200"
[ "$DISCOVER_SHAPE" = "ok" ] || fail "server/discover did not answer the revisions and capabilities without a tool list: ${DISCOVER_SHAPE}"

# The gates a call meets even with a valid token, and the refusals that are not a tool's (review 2026-11-23, T1:
# the host, content-type, Origin, URL-token, method and 2026-07-28 header gates were unit-tested and nothing proved
# them on the wire). The token is the one this script was given in ITOP_TOKEN and is sent only in the header; nothing
# here reads another credential. The profile gate and the token-login backstop need an account that lacks the MCP
# profile, or a changed setting, which this fixture does not have: the unit suite holds them.
echo "4e. the gates a valid token still meets, on the wire"
gate() { # name, expected HTTP status, then curl arguments; the body is left in $BODY
  local sName="$1" sExpect="$2" sGot
  shift 2
  sGot=$(curl -s -o "$BODY" -w '%{http_code}' "$@")
  echo "   HTTP ${sGot}   ${sName}"
  [ "$sGot" = "$sExpect" ] || { echo "--- response ---"; cat "$BODY"; fail "${sName}: answered ${sGot}, expected ${sExpect}"; }
}
rpc_code() { php -r '$a = json_decode(file_get_contents($argv[1]), true); echo $a["error"]["code"] ?? "";' "$BODY"; }
TOOLS_LIST='{"jsonrpc":"2.0","id":7,"method":"tools/list","params":{}}'
AUTH_H="Authorization: Bearer ${ITOP_TOKEN}"
JSON_H='Content-Type: application/json'
ACCEPT_H='Accept: application/json, text/event-stream'
gate "a Host this instance does not serve" 403 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'Host: evil.example' -d "$TOOLS_LIST"
gate "a body that is not JSON" 415 -X POST "$ENDPOINT" -H "$AUTH_H" -H 'Content-Type: text/plain' -H "$ACCEPT_H" -d "$TOOLS_LIST"
gate "a foreign Origin" 403 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'Origin: https://evil.example' -d "$TOOLS_LIST"
grep -q 'host or origin' "$BODY" || fail "a foreign Origin is said to be a host that is not served (review 2026-11-23, C11)"
gate "a token given as a URL parameter" 401 -X POST "${ENDPOINT}?auth_token=not-a-real-token" -H "$JSON_H" -H "$ACCEPT_H" -d "$TOOLS_LIST"
grep -q 'auth_token request parameter' "$BODY" || fail "a token in the URL is answered 'no credential was sent' and not said to be refused as a URL parameter (review 2026-11-23, C11)"
gate "a token in the header and another in the URL" 401 -X POST "${ENDPOINT}?auth_token=not-a-real-token" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -d "$TOOLS_LIST"
gate "a token nobody issued" 401 -X POST "$ENDPOINT" -H 'Authorization: Bearer abcdef0123456789abcdef0123456789' -H "$JSON_H" -H "$ACCEPT_H" -d "$TOOLS_LIST"
gate "GET" 405 -X GET "$ENDPOINT" -H "$AUTH_H"
gate "PUT" 405 -X PUT "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -d "$TOOLS_LIST"
META='"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"smoke","version":"0"},"io.modelcontextprotocol/clientCapabilities":{}}'
gate "2026-07-28 without Mcp-Method" 400 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'MCP-Protocol-Version: 2026-07-28' \
  -d '{"jsonrpc":"2.0","id":8,"method":"resources/list","params":{'"$META"'}}'
[ "$(rpc_code)" = "-32020" ] || fail "a 2026-07-28 call without Mcp-Method was not answered -32020"
gate "2026-07-28 tools/call without Mcp-Name" 400 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/call' \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"core_current_user","arguments":{},'"$META"'}}'
[ "$(rpc_code)" = "-32020" ] || fail "a 2026-07-28 tools/call without Mcp-Name was not answered -32020"
gate "2026-07-28 with Mcp-Name naming another tool" 400 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'MCP-Protocol-Version: 2026-07-28' -H 'Mcp-Method: tools/call' -H 'Mcp-Name: core_class_list' \
  -d '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"core_current_user","arguments":{},'"$META"'}}'
gate "an unsupported protocol version header" 400 -X POST "$ENDPOINT" -H "$AUTH_H" -H "$JSON_H" -H "$ACCEPT_H" -H 'MCP-Protocol-Version: 1999-01-01' -d "$TOOLS_LIST"
[ "$(rpc_code)" = "-32022" ] || fail "an unsupported protocol version was not answered -32022"
grep -q '2026-07-28' "$BODY" || fail "the -32022 refusal does not list 2026-07-28 among the versions it supports (review 2026-11-23, B5)"
for sRefusalNeedle in "$ITOP_TOKEN"; do
  grep -qF "$sRefusalNeedle" "$BODY" && fail "a refusal echoed the token"
done

# A call a tool refuses is answered with isError - HTTP 200 and a JSON-RPC success, as the protocol
# says - and recorded as an error. A class that does not exist is refused by core_object_get.
echo "4d. a refusal by a tool is HTTP 200 and an error on the audit row"
if [ -n "${DB_NAME:-}" ] && [ -n "${DB_HOST:-}" ]; then
  REFUSED_STATUS=$(curl -s -o "$BODY" -w '%{http_code}' \
    -X POST "$ENDPOINT" \
    -H "Authorization: Bearer ${ITOP_TOKEN}" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json, text/event-stream' \
    -H "MCP-Protocol-Version: ${PROTOCOL_VERSION}" \
    ${SESSION:+-H "Mcp-Session-Id: ${SESSION}"} \
    -d '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"core_object_get","arguments":{"class":"NoSuchClassAnywhere","id":1}}}')
  # shellcheck disable=SC2016
  IS_ERROR=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo ($a["result"]["isError"] ?? false) ? "yes" : "no";' "$BODY")
  AUDIT_ROW=$(itop_sql "SELECT e.status FROM \`${AUDIT_TABLE}\` e WHERE e.mcp_name = 'core_object_get' ORDER BY e.id DESC LIMIT 1")
  echo "   HTTP ${REFUSED_STATUS}, isError=${IS_ERROR}, audit status='${AUDIT_ROW}'"
  [ "$REFUSED_STATUS" = "200" ] || fail "the tool's refusal was not answered HTTP 200"
  [ "$IS_ERROR" = "yes" ] || fail "the call that the refusal check makes was not refused by the tool"
  [ "$AUDIT_ROW" = "error" ] || fail "the audit row for a refused call does not say error"
else
  echo "   no DB_HOST/DB_NAME: the refusal is not read back"
fi

# Portal mode, when the post-setup checks made a portal-only user to try it with. Run last: the
# portal fixture is the checks' own, and a failure there says nothing about the rest above.
PORTAL_CHECKS="$MODULE_SRC/tools/ci/checks/portal-smoke.sh"
if [ -n "${PORTAL_SMOKE:-}" ] && [ -f "$PORTAL_CHECKS" ]; then
  # shellcheck source=checks/portal-smoke.sh
  . "$PORTAL_CHECKS"
else
  echo "5. no PORTAL_SMOKE: portal mode is not exercised over HTTP"
fi

echo "both endpoints are reachable, refuse anonymous callers, and the published one serves tools"
