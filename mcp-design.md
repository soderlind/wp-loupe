# WP Loupe MCP Server Design (Draft)

Status: Draft (Initial) – STOP after this file per instructions
Target Version: 0.5.0 (incremental preview) then 0.6.0 (stabilize)
Author: AI Assistant (to be reviewed)
Date: 2025-09-21

## 1. Goal & Summary
Add a Remote HTTP-based Model Context Protocol (MCP) server interface to WP Loupe so AI agents / MCP clients can discover, authorize, and invoke WordPress search and index management capabilities using standardized MCP resources & commands. This layer will wrap existing WP Loupe search/index functions, integrate with the WordPress Abilities API + MCP Adapter, and expose OAuth 2.1 secured endpoints with auto-discovery.

## 2. Scope (Initial Phase)
In-scope (Phase 1):
- Read-only search across configured post types (aligned with existing Loupe index)
- Retrieval of single post metadata & content (sanitized) for allowed fields
- Retrieval of index schema, indexed fields & configuration weights
- Health/status (version, index freshness, SQLite availability)
- Auto-discovery & protected resource metadata for authorization
- OAuth 2.1 (Authorization Code + PKCE) integration leveraging WP as Authorization Server (plugin-provided minimal AS or integration with existing OAuth / Application Password replacement if available)
- Abilities API mapping to granular scopes

Future (later phases, not implemented initially):
- Index mutation commands (reindex, selective reindex, field config updates)
- Streaming events (indexing progress, invalidation notices)
- Multi-site / network site aggregation
- Tool invocation for content generation or summarization
- Webhook / push events (Server-Sent Events or WebSocket transport variant)

Out of scope (explicitly not planned for first two releases):
- Acting as a proxy to 3rd-party APIs (avoid confused deputy risk)
- Non-HTTP transports (STDIO, WebSocket) – could be added later

## 3. Architectural Overview
Components to add:
1. MCP Controller Layer (REST-like endpoints under `/wp-json/wp-loupe-mcp/v1/*` and well-known discovery resources).
2. OAuth / Auth Middleware (Bearer token validation + scope enforcement, maps token claims to Abilities API capabilities).
3. Discovery Endpoints:
   - `/.well-known/oauth-protected-resource` (RFC9728) including `authorization_servers`.
   - `/.well-known/mcp.json` (custom convenience manifest for MCP Adapter auto-discovery – contains canonical MCP base URL, supported commands, resource types, version, abilities mapping). If spec evolves to a standardized MCP well-known, adjust accordingly.
4. Abilities Adapter Integration:
   - Define abilities: `wp_loupe.search`, `wp_loupe.post.read`, `wp_loupe.schema.read`, `wp_loupe.health.read`.
   - Ability → OAuth scope mapping (e.g., `wp_loupe.search` => `search.read`).
5. MCP Command Router: maps JSON command invocations to internal service functions (search, getPost, listSchemas, healthCheck).
6. Serialization Layer: ensures output data matches MCP spec expectations (deterministic field ordering, explicit types, pagination cursors).
7. WordPress Native Function Usage: Favor core helpers (`rest_url()`, `home_url()`, `is_ssl()`, `wp_json_encode()`, `current_user_can()`, `get_option()/update_option()`, `add_rewrite_rule()`, `apply_filters()`, `wp_remote_get()` for outbound validation) to minimize custom glue and leverage existing security / escaping. Avoid reinventing routing or option handling.

Data Flow (search example):
Client → Authorization (OAuth) → Bearer token → MCP command `searchPosts` → WP_Loupe_Search::search() → result shaping (score, snippet, post_type) → Response JSON.

## 4. Transports & Endpoints
Transport: HTTP (JSON). All endpoints require HTTPS in production.
Canonical Base (example): `https://example.com/wp-json/wp-loupe-mcp/v1`

Proposed Endpoints (Phase 1):
- `GET /.well-known/oauth-protected-resource` – Protected Resource Metadata (lists `authorization_servers`, `resource` identifier, supported scopes).
- `GET /.well-known/mcp.json` – MCP manifest (non-authoritative convenience for auto-discovery + caching; includes version, capabilities).
- `POST /wp-json/wp-loupe-mcp/v1/commands` – Generic command execution `{ "command": "searchPosts", "params": {...} }`.
  (Alternative: individual REST routes; using a single command endpoint simplifies uniform auth & logging consistent with MCP.)
- `GET /wp-json/wp-loupe-mcp/v1/resources/post/{id}` – Fetch a single post (sanitized) (optional alias to command form `getPost`).
- `GET /wp-json/wp-loupe-mcp/v1/resources/schema` – Index schema summary.
- `GET /wp-json/wp-loupe-mcp/v1/health` – Health + version + index freshness timestamps.

(Phase 2 additions would include mutation commands e.g., `reindex`, `updateFields`.)

## 5. Authentication & Authorization
Model: OAuth 2.1 Authorization Code with PKCE. Short-lived Access Tokens (e.g., 10–15 min) + Refresh Tokens (rotated).
Resource (audience) Identifier: canonical MCP base URI (no trailing slash). Tokens must include correct audience.
Scopes (Access Token claims) mapped to Abilities:
| Scope | Ability | Description |
|-------|---------|-------------|
| `search.read` | `wp_loupe.search` | Perform search queries |
| `post.read` | `wp_loupe.post.read` | Retrieve single post data |
| `schema.read` | `wp_loupe.schema.read` | View index schema/fields |
| `health.read` | `wp_loupe.health.read` | Check health endpoint |

Enforcement Strategy:
- Middleware validates: HTTPS, Authorization header, token signature (if JWT) or introspection, audience match, expiry, required scope(s).
- Failure Modes: 401 (missing/invalid token), 403 (insufficient scope), 400 (malformed request).

Dynamic Client Registration:
- If a WordPress OAuth server plugin supports RFC7591, surface its registration endpoint in discovery metadata.
- If absent, document manual client provisioning (admin UI section in future).

Token Storage Considerations:
- Server never logs raw access tokens.
- Optional: store hashed refresh tokens with rotation; implement revocation list.

## 6. Commands (Phase 1)
All commands invoked via `POST /commands` with envelope:
```
{
  "command": "searchPosts",
  "params": { ... },
  "requestId": "uuid-optional"
}
```
Response envelope:
```
{
  "requestId": "...", // echoes if provided
  "success": true,
  "data": { ... },
  "error": null
}
```
Error example:
```
{
  "success": false,
  "error": { "code": "invalid_scope", "message": "Scope search.read required" }
}
```

### 6.1 searchPosts
Params:
- `query` (string, required)
- `postTypes` (array<string>, optional, default all configured)
- `limit` (int, 1–100, default 10)
- `cursor` (opaque string pagination token) OR `page` (int) – choose one model; prefer cursor for stability
- `fields` (array<string>, optional allowed subset; defaults to safe set: id,title,url,excerpt,score,post_type)
Response `data`:
```
{
  "hits": [
    {"id":123,"post_type":"post","title":"...","url":"...","excerpt":"...","score":0.82}
  ],
  "pageInfo": {"nextCursor":"..."},
  "tookMs": 12
}
```
Authorization: `search.read`

### 6.2 getPost
Params: `id` (int), `fields` (whitelist)
Response: sanitized metadata + content (if allowed & not password protected)
Authorization: `post.read`

### 6.3 getSchema
Returns:
```
{
  "postTypes": {
    "post": {
      "indexable": [{"field":"post_title","weight":2.0},...],
      "filterable": ["post_date",...],
      "sortable": [{"field":"post_date","direction":"desc"}]
    }
  }
}
```
Authorization: `schema.read`

### 6.4 healthCheck
Returns version, timestamp, db path status, sqlite version, number of indexed docs (per type) & last index rebuild time (if tracked). Scope: `health.read`.

## 7. Resources (Conceptual Mapping)
MCP Resource Types (Phase 1 read-only):
- `post` – WordPress post/page/custom post summary
- `searchResult` – container for hits + pageInfo
- `schema` – index configuration snapshot
- `health` – operational status

Future: `indexTask`, `event` (stream), `taxonomy`, `media`.

## 8. Abilities API Integration
Define abilities via filter or registration in plugin init:
- Provide metadata: label, description, default allowed roles (e.g., administrator for schema, health; editor for search/post read; filterable by site admin).
- Adapter maps these abilities for MCP Adapter consumption so that external clients can request them.

Internal mapping layer will check both:
1. Token scopes
2. Current WP user permission when token is bound to a user (optional user-binding claim `sub` -> WP user ID).

## 9. Auto-Discovery
Mechanisms:
1. Protected Resource Metadata (`/.well-known/oauth-protected-resource`) – lists canonical resource URI, supported scopes, authorization_servers.
2. MCP Manifest (`/.well-known/mcp.json`) – JSON containing:
```
{
  "name": "wp-loupe",
  "version": "0.5.0-draft",
  "canonical": "https://example.com/wp-json/wp-loupe-mcp/v1",
  "commands": ["searchPosts","getPost","getSchema","healthCheck"],
  "resources": ["post","searchResult","schema","health"],
  "scopes": {"search.read":"Search indexed content", ...},
  "abilities": {"wp_loupe.search":{"scope":"search.read"}, ...}
}
```
3. WWW-Authenticate header injection on 401 with `resource_metadata` pointer (per RFC9728) enabling client-first probe pattern.

### 9.1 Implementation Details (WordPress Specific)
- Use a rewrite rule instead of physical files for the `/.well-known/*` paths so WordPress can generate dynamic metadata without touching the web root. Example (added on `init`):
  - `add_rewrite_rule('^\.well-known/oauth-protected-resource/?$', 'index.php?wp_loupe_mcp_wellknown=protected', 'top');`
  - `add_rewrite_rule('^\.well-known/mcp.json/?$', 'index.php?wp_loupe_mcp_wellknown=mcp_manifest', 'top');`
- Register query var via `add_filter('query_vars', ...)` to allow detection in `template_redirect` (or an earlier hook) and output JSON with correct headers (`Content-Type: application/json; charset=UTF-8`).
- Flush rewrite rules on activation / deactivation only (`register_activation_hook` + `flush_rewrite_rules();`). Do NOT flush on every request.
- Fallback: If server forbids dot-prefixed rewrite, also expose copies under `/wp-json/wp-loupe-mcp/v1/.well-known/*` and advertise via `Link` headers.
- Cache responses (transient or object cache) for 5 minutes; purge when abilities or schema settings change.

Caching: Send `Cache-Control: public, max-age=300` and `ETag` headers for manifest & metadata.

## 10. Security Considerations
- Enforce HTTPS (abort if `!is_ssl()` except local dev w/ constant override).
- Audience validation for tokens (no token passthrough to internal/other APIs).
- Reject tokens without required scope quickly.
- Rate limiting (WP transients keyed by token+IP; defaults: 60 searchPosts/min, 300 getPost/min). Pluggable filter.
- Output field whitelisting; never leak unpublished / password protected posts; exclude protected meta keys (prefix `_`).
- SQL / injection minimized (interacts only with Loupe + WP core functions).
- Token not logged; sanitize error output.

## 11. Performance
- Use existing Loupe caching (transients). Add MCP-layer request cache keyed by (command, params hash, scope) for 30s micro-cache to smooth bursts.
- Support partial field selection to minimize payload.
- Cursor-based pagination token contains (post_type set, lastHitCompositeKey, hash) signed with HMAC (secret WP salt) to prevent tampering.

## 12. Open Questions / Decisions Pending
| Topic | Question | Proposed Default |
|-------|----------|------------------|
| OAuth Server | Bundle minimal AS or rely on existing plugin? | Detect existing (e.g., Application Passwords inadequate) → if none, ship lightweight AS limited to code+PKCE flow |
| Token Format | JWT vs opaque introspected | Prefer JWT (signed HS256 with site-specific key) for fewer DB hits |
| Dynamic Client Registration | Implement now? | If AS supports; else document manual path |
| Multi-site canonical URI | Per site vs network aggregate | Per site first |
| Version negotiation | Manifest field vs header | Embed `mcpVersion` in manifest; add `X-WP-Loupe-MCP-Version` header |
| Command endpoint path | Single `/commands` vs per-command routes | Single endpoint (simplicity) + optional alias routes |

## 13. Phased Implementation Plan
Phase 1 (0.5.0 draft):
1. Add manifest & protected resource metadata endpoints + auth skeleton (401 flow, WWW-Authenticate header).
2. Implement bearer validation abstraction (pluggable) – temporarily allow a development token (constant) until OAuth server integrated.
3. Implement `searchPosts`, `getPost`, `getSchema`, `healthCheck` commands.
4. Add Abilities registration + scope mapping.
5. Documentation & examples.

Phase 1.1: Replace dev token path with full OAuth 2.1 (authorization code + PKCE) integration once AS selected/built.

Phase 2 (0.6.0):
- Add reindex & index status commands (write scopes), cursor pagination, rate limiting, event streaming stub.

## 14. Data Models (JSON Shapes)
### Post
```
{
  "id": 123,
  "post_type": "post",
  "title": "...",
  "url": "https://...",
  "excerpt": "...",
  "content": "...", // optional (requested & allowed)
  "date": "2025-09-21T10:11:12Z",
  "taxonomies": {"category": ["News"], "post_tag": ["ai"]},
  "score": 0.83
}
```

### SearchResult
```
{
  "hits": [PostSummary...],
  "totalApprox": 123, // optional future
  "pageInfo": {"nextCursor": "opaque"},
  "tookMs": 14
}
```

### Schema
```
{
  "post": {
    "indexable": [{"field":"post_title","weight":2}],
    "filterable": ["post_date"],
    "sortable": [{"field":"post_date","direction":"desc"}]
  }
}
```

### Health
```
{
  "version": "0.5.0-draft",
  "sqliteVersion": "3.45.x",
  "hasSqlite": true,
  "indexedCounts": {"post": 1234, "page": 42},
  "lastReindex": "2025-09-20T09:00:00Z"
}
```

## 15. WordPress Integration Points
- Hook: `rest_api_init` to register endpoints & command route.
- Filter: `determine_current_user` for bearer token user binding (optional).
- Action: `init` to register abilities & manifest caching.
- Transients: micro-cache of command responses.
- Constants (dev): `WP_LOUPE_MCP_DEV_TOKEN` for early testing.
- Rewrite: `init` hook registers `/.well-known` rewrite rules and query vars; activation hook flushes.
- Capabilities: map token scopes to WP capabilities (`current_user_can()`) where appropriate (e.g., schema → `manage_options`).
- JSON Output: use `wp_send_json()` / `wp_send_json_success()` / `wp_send_json_error()` for consistency; avoid manual `echo json_encode`.
- URL Construction: use `rest_url()` and `home_url()` instead of manual concatenation to respect site settings (e.g., subdirectory installs, HTTPS enforcement).
- Security Nonce (future write commands): leverage `wp_create_nonce()` + `check_ajax_referer()` when mixing browser-initiated admin flows with MCP config screens.

## 21. WordPress Native Function Strategy (Summary)
| Concern | Preferred Core API | Notes |
|---------|--------------------|-------|
| Routing (`/.well-known`) | `add_rewrite_rule`, `template_redirect` | Keeps root clean, dynamic generation |
| JSON responses | `wp_send_json*` | Handles headers + encoding + exit |
| Caching | `set_transient` / `wp_cache_*` | 5 min for discovery docs |
| Permissions | `current_user_can` | Map scopes → capabilities defensively |
| Options persistence | `get_option` / `update_option` | Namespaced options: `wp_loupe_mcp_*` |
| URL building | `rest_url`, `home_url`, `site_url` | Avoid hard-coded paths |
| Logging (debug) | `error_log` (filtered) / WP Debug Log | Guard with `WP_DEBUG` |
| Encoding | `wp_json_encode` | Ensures proper UTF-8 handling |
| Activation tasks | `register_activation_hook` | Flush rewrites, seed caches |


## 16. Risks & Mitigations
| Risk | Mitigation |
|------|------------|
| OAuth complexity delays feature | Phase with dev token; modular auth provider interface |
| Over-exposing private data | Strict field allowlist + scope checks + status filtering |
| Performance under high-frequency search | Micro-cache + existing Loupe efficiencies; rate limiting |
| Spec evolution changes manifest expectations | Version field; backward-compatible additions |
| Multi-site ambiguity | Limit to single-site initially; future network aggregator design |

## 17. Testing Strategy (Phase 1)
- Unit: command dispatcher, schema serialization, cursor encoder/decoder.
- Integration: searchPosts vs direct WP_Loupe_Search parity (result count, order).
- Security: unauthorized -> 401, missing scope -> 403, tampered cursor -> 400.
- Performance: benchmark searchPosts concurrency (baseline vs micro-cache).
- Lint: PHP 7.4+/8.x compatibility (match plugin target), JSON schema validation (optional).

## 18. Documentation & Developer Experience
- README section: "MCP Integration" with steps to enable, generate dev token, discover endpoints, run sample `curl` searches.
- Example manifest snippet + OAuth authorization example URL with `resource` param.
- Provide Postman / Hoppscotch collection (future).

## 19. Future Enhancements
- WebSocket / SSE streaming for indexing progress.
- Write commands (reindexSelected, updateSchemaFieldWeights) with admin scopes.
- Tool commands: summarizePost, extractKeywords (leveraging additional ML libs) – out of MVP.
- Multi-lingual segmentation / translation hints.

## 20. Open Items for Review
- Should we standardize `/.well-known/mcp.json` naming or choose `/.well-known/wp-loupe-mcp`? (Pending spec guidance)
- Decide minimum PHP version & dependency injection pattern for auth provider.
- Confirm whether Abilities API will provide automatic manifest augmentation (investigate upstream adapter behavior).

---
END OF DRAFT (awaiting further instructions before implementation).
