# WP Loupe MCP Integration

![API Version](https://img.shields.io/badge/MCP%20API-v1-blue) ![Status](https://img.shields.io/badge/Status-Draft-orange)

<!-- TOC BEGIN (generated manually; update when headings change) -->
- [Overview](#overview)
- [Discovery](#discovery)
- [Authentication Model](#authentication-model)
  - [Enabling the MCP Server](#enabling-the-mcp-server)
  - [Token Management UI](#token-management-ui)
  - [Scopes](#scopes)
  - [Hybrid Rules](#hybrid-rules)
  - [Token Response](#token-response)
  - [Error Responses](#error-responses)
- [Commands](#commands)
  - [`searchPosts`](#searchposts)
  - [`getPost`](#getpost)
  - [`getSchema`](#getschema)
  - [`listCommands`](#listcommands)
  - [`healthCheck`](#healthcheck)
- [Pagination](#pagination)
- [Rate Limiting](#rate-limiting)
  - [Configuration UI (Preferred)](#-configuration-ui-preferred)
  - [Precedence Order](#precedence-order)
  - [HTTP Headers](#http-headers)
  - [Defaults (If Not Modified)](#defaults-if-not-modified)
  - [Filters (Optional Overrides)](#filters-optional-overrides)
- [Field Filtering and Heavy Fields](#field-filtering-and-heavy-fields)
- [Authorization Header Handling](#authorization-header-handling)
- [Error Semantics](#error-semantics)
- [Testing](#testing)
- [WP-CLI Usage](#wp-cli-usage)
- [Connecting from MCP-Capable Clients](#connecting-from-mcp-capable-clients)
  - [Claude Desktop](#1-claude-desktop-anthropic--local-mcp-server-config)
  - [VS Code Extensions](#2-vs-code--copilot--mcp-extensions)
  - [ChatGPT Workaround](#3-chatgpt-openai--custom-tool-manifest-workaround)
  - [cURL Reference](#4-curl--scripted-agent-reference)
  - [Rotating / Revoking Tokens](#5-rotating--revoking-tokens-for-clients)
  - [Field & Payload Optimization](#6-field--payload-optimization-tips)
  - [Troubleshooting](#7-troubleshooting)
  - [Security Best Practices](#8-security-best-practices)
  - [Manifest Cache Policy](#9-example-manifest-consumption-cache-policy)
- [Security Considerations](#security-considerations)
- [Indefinite Tokens (TTL = 0)](#indefinite-tokens-ttl--0)
- [Last-Used Tracking](#last-used-tracking)
- [Revoke-All Operation](#revoke-all-operation)
- [Adjustable TTL & Scopes Summary](#adjustable-ttl--scopes-summary)
- [Roadmap (Potential Enhancements)](#roadmap-potential-enhancements)
- [Changelog (MCP Portion)](#changelog-mcp-portion)
<!-- TOC END -->

| Property | Value |
|----------|-------|
| MCP API Version | v1 |
| Implementation Status | Draft/Beta |
| WordPress Minimum | 6.3+ |
| PHP Minimum | 8.2+ |

This document describes the Model Context Protocol (MCP) capabilities exposed by the WP Loupe plugin. It covers discovery, authentication, commands, pagination, rate limiting, and extension points.

## Overview
The MCP server provides a structured interface for external agents or tools to:
- Discover supported commands and metadata
- Perform search queries (anonymous or authenticated)
- Retrieve post data and schema information
- Perform health checks (authenticated)

The design uses **hybrid access**: anonymous users can perform limited search; authenticated clients (Bearer tokens) receive higher limits and access protected commands.

## Discovery
WP Loupe exposes two discovery endpoints:

| Purpose | Path | Notes |
|---------|------|------|
| MCP Manifest | `/.well-known/mcp.json` | High-level manifest (commands, scopes) with ETag caching |
| Protected Resource Metadata | `/.well-known/oauth-protected-resource` | OAuth2 resource metadata (subset) |

Fallback REST routes (if rewrite rules are missing):
- `GET /wp-json/wp-loupe-mcp/v1/discovery/manifest`
- `GET /wp-json/wp-loupe-mcp/v1/discovery/protected-resource`

If rewrites fail (multisite edge cases), a raw path fallback is used and a logging action fires:
`do_action( 'wp_loupe_mcp_raw_wellknown_fallback', $type, $uri )`.

## Authentication Model
Before any MCP endpoints are reachable you must explicitly enable the MCP server.

### Enabling the MCP Server
1. Navigate to: Settings → WP Loupe → MCP tab.
2. Check “Enable MCP Server” and save. This activates:
  - `/.well-known/mcp.json`
  - `/.well-known/oauth-protected-resource`
  - REST namespace: `/wp-json/wp-loupe-mcp/v1/*`
3. When disabled these endpoints return 404 (hard fail, not soft-deny) to reduce surface area.

You can toggle this at any time; existing transient tokens become unusable when disabled because command routing stops registering.

### Token Management UI
On the MCP tab (when enabled):
* Create a token by providing an optional label, selecting scopes, and setting a TTL (hours). The raw token is shown exactly once – copy it immediately.
* Scopes: uncheck any to restrict (principle of least privilege). All are pre-selected by default.
* TTL (hours): `1–168` (7 days) or `0` for a non-expiring (indefinite) token. Use `0` sparingly.
* List columns: Label, Scopes, Issued, Expires (`Never` if TTL=0), and Actions.
* Last-used timestamp (if present) is updated on each successful authenticated command.
* Revoke (single) removes a token immediately. Revoke All removes every issued token.
* CLI-issued tokens now appear automatically after issuance (registry mirroring). Older tokens from before this feature will not retroactively display.

Previously “future” features (scope selection, adjustable TTL) are now implemented.

Authentication uses **OAuth2 client_credentials** (scaffold-level) with in-memory (transient) token persistence.

- Token endpoint: `POST /wp-json/wp-loupe-mcp/v1/oauth/token`
- Body supports either JSON or form-encoded:
  - `grant_type=client_credentials`
  - `client_id=wp-loupe-local` (default)
  - `client_secret` (optional – if not defined by constant, secret-less dev mode allowed)
  - `scope` space-separated (optional)

### Scopes
| Scope | Description |
|-------|-------------|
| `search.read` | Perform search queries (higher auth limits) |
| `post.read` | Retrieve post metadata/content (future restrictions may apply) |
| `schema.read` | Access schema details |
| `health.read` | Health check command |
| `commands.read` | List commands metadata |

### Hybrid Rules
| Command | Anonymous | Authenticated Requirement |
|---------|-----------|---------------------------|
| `searchPosts` | Yes (lower limits) | `search.read` (if token presented) |
| `getPost` | Yes (currently unrestricted) | (Will enforce `post.read` if tightened) |
| `getSchema` | Yes | (Will enforce `schema.read` if tightened) |
| `listCommands` | Yes | `commands.read` (if enforced later) |
| `healthCheck` | No | `health.read` |

### Token Response
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "search.read health.read"
}
```

### Error Responses
Standard OAuth-style headers are set for auth errors, e.g.:
```
WWW-Authenticate: Bearer realm="wp-loupe", error="insufficient_scope", error_description="Missing required scopes: health.read", scope="health.read"
```

Possible error codes: `invalid_request`, `invalid_client`, `unsupported_grant_type`, `invalid_scope`, `invalid_token`, `insufficient_scope`, `invalid_header`, `missing_token`.

## Commands
All commands are invoked via:
`POST /wp-json/wp-loupe-mcp/v1/commands`

Request envelope:
```json
{
  "command": "searchPosts",
  "params": { ... },
  "requestId": "optional-correlation-id"
}
```

Response envelope:
```json
{
  "success": true,
  "error": null,
  "requestId": "...",
  "data": { ... }
}
```
On failure:
```json
{
  "success": false,
  "error": { "code": "rate_limited", "message": "Rate limit exceeded" },
  "data": null
}
```

### `searchPosts`
Search published posts/pages.

Params:
| Name | Type | Description |
|------|------|-------------|
| `query` | string (required) | Search phrase |
| `limit` | int (optional) | Max hits (auth: up to 100 default; anon: up to 10) |
| `cursor` | string (optional) | Pagination cursor |
| `fields` | string[] (optional) | Whitelist fields (subset of: id, title, excerpt, url, content, taxonomies, post_type) |
| `postTypes` | string[] (optional) | Restrict to specific post types |

Response `data` object:
```json
{
  "hits": [ { "id": 123, "title": "...", "url": "..." } ],
  "tookMs": 12,
  "pageInfo": { "nextCursor": "..." }
}
```

Pagination cursor is base64 + HMAC signed JSON containing offset + query hash.

### `getPost`
Retrieve a single published post by ID.
Params: `id` (required), `fields` (optional array).

### `getSchema`
Returns schema info (indexable/filterable/sortable fields per post type).

Example response:
```json
{
  "success": true,
  "error": null,
  "requestId": null,
  "data": {
    "post": {
      "indexable": ["post_title", "post_content", "post_excerpt", "post_date", "post_author", "permalink"],
      "filterable": ["post_title", "post_date", "post_author"],
      "sortable": ["post_title", "post_date", "post_author"]
    },
    "page": {
      "indexable": ["post_title", "post_content", "post_excerpt", "post_date", "post_author", "permalink"],
      "filterable": ["post_title", "post_date", "post_author"],
      "sortable": ["post_title", "post_date", "post_author"]
    }
  }
}

```

### `listCommands`
Returns metadata describing supported commands.

Example response:
```json
{
  "success": true,
  "error": null,
  "requestId": null,
  "data": {
    "healthCheck": {
      "description": "Return plugin / environment health information",
      "params": {}
    },
    "getSchema": {
      "description": "Retrieve index schema details for supported post types",
      "params": {}
    },
    "searchPosts": {
      "description": "Full-text search posts/pages with pagination cursor",
      "params": {
        "query": "string (required) search phrase",
        "limit": "int (optional, 1-100, default 10)",
        "cursor": "string (optional) pagination cursor",
        "fields": "string[] optional whitelist of fields (id,title,excerpt,url,content,taxonomies,post_type)",
        "postTypes": "string[] optional post types to restrict"
      }
    },
    "getPost": {
      "description": "Retrieve a single published post by ID with optional field selection",
      "params": {
        "id": "int (required) WordPress post ID",
        "fields": "string[] optional fields to include"
      }
    },
    "listCommands": {
      "description": "List available MCP commands and their parameter hints",
      "params": {}
    }
  }
}

```

### `healthCheck`
Protected; returns environment diagnostics (`version`, `phpVersion`, `wpVersion`, `hasSqlite`, `timestamp`).

## Pagination
Cursor creation:
- Encode JSON `{ o: <nextOffset>, q: md5(query) }`
- Append HMAC SHA256 over payload using WP auth salt
- Base64URL encode

Validation rejects tampered or cross-query cursors.

## Rate Limiting
Rate limiting currently applies to `searchPosts` and is **configurable via the MCP settings UI** or filter overrides.

### Configuration UI (Preferred)
On the MCP tab you can set:
| Setting | Anonymous | Authenticated | Notes |
|---------|-----------|---------------|-------|
| Requests per window | `anon_limit` | `auth_limit` | Max command invocations in a rolling window |
| Window (seconds) | `anon_window` | `auth_window` | Shared logical bucket per IP/token fragment |
| Max search hits per request | `max_search_anon` | `max_search_auth` | Caps the `limit` param requested by clients |

Saved values are stored in the option `wp_loupe_mcp_rate_limits` and immediately applied to future requests.

### Precedence Order
1. Saved option values (if present)
2. Filter overrides (allow deployment-specific adjustments without DB changes)
3. Hard-coded defaults (fallback only)

### HTTP Headers
Each `searchPosts` response includes:
* `X-RateLimit-Limit` – window quota in effect (post-filter, after option)
* `X-RateLimit-Remaining` – remaining allowance in the current window
* `Retry-After` – only present on 429 responses

### Defaults (If Not Modified)
| Context | Window | Requests | Max Hits per Search |
|---------|--------|----------|----------------------|
| Anonymous | 60s | 15 | 10 |
| Authenticated | 60s | 60 | 100 |

### Filters (Optional Overrides)
You can still override any piece via filters (they run after option retrieval):
| Filter | Purpose | Option-Based Default Passed In |
|--------|---------|--------------------------------|
| `wp_loupe_mcp_rate_window_seconds` | Effective window length (seconds) | `anon_window` or `auth_window` selected based on auth |
| `wp_loupe_mcp_search_rate_limit_anon` | Anonymous requests per window | Saved `anon_limit` |
| `wp_loupe_mcp_search_rate_limit_auth` | Auth requests per window | Saved `auth_limit` |
| `wp_loupe_mcp_search_max_limit_anon` | Max hits per search (anon) | Saved `max_search_anon` |
| `wp_loupe_mcp_search_max_limit_auth` | Max hits per search (auth) | Saved `max_search_auth` |

Example override (increase auth window only):
```php
add_filter( 'wp_loupe_mcp_rate_window_seconds', function( $window ) {
    if ( is_user_logged_in() ) { // or custom condition
        return 120; // 2 minute window
    }
    return $window;
});
```

### Implementation Details
The rate limiter keys buckets by client IP plus a token-fragment (derived from scopes) for authenticated requests. Anonymous traffic is grouped under `anon`. Buckets reset automatically after the configured window.

If a client exceeds the quota a standardized error is returned:
```json
{
  "success": false,
  "error": { "code": "rate_limited", "message": "Rate limit exceeded" },
  "data": null
}
```
With HTTP status `429` and `Retry-After` header indicating when to try again.

## Field Filtering and Heavy Fields
Clients can request specific fields to minimize payload size. Heavy fields like `content` and `taxonomies` are only included if explicitly requested.

## Authorization Header Handling
Robust extraction checks `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, and `getallheaders()`. Custom environments may override via:
`apply_filters( 'wp_loupe_mcp_raw_authorization_header', $header )`.

## Error Semantics
Error envelope codes map to transport-level status codes. Auth errors also emit `WWW-Authenticate`.
| Code | Typical HTTP | Notes |
|------|--------------|-------|
| `missing_command` | 400 | No `command` field |
| `unknown_command` | 400 | Unsupported command name |
| `invalid_header` | 401 | Malformed `Authorization` header |
| `missing_token` | 401 | Protected command w/out auth |
| `invalid_token` | 401 | Expired or unknown token |
| `insufficient_scope` | 403 | Token lacks required scope |
| `rate_limited` | 429 | Rate limit exceeded |

## Testing
A helper script is included: `test-mcp-search.sh`.

Example manual token issuance:
```bash
curl -s -X POST "$BASE/oauth/token" \
  -H 'Content-Type: application/json' \
  -d '{"grant_type":"client_credentials","client_id":"wp-loupe-local","scope":"search.read health.read"}'
```

Search example:
```bash
curl -s -X POST "$BASE/commands" \
  -H 'Content-Type: application/json' \
  -d '{"command":"searchPosts","params":{"query":"hello","limit":5}}'
```

## WP-CLI Usage
You can issue tokens directly via WP-CLI (helpful for server-to-server integration without crafting HTTP calls manually).

### Multisite Note
If WordPress is running as a multisite network and the WP Loupe plugin is activated on a specific sub‑site, you MUST scope WP-CLI commands to that site using `--url`. Tokens are stored per-site (transient cache); issuing a token on the network root will not make it valid for a sub-site endpoint.

Example (sub-site at `http://plugins.local/loupe/`):
```bash
wp --url=http://plugins.local/loupe/ wp-loupe mcp issue-token --scopes="search.read health.read" --format=json
```
Then use the returned access_token against the sub-site REST endpoint:
```bash
TOKEN="<paste-token>"
curl -s -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"command":"healthCheck"}' \
  http://plugins.local/loupe/wp-json/wp-loupe-mcp/v1/commands
```

If you omit `--url` you may see `invalid_token` because the token transient was written for a different blog/site ID.

List help:
```bash
wp help wp-loupe mcp issue-token
```

Issue a token with default (all) scopes:
```bash
wp wp-loupe mcp issue-token
```

Issue a token limited to search + health scopes (JSON output):
```bash
wp wp-loupe mcp issue-token --scopes="search.read health.read"
```

Table output format:
```bash
wp wp-loupe mcp issue-token --format=table
```

Specify explicit client credentials (if constants configured):
```bash
wp wp-loupe mcp issue-token --client_id=wp-loupe-local --client_secret="$CLIENT_SECRET"
```

The command returns (JSON format):
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "search.read health.read"
}
```

## Connecting from MCP-Capable Clients

Below are quick-start instructions for integrating the WP Loupe MCP server with popular agent / IDE environments. Always create a scoped token (principle of least privilege) unless you intentionally allow anonymous low‑limit access.

### 1. Claude Desktop (Anthropic) – Local mcp-server Config
Claude Desktop supports local JSON config listing MCP servers. Add or extend your `claude_desktop_config.json` (path varies by OS). Example entry:

```jsonc
{
  "mcpServers": {
    "wp-loupe": {
      "type": "http",
      "url": "https://example.com/.well-known/mcp.json",
      "headers": {
        "Authorization": "Bearer REPLACE_WITH_TOKEN"
      }
    }
  }
}
```

Steps:
1. Enable MCP in WP admin and create a token with scopes you need (e.g., `search.read health.read`).
2. Paste token into the header above.
3. Restart Claude Desktop; it should list `wp-loupe` as a connected tool. Use natural prompts like: “Search WordPress for posts about performance using wp-loupe.”

Anonymous mode: Remove `headers` object—Claude will still reach the server but hit anonymous limits (can’t run `healthCheck`).

### 2. VS Code – Copilot / MCP Extensions
If using an MCP-compatible VS Code extension (e.g., experimental MCP bridge), configure a server entry similar to:

```jsonc
// .vscode/mcp.json (example – actual file name/extension may differ)
{
  "servers": [
    {
      "name": "wp-loupe",
      "manifestUrl": "https://example.com/.well-known/mcp.json",
      "auth": {
        "type": "bearer",
        "token": "REPLACE_WITH_TOKEN"
      }
    }
  ]
}
```

After reload, trigger the extension’s command palette action (e.g., “MCP: Refresh Servers”) and invoke commands via chat or command listing UI. If the extension supports streaming, search output appears as JSON payloads; you can refine queries by adjusting `limit` or `fields`.

### 3. ChatGPT (OpenAI) – Custom Tool Manifest (Workaround)
ChatGPT doesn’t (yet) natively load arbitrary MCP manifests, but you can approximate integration by:
1. Supplying the manifest JSON inline (copy from `/.well-known/mcp.json`).
2. Instructing ChatGPT to treat `POST https://example.com/wp-json/wp-loupe-mcp/v1/commands` as the primary endpoint with envelope `{command, params}`.
3. Providing a fixed Bearer token (remove after session if temporary).

Prompt snippet:
```
You are an assistant with access to a WordPress MCP search API. To search, POST JSON to:
https://example.com/wp-json/wp-loupe-mcp/v1/commands
Body example: {"command":"searchPosts","params":{"query":"performance", "limit":5}}
Auth header: Authorization: Bearer TOKEN
Return only the 'hits' array when summarizing unless I ask for raw JSON.
```

Limitations: No automatic schema refresh; you must paste updated manifest if scopes or commands change.

### 4. cURL / Scripted Agent Reference
Minimal scriptable invocation (with token):
```bash
TOKEN="YOUR_TOKEN"; BASE="https://example.com/wp-json/wp-loupe-mcp/v1"
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"command":"searchPosts","params":{"query":"accessibility","limit":5}}' \
  "$BASE/commands" | jq '.data.hits'
```

### 5. Rotating / Revoking Tokens for Clients
If a workstation is lost or you suspect leakage:
1. Open MCP tab → “Revoke All Tokens” (immediate invalidation)
2. Issue replacement tokens and update client configs.

### 6. Field & Payload Optimization Tips
| Goal | Param Strategy |
|------|----------------|
| Minimal metadata | `fields: ["id","title","url"]` |
| Include taxonomy slugs | Add `taxonomies` to `fields` |
| Full content fetch | Use small search limit, then `getPost` per ID with `content` |

### 7. Troubleshooting
| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| 404 on manifest URL | MCP not enabled | Enable in admin MCP tab |
| 401 invalid_token | Wrong / expired token | Issue new token, update config |
| 403 insufficient_scope | Missing scope (e.g., `search.read`) | Reissue token with required scopes |
| 429 rate_limited | Quota exceeded | Wait for window or raise limits (if appropriate) |
| Cursor yields no progress | Query text changed | Discard old cursor and restart search |

### 8. Security Best Practices
* Use separate tokens per client (enables per-client last_used audit).
* Prefer short TTL tokens; only use indefinite (0) for tightly controlled back-end tasks.
* Scope-minimize: if client only searches, drop `health.read`.
* Rotate tokens periodically and after personnel changes.
* Monitor server logs (add custom logging via filters/actions if needed) to detect abuse patterns.

### 9. Example Manifest Consumption Cache Policy
MCP clients should honor ETag headers on `/.well-known/mcp.json` to reduce bandwidth and auto-refresh capabilities when changed.

---

## Security Considerations
- Transient-backed tokens: ephemeral; not persistent beyond TTL.
- Token hash storage avoids leaking raw tokens via options table.
- No refresh tokens implemented (simple rotation model acceptable for server-to-server integrations).
- Consider adding per-client secret & revocation list for production.

## Indefinite Tokens (TTL = 0)
Setting TTL to `0` issues a logically non-expiring token (`expires_at = 0`). It is cached for a long duration (currently 1 year in transient storage) and treated as never expiring in validation. Prefer time-bound tokens whenever possible and revoke indefinite tokens if no longer required.

## Last-Used Tracking
Each authenticated command updates `last_used` for that token. This enables future automation (e.g., pruning stale tokens or alerting on dormant credentials).

## Revoke-All Operation
The “Revoke All Tokens” action wipes every active token and its transient. Use during incident response or credential rotation.

## Adjustable TTL & Scopes Summary
| Feature | Supported | Notes |
|---------|-----------|-------|
| Scope selection | Yes | All pre-selected; uncheck to restrict |
| TTL hours | Yes | 1–168 or 0 (never) |
| Indefinite tokens | Yes (0) | Use sparingly; rotate manually |
| Last-used tracking | Yes | Updated on success |
| Bulk revoke | Yes | One-click cleanup |

## Roadmap (Potential Enhancements)
- Refresh tokens & revocation list
- Persistent token storage (custom table) for durability & querying
- Per-command rate limiting & usage metrics
- Write / indexing commands with dedicated scopes
- Schema introspection expansion
- WP-CLI: pass TTL + list/revoke tokens natively
- Hardening: IP allow/deny lists & anomaly detection
- Automated stale token pruning (using `last_used`)

## Changelog (MCP Portion)
- 0.5.2-draft: Added configurable rate limit UI (window, per-window quotas, max search hits) with option + filter precedence; server now consumes saved configuration.
- 0.5.1-draft: Added scope selection UI, adjustable TTL (0 = indefinite), revoke-all, last-used tracking, indefinite token handling.
- 0.5.0-draft: Initial hybrid MCP server (discovery, commands, OAuth client_credentials, pagination, rate limiting, scopes, ETag).

---
*This document will evolve as MCP capabilities expand.*

<!--
TOC MAINTENANCE
The TOC at the top of this document is maintained manually. When adding or renaming headings:
1. Collect all level 2 (##) and important level 3 (###) headings.
2. Generate anchor IDs using GitHub slug rules (lowercase, spaces -> dashes, strip punctuation).
3. Update the list between <!-- TOC BEGIN --> and <!-- TOC END --> accordingly.
4. Keep ordering identical to document flow.
5. Avoid deep nesting unless readability benefits.
-->
