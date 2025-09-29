# WP Loupe MCP Integration

![API Version](https://img.shields.io/badge/MCP%20API-v1-blue) ![Status](https://img.shields.io/badge/Status-Draft-orange)

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
| `search.read` | Perform search queries (required iff authenticated search) |
| `post.read` | Retrieve post metadata/content (future expansion) |
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

### `healthCheck`
Protected; returns environment diagnostics (`version`, `phpVersion`, `wpVersion`, `hasSqlite`, `timestamp`).

## Pagination
Cursor creation:
- Encode JSON `{ o: <nextOffset>, q: md5(query) }`
- Append HMAC SHA256 over payload using WP auth salt
- Base64URL encode

Validation rejects tampered or cross-query cursors.

## Rate Limiting
Applied only to `searchPosts` currently.
- Window: 60s (filterable)
- Anon default limit: 15 requests / window
- Auth default limit: 60 requests / window
- Headers set: `X-RateLimit-Limit`, `X-RateLimit-Remaining`

Filters:
| Filter | Purpose | Default |
|--------|---------|---------|
| `wp_loupe_mcp_rate_window_seconds` | Window length | 60 |
| `wp_loupe_mcp_search_rate_limit_anon` | Anonymous requests per window | 15 |
| `wp_loupe_mcp_search_rate_limit_auth` | Authenticated requests per window | 60 |
| `wp_loupe_mcp_search_max_limit_anon` | Max hits per search (anon) | 10 |
| `wp_loupe_mcp_search_max_limit_auth` | Max hits per search (auth) | 100 |

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

## Security Considerations
- Transient-backed tokens: ephemeral; not persistent beyond TTL.
- Token hash storage avoids leaking raw tokens via options table.
- No refresh tokens implemented (simple rotation model acceptable for server-to-server integrations).
- Consider adding per-client secret & revocation list for production.

## Roadmap (Potential Enhancements)
- Refresh tokens & revocation
- Persistent token storage (custom table)
- Per-command rate limiting & metrics
- Post mutation / indexing commands (scoped)
- Expanded schema or introspection command
- WP-CLI utilities for token issuance & cleanup
- Hardening: explicit deny-list / allow-list of IPs

## Changelog (MCP Portion)
- 0.5.0-draft: Initial hybrid MCP server (discovery, commands, OAuth client_credentials, pagination, rate limiting, scopes, ETag).

---
*This document will evolve as MCP capabilities expand.*
