# Portal mode over the wire, sourced by tools/ci/http-smoke.sh with BASE, ENDPOINT, MODULE_CODE,
# PROTOCOL_VERSION and fail() in scope, and PORTAL_SMOKE set by the caller to
# "<token>:<own request id>:<another caller's request id>" - what checks/module-smoke.php made:
# a portal-only user, a personal token for them, and a request each for them and a colleague.
#
# The unit and integration suites call the tools in-process, with the portal remembered by hand.
# What they cannot say is that a request on the wire gets there: that the token logs a portal-only
# user in at all, that the endpoint puts them in portal mode, that what tools/list advertises is
# what portal mode serves, and that portal_id is read from the URL a client really calls. The
# 2026-11-03 red pass read all of it against the code and tried none of it live (review/).
#
# Nothing here writes. A call that must be refused is sent with simulate=false, so that a
# refusal is the portal's and not the dry run's; one that is allowed is left as a dry run.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

IFS=: read -r sPortalToken iOwn iOther <<<"$PORTAL_SMOKE"
sPortalUrl="$ENDPOINT?portal_id=itop-portal"
sPortalSession=''
iRpc=10

# <url> <json-rpc body> -> the headers and then the body, in $sHeaders and $sBody
mcp_post() {
	local sOut
	sOut=$(curl -sS -D - -X POST "$1" -H "Authorization: Bearer $sPortalToken" -H 'Content-Type: application/json' \
		-H 'Accept: application/json, text/event-stream' -H "MCP-Protocol-Version: $PROTOCOL_VERSION" \
		${sPortalSession:+-H "Mcp-Session-Id: $sPortalSession"} -d "$2" -w '\n%{http_code}')
	sStatus=${sOut##*$'\n'}
	sHeaders=${sOut%%$'\r\n\r\n'*}
	sBody=${sOut#*$'\r\n\r\n'}
	sBody=${sBody%$'\n'*}
}
# <url> -> a session on that URL, or none when the endpoint is stateless
mcp_open() {
	sPortalSession=''
	mcp_post "$1" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"'"$PROTOCOL_VERSION"'","capabilities":{},"clientInfo":{"name":"ci","version":"0"}}}'
	sPortalSession=$(printf '%s' "$sHeaders" | grep -i '^mcp-session-id:' | tr -d '\r' | cut -d' ' -f2- || true)
	[ "$sStatus" = 200 ] || return 1
	mcp_post "$1" '{"jsonrpc":"2.0","method":"notifications/initialized"}'
}
# <name> <arguments json> -> "ok" or "refused", then the text the tool answered
mcp_tool() {
	iRpc=$((iRpc + 1))
	mcp_post "$sPortalUrl" '{"jsonrpc":"2.0","id":'"$iRpc"',"method":"tools/call","params":{"name":"'"$1"'","arguments":'"$2"'}}'
	# shellcheck disable=SC2016
	printf '%s' "$sBody" | php -r '$a = json_decode(stream_get_contents(STDIN), true);
		if (!is_array($a)) { echo "unreadable"; exit; }
		if (isset($a["error"])) { echo "refused ".json_encode($a["error"]); exit; }
		$t = implode(" ", array_column($a["result"]["content"] ?? [], "text"));
		echo (($a["result"]["isError"] ?? false) ? "refused " : "ok ").$t;'
}
must() { # <what> <got> <prefix>
	case "$2" in "$3"*) echo "   ok  $1" ;; *) fail "$1: expected $3..., got: ${2:0:300}" ;; esac
}

echo "5. portal mode: a portal-only user with a scoped token"
mcp_open "$sPortalUrl" || fail "a portal-only user with a valid token was not let in (HTTP $sStatus): ${sBody:0:300}"
echo "   ok  let in, on the portal named in the URL"

mcp_post "$sPortalUrl" '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
sTools=$(printf '%s' "$sBody" | php -r '$a = json_decode(stream_get_contents(STDIN), true); echo implode(" ", array_column($a["result"]["tools"] ?? [], "name"));')
case " $sTools " in *' core_portal_list '*) echo "   ok  the portal's lists are a tool" ;; *) fail "tools/list in portal mode lacks core_portal_list: $sTools" ;; esac
# MCP-delete is on the token, so each of these is absent because of the mode (ADR 0006).
for sTool in core_object_delete core_object_search_by_oql; do
	case " $sTools " in *" $sTool "*) fail "tools/list in portal mode advertises $sTool, which the portal does not grant" ;; esac
done
echo "   ok  core_object_delete and core_object_search_by_oql are not advertised, MCP-delete or not"

sOut=$(mcp_tool core_object_delete '{"class":"UserRequest","id":'"$iOwn"'}')
must "and calling core_object_delete anyway is refused" "$sOut" refused

sOut=$(mcp_tool core_current_user '{}')
case "$sOut" in *'"current_portal":"itop-portal"'*|*'"current_portal": "itop-portal"'*) echo "   ok  core_current_user says which portal is served" ;; *) fail "core_current_user does not name the portal: ${sOut:0:300}" ;; esac

echo "6. portal mode: no more than the portal's scopes"
sOut=$(mcp_tool core_object_get '{"class":"UserRequest","id":'"$iOwn"'}')
must "the user's own request is readable" "$sOut" ok
sOut=$(mcp_tool core_object_get '{"class":"UserRequest","id":'"$iOther"'}')
must "a colleague's request in the same organisation is not" "$sOut" refused
case "$sOut" in *"portal-smoke other"*) fail "the refusal quotes the colleague's request: ${sOut:0:300}" ;; esac

sOut=$(mcp_tool core_object_update '{"class":"UserRequest","id":'"$iOwn"',"fields":{"public_log":"Any news?"}}')
must "a reply on one's own request is accepted, as a dry run" "$sOut" ok
sOut=$(mcp_tool core_object_update '{"class":"UserRequest","id":'"$iOwn"',"fields":{"caller_id":'"$iOther"'},"simulate":false}')
must "a field the portal shows read-only is refused" "$sOut" refused
sOut=$(mcp_tool core_object_update '{"class":"UserRequest","id":'"$iOwn"',"fields":{"status":"closed"},"simulate":false}')
must "the state is not a field of the portal's form" "$sOut" refused
sOut=$(mcp_tool core_object_update '{"class":"UserRequest","id":'"$iOther"',"fields":{"public_log":"Hello"},"simulate":false}')
must "a colleague's request cannot be changed" "$sOut" refused
sOut=$(mcp_tool core_object_get '{"class":"UserRequest","id":'"$iOwn"',"output_fields":"status"}')
case "$sOut" in *'"status":"new"'*|*'"status": "new"'*) echo "   ok  and the request is still as it was" ;; *) fail "the request changed or cannot be read back: ${sOut:0:300}" ;; esac

echo "7. portal mode: portal_id names a portal or is refused"
sPortalUrl="$ENDPOINT?portal_id=no-such-portal"
if mcp_open "$sPortalUrl"; then
	sOut=$(mcp_tool core_current_user '{}')
	fail "a portal the user cannot enter was served (${sOut:0:200}), or silently replaced by another"
fi
case "$sBody$sHeaders" in *protocolVersion*) fail "a portal the user cannot enter was answered as a session" ;; esac
case "$sStatus" in 4??) echo "   ok  an unknown portal is refused (HTTP $sStatus)" ;; *) fail "an unknown portal was answered HTTP $sStatus, expected a 4xx" ;; esac
# The id becomes a file name that iTop then requires; each of these is its attempt at a different one.
for sHostile in '..%2F..%2Fconf%2Fproduction%2Fconfig-itop' 'itop-portal%0A' 'itop-portal%00.php' 'itop-portal.php' '%3C%3Fphp%20phpinfo()%3B%3F%3E' 'itop-portal%2F..%2Fitop-portal'; do
	if mcp_open "$ENDPOINT?portal_id=$sHostile"; then
		fail "portal_id=$sHostile was served"
	fi
	case "$sStatus" in 4??) ;; *) fail "portal_id=$sHostile was answered HTTP $sStatus, expected a 4xx" ;; esac
done
echo "   ok  a portal_id that is no plain name is refused, in each of its shapes"
sPortalUrl="$ENDPOINT?portal_id=itop-portal"
mcp_open "$sPortalUrl" || fail "the portal named correctly is no longer served after a refusal"
echo "   ok  and the next call that names a real one is served"
