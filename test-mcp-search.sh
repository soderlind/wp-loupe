#!/usr/bin/env bash
set -euo pipefail

# Optional DEBUG=1 for verbose curl and bash tracing.
DEBUG=${DEBUG:-0}

# Common curl options: fail on HTTP >=400, show errors, reasonable timeouts.
CURL_COMMON_OPTS=(--fail --show-error --connect-timeout 5 --max-time 30)
if [ "$DEBUG" -eq 1 ]; then
  CURL_COMMON_OPTS+=(-v)
  set -x
fi

BASE="${BASE:-http://plugins.local/loupe/wp-json/wp-loupe-mcp/v1}"
QUERY="a"               # Adjust to a common term in your content
AUTH_LIMIT_REQUEST=50    # Requested limit for authenticated search
ANON_LIMIT_REQUEST=50    # Requested limit for anonymous search (will clamp to anon cap)

issue_token() {
  local scope="$1"
  local json
  # Build JSON safely even with spaces in scope.
  if [[ -n "$scope" ]]; then
    json=$(printf '{"grant_type":"client_credentials","client_id":"wp-loupe-local","scope":"%s"}' "$scope")
  else
    json='{"grant_type":"client_credentials","client_id":"wp-loupe-local"}'
  fi
  curl -sS -L "${CURL_COMMON_OPTS[@]}" -X POST "$BASE/oauth/token" \
    -H 'Content-Type: application/json' \
    -d "$json" || return $?
}

issue_token_multiscope() {
  # Convenience wrapper for search.read + health.read
  issue_token "search.read health.read"
}

API_ROOT="$BASE" # already the full root to /wp-loupe-mcp/v1
echo "Preflight connectivity check (manifest endpoint): $API_ROOT/discovery/manifest" >&2
MF_CODE=$(curl -sS -L "${CURL_COMMON_OPTS[@]}" -o /dev/null -w '%{http_code}' "$API_ROOT/discovery/manifest" || echo "000")
echo "  Manifest HTTP: $MF_CODE" >&2
if [ "$MF_CODE" = "404" ]; then
  echo "  NOTE: 404 means REST route not found (check plugin active & correct BASE)" >&2
fi
echo "(GET /commands would 404; that's expected—only POST is implemented.)" >&2

echo "Issuing token (scopes: search.read)" >&2
TOKEN_JSON=$(issue_token "search.read" || true)
TOKEN=$(echo "$TOKEN_JSON" | jq -r .access_token 2>/dev/null || true)

if [[ -z "${TOKEN}" || "${TOKEN}" == "null" ]]; then
  echo "Token request did not return an access_token." >&2
  if [[ -z "${TOKEN_JSON}" ]]; then
    echo "No response body (possible network hang or timeout)." >&2
    echo "Suggestions:" >&2
    echo "  1. Try: DEBUG=1 bash $0" >&2
    echo "  2. Curl manually: curl -v -X POST $BASE/oauth/token -H 'Content-Type: application/json' -d '{\"grant_type\":\"client_credentials\",\"client_id\":\"wp-loupe-local\"}'" >&2
    echo "  3. Verify host resolves: ping -c1 $(echo $BASE | sed -E 's#https?://([^/]+)/.*#\1#')" >&2
    echo "  4. Check WP constants WP_LOUPE_OAUTH_CLIENT_ID / SECRET" >&2
  else
    echo "Raw response:" >&2
    echo "$TOKEN_JSON" >&2
  fi
  exit 1
fi

echo "Token acquired"

printf "\nAnonymous search (requesting limit=%s, expect <= anon cap, typically 10):\n" "$ANON_LIMIT_REQUEST"
ANON_RESP=$(curl -s -X POST "$BASE/commands" \
  -H 'Content-Type: application/json' \
  -d '{"command":"searchPosts","params":{"query":"'$QUERY'","limit":'$ANON_LIMIT_REQUEST'}}')
ANON_COUNT=$(echo "$ANON_RESP" | jq '.data.hits | length' 2>/dev/null || echo 0)
echo "Anonymous hits returned: $ANON_COUNT"

printf "\nAuthenticated search (requesting limit=%s, should honor higher cap):\n" "$AUTH_LIMIT_REQUEST"
AUTH_RESP=$(curl -sS -L "${CURL_COMMON_OPTS[@]}" -X POST "$BASE/commands" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"command":"searchPosts","params":{"query":"'$QUERY'","limit":'$AUTH_LIMIT_REQUEST'}}')
AUTH_COUNT=$(echo "$AUTH_RESP" | jq '.data.hits | length' 2>/dev/null || echo 0)
echo "Authenticated hits returned: $AUTH_COUNT"

printf "\nRate limit headers (authenticated request):\n"
curl -i -sS -L "${CURL_COMMON_OPTS[@]}" -X POST "$BASE/commands" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"command":"searchPosts","params":{"query":"'$QUERY'","limit":5}}' \
  | grep -i '^X-RateLimit' || true

printf "\nProtected healthCheck with search.read only (should fail scope):\n"
HEALTH_FAIL=$(curl -sS -o /dev/stderr -w '%{http_code}' -L "${CURL_COMMON_OPTS[@]}" -X POST "$BASE/commands" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"command":"healthCheck"}') || true
echo "HTTP status (expected 403): $HEALTH_FAIL"

printf "\nIssuing token with health.read scope\n"
TOKEN2_JSON=$(issue_token_multiscope || true)
TOKEN2=$(echo "$TOKEN2_JSON" | jq -r .access_token 2>/dev/null || true)
if [[ -z "$TOKEN2" || "$TOKEN2" == "null" ]]; then
  echo "Failed to obtain second token (health.read). Raw:" >&2
  echo "$TOKEN2_JSON" >&2
else
  echo "Calling healthCheck with proper scope:"
  HEALTH_OK=$(curl -sS -L "${CURL_COMMON_OPTS[@]}" -X POST "$BASE/commands" \
    -H "Authorization: Bearer $TOKEN2" \
    -H 'Content-Type: application/json' \
    -d '{"command":"healthCheck"}')
  if command -v jq >/dev/null 2>&1; then
    echo "$HEALTH_OK" | jq '{success:.success, data:.data, error:.error}'
  else
    echo "$HEALTH_OK"
  fi
  TOKEN2_SCOPES=$(echo "$TOKEN2_JSON" | jq -r .scope 2>/dev/null || echo '')
  [ -n "$TOKEN2_SCOPES" ] && echo "Second token scopes: $TOKEN2_SCOPES"
fi

printf "\nSummary:\n"
printf "  Anonymous count : %s\n" "$ANON_COUNT"
printf "  Auth count      : %s\n" "$AUTH_COUNT"

if (( AUTH_COUNT > ANON_COUNT )) && (( ANON_COUNT <= 10 )); then
  echo "RESULT: PASS (auth limit greater than anonymous)"
else
  echo "RESULT: CHECK (expected auth > anon and anon <= 10)"
fi

exit 0