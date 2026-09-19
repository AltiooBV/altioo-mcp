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
# Usage: ITOP_DIR=... ITOP_TOKEN=... tools/ci/http-smoke.sh
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

: "${ITOP_DIR:?set ITOP_DIR to the installed iTop}"
: "${ITOP_TOKEN:?set ITOP_TOKEN to a personal token carrying an MCP scope}"

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
ENDPOINT="${BASE}/env-${ITOP_ENV}/altioo-mcp/index.php"
ALT_ENDPOINT="${BASE}/extensions/altioo-mcp/index.php"

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

# Forwarded on every later call, as a client does. Absent when the server is
# stateless, in which case this is an empty string and changes nothing.
SESSION=$(grep -i '^mcp-session-id:' "$HEADERS" | tr -d '\r' | cut -d' ' -f2- || true)

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
RPC_ERROR=$(php -r '$a = json_decode(file_get_contents($argv[1]), true); echo isset($a["error"]) ? json_encode($a["error"]) : "";' "$BODY")
[ -z "$RPC_ERROR" ] || { echo "--- response ---"; cat "$BODY"; fail "tools/list returned a JSON-RPC error: $RPC_ERROR"; }
COUNT=$(php -r '$a=json_decode(file_get_contents($argv[1]),true); echo count($a["result"]["tools"] ?? []);' "$BODY")
echo "   $COUNT tools advertised"
[ "$COUNT" -gt 0 ] || { echo "--- response ---"; cat "$BODY"; fail "tools/list advertised no tools"; }

echo "both endpoints are reachable, refuse anonymous callers, and the published one serves tools"
