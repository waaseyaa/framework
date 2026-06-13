# Contract: Discovery Filtering & Denied-as-404

**Mission**: request-surface-hardening-01KTX7F2 | **Requirements**: FR-001..FR-004, NFR-001, NFR-002, C-001, C-004

Applies to the `GET /api` discovery index (`ApiDiscoveryController` via `DiscoveryRouter`) and the JSON:API single-read path (`JsonApiController::show()` via `JsonApiRouter`).

## Discovery index (FR-001/FR-002, NFR-001)

1. **Envelope is caller-independent**: every response carries `meta {api: 'waaseyaa', version: '1.0'}` and `links.self` = the base path. The route remains `allowAll()` (`_public`) — the endpoint answers all callers; only the per-type links vary.
2. **Authenticated-only default (research D1)**: per-type links are emitted only when the requesting account satisfies `isAuthenticated() === true`. An anonymous account (`AnonymousUser`, id 0), a null account, or a controller constructed without an account yields **zero** entity-type links — no type id appears anywhere in the response body.
3. **Account source**: `DiscoveryRouter::handle()` passes `WaaseyaaContext::fromRequest($request)->account` (the `_account` attribute set by `SessionMiddleware`) into the controller construction. No other account source is consulted.
4. **Discoverable flag (FR-002)**: a definition whose duck-typed `isDiscoverable()` returns `false` is absent from the index for **every** caller, including authenticated and admin accounts. Definitions not exposing the method (interface stubs) are treated as discoverable.
5. **Flag is visibility, not authorization**: CRUD routes for non-discoverable types keep registering and keep enforcing entity access unchanged. Marking a type non-discoverable removes it from enumeration only.
6. **Default unchanged**: `discoverable` defaults to `true` on both the `EntityType` constructor and `fromClass()`; no existing registration changes behavior without an explicit opt-out (C-004 — the flag is the mission's only new vocabulary).
7. **Cost bound (NFR-001)**: the filter performs one `isAuthenticated()` call per request and at most one accessor read per registered type. No access-policy invocation, no row loading, no queries.
8. **Admin/authorized edge case**: any authenticated account sees every discoverable type — gated types included. (Per-account categorical granularity is not implementable with the current access API; see research D1 falsification + follow-up note.)

## Denied single read returns the not-found shape (FR-003, NFR-002, C-001)

9. **One construction site**: `show()` produces its 404 through a single private factory; the missing-entity branch and the denied-`view` branch both return that factory's output for the same `(entityTypeId, id)` pair.
10. **Byte-identical bodies**: for the same probe id, the denied response body equals the missing response body byte-for-byte: status `'404'`, title `'Not Found'`, detail `"Entity of type '<type>' with ID '<id>' not found."`, **no `code` member**, no source, identical member order. Status codes are equal (404). Headers are identical by construction (both documents exit through the single `jsonApiResponse()` emitter in `JsonApiRouter`).
11. **The access check still runs**: the denied branch evaluates `EntityAccessHandler::check($entity, 'view', $account)` exactly as before — the entity is never serialized, the result's reason is never surfaced. Allowed entities serialize unchanged.
12. **Uniform in all environments**: there is no debug/development variant of the denied response (research D3 — scenario 2's "may" deliberately not exercised). The NFR-002 pin holds unconditionally.
13. **Consumer-visible break (C-001)**: clients keying on `403` for denied single reads must adapt; documented prominently under CHANGELOG `[Unreleased]`.

## Genuine authorization errors are preserved (FR-004)

14. `store` (create denied), `update` (update denied), `destroy` (delete denied), and the field-edit checks inside store/update keep returning `JsonApiError::forbidden()` (status 403, `code: FORBIDDEN`) unchanged.
15. **Residual, accepted**: a mutation against a view-denied-but-existing entity still 403s, signalling existence to *authenticated* callers only (all mutation routes carry `requireAuthentication()`). FR-003's scope is the single read; widening 404-ing to mutations is explicitly not done ("no blanket 404-ing of the API").
16. Collection listing behavior (empty `data[]` for filtered rows) is untouched (#1605 out of scope).
17. **Unknown entity type** on any operation keeps its existing 404 (`"Unknown entity type: <type>."`) — a different, pre-existing shape that does not participate in clause 10's byte-pin (it reveals only that a *type* is unregistered, which the discovery surface governs).

## Verification

- Unit: `EntityTypeDiscoverableTest` (default true, explicit false, `fromClass()` passthrough); `ApiDiscoveryControllerTest` matrix — anonymous→no type links, authenticated→all discoverable types, `discoverable: false` absent for both, envelope constant; `DiscoveryRouterTest` — `$ctx->account` reaches the controller.
- Unit pin (NFR-002): `JsonApiControllerDeniedNotFoundTest` — same controller, a denying policy vs a nonexistent id: `json_encode` byte-equality of `toArray()` outputs + equal `statusCode`; plus a guard that the denied branch never serializes the entity.
- Updated deliberately: `JsonApiControllerAccessControlTest` denied-show assertions move `'403'` → `'404'`/not-found shape (they encode the #1649 oracle); mutation-denial assertions stay `'403'` untouched.
- Integration: `tests/Integration/Phase7/ApiDiscoveryIntegrationTest.php` — route shape assertions unchanged (`_public`, path, methods); listing assertions become account-aware (anonymous: zero type links; authenticated: previous full-listing assertions).
