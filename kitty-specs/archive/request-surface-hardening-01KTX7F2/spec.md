# Feature Specification: Request-Surface Hardening

**Mission**: `request-surface-hardening-01KTX7F2`
**Created**: 2026-06-12
**Status**: Draft
**Tracking**: [#1649](https://github.com/waaseyaa/framework/issues/1649), [#1650](https://github.com/waaseyaa/framework/issues/1650), [#1652](https://github.com/waaseyaa/framework/issues/1652)
**Target release**: v0.1.0-alpha.206

## Overview

Three small, independent request-surface gaps. First, the public API leaks information: anonymous discovery enumerates every registered entity type id (a site's sensitive internal type names are public even when every row is access-denied), and asking for a denied entity answers "forbidden" instead of "not found" — an existence oracle confirming that a given id exists in a gated type. Second, bearer-token authentication on the MCP endpoint compares tokens with a non-constant-time lookup and never re-checks whether the token's account has been blocked since the token map was built. Third, a relative database path resolves against the process working directory instead of the project root, so the PHP dev server silently creates and uses a second empty database inside the docroot — logins fail, queries come back empty, nothing errors, and the CLI operates on a different database with identical configuration.

## User Scenarios & Testing

### Acceptance scenarios

1. **Given** an anonymous (or any) caller listing the API discovery index, **then** only entity types the caller could conceivably view are listed; types categorically denied to that caller (or marked non-discoverable) do not appear by name.
2. **Given** a caller requesting a single entity it is denied `view` on, **then** the response is indistinguishable from the type/id not existing (404, not-found shape) — no existence oracle. An explicit debug/development mode may surface the distinction.
3. **Given** a bearer token presented to the MCP endpoint, **then** token comparison is constant-time, and a token whose account is blocked/inactive is rejected at request time (fail closed) even if the token map still contains it.
4. **Given** a relative database path configuration, **then** it resolves against the project root regardless of the process working directory — the dev server and the CLI operate on the same file; **and** if the resolved path lands inside the public docroot, a boot-time warning is emitted.
5. **Given** existing consumers with absolute database paths or default (unset) configuration, **then** behavior is unchanged.

### Edge cases

- Discovery filtering must not slow the index into running row-level access checks per type — the check is categorical (per-type/per-account), not per-row.
- 404-for-denied must preserve the real 404 shape exactly (headers, body) so the two cases cannot be told apart by diffing.
- Listing endpoints already return empty `data[]` for access-filtered collections — unchanged (tracked separately as #1605).
- An admin/authorized caller must still see gated types in discovery and get real 403s where the distinction is legitimate (e.g. update on a viewable entity).
- Blocked-account check must not add a query per request beyond the account load the auth already performs.
- A relative `WAASEYAA_DB` that climbs out of the project root (`../shared/db.sqlite`) resolves correctly relative to project root.

## Requirements

### Functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | API discovery lists only entity types viewable-in-principle by the requesting account; categorically denied types are absent from the listing. | Proposed |
| FR-002 | Entity types can opt out of discovery entirely via their type definition (non-discoverable types never appear, for any caller). | Proposed |
| FR-003 | A single-entity read denied by `view` access returns the same not-found response as a nonexistent entity (status and body shape identical). | Proposed |
| FR-004 | Mutating operations on entities the caller can view keep returning genuine authorization errors (no blanket 404-ing of the API). | Proposed |
| FR-005 | Bearer-token comparison is constant-time over candidate tokens. | Proposed |
| FR-006 | A bearer token resolving to a blocked/inactive account is rejected at request time, fail closed. | Proposed |
| FR-007 | A relative database path resolves against the kernel project root in every runtime (HTTP, dev server, CLI, queue); unset configuration keeps the documented project-root default. | Proposed |
| FR-008 | Boot emits a warning when the resolved database path is inside the public docroot. | Proposed |

### Non-functional requirements

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | Discovery filtering adds no per-row access checks; per-request cost is bounded by the number of registered types. | Proposed |
| NFR-002 | The denied-vs-missing indistinguishability is pinned by a test asserting byte-identical response bodies and equal status codes. | Proposed |
| NFR-003 | Bearer auth changes add no additional database queries per request beyond the existing account resolution. | Proposed |

### Constraints

| ID | Constraint | Status |
|----|-----------|--------|
| C-001 | The 403→404 change on denied singles is consumer-visible and documented in the CHANGELOG under `[Unreleased]` (alpha-style; clients keying on 403 must adapt). | Accepted |
| C-002 | All charter quality gates; ships in v0.1.0-alpha.206 under the CI-gated release flow. | Accepted |
| C-003 | SecurityHeadersMiddleware (#1651) stays pinned/unwired — explicitly out of scope; wiring it requires the embed-exemption design. | Accepted |
| C-004 | No new configuration vocabulary beyond the discoverable flag on the entity type definition. | Accepted |

## Success Criteria

- SC-001: An unauthenticated discovery request on a site with a gated type does not reveal the type's id; the FNPI "bland venture type names" mitigation becomes unnecessary.
- SC-002: An unauthenticated probe of a real-but-denied entity id and a nonexistent id produce byte-identical responses (NFR-002 test green).
- SC-003: A blocked service account's still-configured token stops authenticating immediately, proven by test.
- SC-004: With `WAASEYAA_DB=./storage/waaseyaa.sqlite` and a dev-server CWD of the docroot, HTTP and CLI use the same database file; no stray database appears under the docroot.
- SC-005: Zero new quality-gate violations; CHANGELOG documents the 403→404 change and the path-resolution fix.

## Key Entities

- **Discovery index**: the public listing of entity type ids and endpoints.
- **Discoverable flag**: per-entity-type opt-out from the discovery index.
- **Not-found shape**: the canonical 404 response body reused for denied singles.
- **Project root**: the kernel's documented base directory for relative path resolution.

## Assumptions

- "Viewable-in-principle" is decidable per account+type without loading rows (access policies expose a categorical check or equivalent; if only row-level checks exist, the discoverable flag plus an authenticated-only default is the fallback — decided at plan time).
- Blocked/inactive state is readable from the already-resolved account object (no schema change).
- The alpha-style 403→404 break is pre-approved (sprint approval 2026-06-11).

## Out of Scope

- SecurityHeadersMiddleware wiring (#1651) — stays pinned.
- The empty-`data[]` collection behavior for policy-less types (#1605).
- MCP transport bugs (#1635–#1637) and OAuth (#1640).
- Rate limiting and request throttling.
