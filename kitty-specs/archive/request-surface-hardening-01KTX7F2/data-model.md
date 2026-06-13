# Data Model: Request-Surface Hardening

**Date**: 2026-06-12 | **Plan**: [plan.md](plan.md)

No new entity types, no schema changes. The "data model" of this mission is the discoverable-flag semantics, the two response shapes, the bearer decision table, and the path-resolution matrix.

## Discoverable flag (EntityType)

| Member | Type | Default | Semantics |
|---|---|---|---|
| `discoverable` (ctor param) | `bool` | `true` | Whether the type may appear in the `GET /api` discovery index |
| `isDiscoverable()` (accessor) | `bool` | — | Read by `ApiDiscoveryController` (duck-typed via `method_exists`) |

- `fromClass()` gains a `bool $discoverable = true` passthrough; like other non-tenancy overrides it is first-call-wins under the per-class cache (framework norm: one canonical EntityType per class).
- `EntityTypeInterface` is unchanged; definitions not exposing `isDiscoverable()` are treated as discoverable (open default).
- The flag gates **index visibility only**. CRUD routes for non-discoverable types register and enforce access exactly as before. `discoverable: false` types are absent for every caller — anonymous, authenticated, admin (FR-002 "never appear, for any caller").

## Discovery index — visibility decision per type

```
listed(type, account) = isDiscoverableDuckTyped(type)        // false → hidden from EVERYONE
                      ∧ account !== null
                      ∧ account->isAuthenticated()           // anonymous → zero type links
```

| Caller | Non-discoverable type | Discoverable type |
|---|---|---|
| Anonymous (`AnonymousUser`, id 0) | absent | **absent** (authenticated-only default, research D1) |
| Authenticated account (any) | absent | listed |
| No account in scope (`_account` missing — non-HTTP/test invocation) | absent | absent (fail closed) |

Response envelope is constant for all callers: `meta {api: 'waaseyaa', version: '1.0'}` + `links.self: '/api'`. Only the per-type link members vary. Route access (`allowAll()` / `_public`) is unchanged.

## Not-found shape (the canonical 404 reused for denied singles)

One private factory in `JsonApiController`, two callers (missing branch, denied-view branch):

```
JsonApiDocument::fromErrors([JsonApiError::notFound(
    "Entity of type '{entityTypeId}' with ID '{id}' not found."
)], statusCode: 404)
```

Serialized error object — byte-pinned by the NFR-002 test:

```json
{ "status": "404", "title": "Not Found",
  "detail": "Entity of type '<type>' with ID '<id>' not found." }
```

- **No `code` member** (notFound never sets one; the old denied path's `code: "FORBIDDEN"` disappears).
- The `<id>` interpolated is the caller-supplied id in both branches — identical bytes for the same probe.
- Headers: identical by construction (single `jsonApiResponse()` emitter in `JsonApiRouter`).
- Uniform in all environments — no debug-mode variant (research D3).

### Per-operation denial responses after this mission (FR-003/FR-004)

| Operation | Denied check | Response |
|---|---|---|
| GET single (`show`) | `view` not allowed | **404 not-found shape (changed — C-001)** |
| GET single, id does not exist | — | 404 not-found shape (unchanged) |
| GET collection (`index`) | row-level filter | 200 with filtered `data[]` (unchanged; #1605 out of scope) |
| POST (`store`) | `createAccess` not allowed | 403 forbidden (unchanged) |
| PATCH (`update`) | `update` not allowed | 403 forbidden (unchanged) |
| DELETE (`destroy`) | `delete` not allowed | 403 forbidden (unchanged) |
| Field edit (store/update paths) | field forbidden | 403 forbidden (unchanged) |

## Bearer authentication decision table (FR-005/FR-006)

`BearerTokenAuth::authenticate(?string $authorizationHeader): ?AccountInterface`

| Step | Input state | Result |
|---|---|---|
| 1 | header null/empty/non-`bearer ` prefix | `null` (unchanged) |
| 2 | full scan: `hash_equals((string) $candidate, $token)` per map entry, **no early exit** | matched account or `null` |
| 3 | match found ∧ account exposes `isActive()` ∧ `isActive() === false` | **`null` — fail closed (NEW)** |
| 4 | match found ∧ (no `isActive()` method ∨ `isActive() === true`) | the account |

- Rejection at step 3 is byte-indistinguishable from step 2's no-match (same `null` → same 401 JSON-RPC envelope from `McpEndpoint`).
- `(string)` cast at step 2 is mandatory: numeric-string tokens become `int` array keys (PHP key coercion) and `hash_equals` requires strings.
- Freshness model: kernels (hence token maps and their account objects) are built per request in every runtime, so the step-3 read reflects current persisted state as of this request — zero added queries (NFR-003).
- `getTokens()` (admin fingerprinting) returns the raw map, unchanged.

## Database path resolution matrix (FR-007/FR-008)

Precedence (unchanged): `config['database']` → `WAASEYAA_DB` env → `{projectRoot}/storage/waaseyaa.sqlite`.

Resolution of the selected value (NEW — applied in `DatabaseBootstrapper::resolvePath()`, mirrored by `DbInitHandler`):

| Selected value | Classified | Resolved to |
|---|---|---|
| *(unset — default)* | absolute | `{projectRoot}/storage/waaseyaa.sqlite` (byte-identical to today) |
| `:memory:` | sentinel | `:memory:` (untouched; never warns) |
| `/var/db/app.sqlite` | absolute (leading `/`) | untouched |
| `C:\data\app.sqlite`, `C:/data/app.sqlite` | absolute (drive letter) | untouched |
| `\\server\share\app.sqlite` | absolute (UNC) | untouched |
| `./storage/waaseyaa.sqlite` | relative | `{projectRoot}/storage/waaseyaa.sqlite` |
| `storage/waaseyaa.sqlite` | relative | `{projectRoot}/storage/waaseyaa.sqlite` |
| `../shared/db.sqlite` | relative (climbing) | `{projectRoot}/../shared/db.sqlite` (spec edge case) |

Invariant: **the resolved path is a pure function of (configured value, projectRoot) — never of process CWD.** HTTP (projectRoot = `dirname(public/)`) and CLI (projectRoot = validated `getcwd()`) therefore agree whenever they run against the same project (SC-004).

### Docroot warning predicate (FR-008)

```
warn ⇔ resolved !== ':memory:'
     ∧ normalize(resolved) is lexically contained in normalize(projectRoot . '/public')
```

`normalize` = separator unification + `.`/`..` segment resolution (lexical; no `realpath()` — the file may not exist yet). Emitted once at boot via the bootstrapper's optional `LoggerInterface` at `warning` level, naming the resolved path and the docroot. Boot always proceeds.

## Configuration surface

| Name | Kind | Default | Effect |
|---|---|---|---|
| `discoverable` | `EntityType` ctor param | `true` | The mission's single new vocabulary item (C-004). Everything else is behavioral hardening with no new knobs: no discovery-mode setting, no debug-404 toggle, no path-resolution option. |

## State transitions

None. All four changes are pure request/boot-time decision hardening: no persisted state is created, migrated, or transitioned.
