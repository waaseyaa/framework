# Waaseyaa v1 Architecture Audit

**Date:** 2026-03-20
**Goal:** Identify blockers preventing Waaseyaa from replacing Drupal and Laravel at production scale.
**Scope:** All 38 packages, admin SPA, tests, security, and feature completeness.

---

## Executive Summary

Waaseyaa has strong foundations: a well-designed entity system, proper JSON:API 1.1 compliance, multi-layer access control, and modern PHP 8.4 practices (readonly properties, enums, match expressions, intersection types). The 7-layer architecture is sound in concept.

However, **v1 is not production-ready** as a Drupal/Laravel replacement. The audit found **2 critical architectural violations**, **6 security issues**, **14 god classes**, **missing field types** that block real-world content modeling, and **no structured logging framework**. The sections below are ordered by severity.

---

## 1. CRITICAL — Architecture Violations

### 1.1 Circular Dependency: `access` ↔ `routing`

- `packages/access/composer.json` requires `waaseyaa/routing` (Layer 1 → Layer 4)
- `packages/routing/composer.json` requires `waaseyaa/access` (Layer 4 → Layer 1)
- **Impact:** Prevents independent package installation, blocks Composer split, violates layer model.
- **Fix:** Extract shared contracts (e.g., `AccessCheckerInterface`, route option constants) into Foundation (Layer 0), or merge into a single package.

### 1.2 Upward Layer Import: `relationship` → `workflows`

- `packages/relationship/composer.json` requires `waaseyaa/workflows` (Layer 2 → Layer 3)
- **Fix:** Invert via domain events — Relationship dispatches events, Workflows subscribes.

### 1.3 Six Unclassified Packages

`auth`, `billing`, `deployer`, `github`, `inertia`, `ingestion` have no layer assignment in CLAUDE.md. This means no layer enforcement is possible for code touching them.

---

## 2. CRITICAL — Security Vulnerabilities

| # | Issue | Location | Severity |
|---|-------|----------|----------|
| S1 | **XSS in HTML error page** — `$detail` embedded without escaping | `packages/access/src/Middleware/AuthorizationMiddleware.php:78-95` | HIGH |
| S2 | **Session cookies missing security flags** — no `HttpOnly`, `Secure`, `SameSite` | `packages/user/src/Middleware/SessionMiddleware.php` | HIGH |
| S3 | **Open redirect** — `/login?redirect=` uses user-controlled path | `packages/access/src/Middleware/AuthorizationMiddleware.php:72` | MEDIUM |
| S4 | **No session regeneration on login** — session fixation risk | `SessionMiddleware` | MEDIUM |
| S5 | **Dev fallback account has no production guard** — relies on operator discipline | `.env.example` / `DevAdminAccount` | MEDIUM |
| S6 | **Missing `Cache-Control: no-store`** on 401/403 responses | `HttpKernel` | LOW |

**What's good:** Parameterized queries everywhere (no SQL injection), CSRF with `hash_equals()`, JWT with proper `exp`/`nbf` validation, multi-layer authorization, secrets CI gate.

---

## 3. HIGH — God Classes & SRP Violations

| File | Lines | Responsibility Count | Recommendation |
|------|-------|---------------------|----------------|
| `foundation/src/Http/ControllerDispatcher.php` | 986 | 8 domains (JSON:API, SSR, media, discovery, OpenAPI, GraphQL, MCP, app) | Split into domain-specific routers |
| `ssr/src/SsrPageHandler.php` | 688 | 5 (rendering, controllers, language, cache, headers) | Extract `LanguageResolver` |
| `relationship/src/RelationshipDiscoveryService.php` | 579 | Query + normalization + validation | Extract `ParameterValidator` |
| `relationship/src/RelationshipTraversalService.php` | 539 | Traversal + caching + pagination | Split traversal from caching |
| `api/src/JsonApiController.php` | 478 | CRUD + filtering + includes + serialization | Extract `QueryParser` usage |
| `foundation/src/Kernel/AbstractKernel.php` | 467 | 22 methods: DB, manifest, entities, migrations, providers, access, knowledge | Extract phase-specific bootstrappers |
| `entity-storage/src/SqlEntityStorage.php` | 382 | Storage + hydration + reflection + events | Extract `EntityHydrator` |

**Test files also oversized:** `McpControllerTest.php` (1157), `HttpKernelTest.php` (902), `JsonApiControllerTest.php` (721) — should follow existing PhaseN split pattern.

---

## 4. HIGH — Missing Field Types

Only **6 field types** exist. Real-world CMS content modeling requires at minimum:

| Exists | Missing (blocks v1 adoption) |
|--------|------------------------------|
| StringItem | **DateTimeItem** |
| TextItem | **DateItem** |
| IntegerItem | **FileItem / ImageItem** |
| FloatItem | **LinkItem** |
| BooleanItem | **EmailItem** |
| EntityReferenceItem | **DecimalItem** |
| | **ListItem / SelectItem** |
| | **ComputedField support** |

Without DateTime and File/Image fields, Waaseyaa cannot model a basic blog post, let alone replace Drupal or Laravel for content management.

---

## 5. HIGH — Missing Middleware

| Middleware | Status | Impact |
|-----------|--------|--------|
| **Security headers** (CSP, HSTS, X-Frame-Options, X-Content-Type-Options) | Missing | OWASP non-compliance |
| **Rate limiting** (HTTP layer) | Missing | DoS vulnerability |
| **Request logging / audit trail** | Missing | No observability |
| **Request body size limit** | Missing | Memory exhaustion risk |
| **Response compression** (gzip) | Missing | Performance on large payloads |
| **ETag / conditional requests** | Missing | Bandwidth waste |

**Present and well-implemented:** Session, Bearer/JWT, CSRF, Authorization, API Cache, Tenant isolation, CORS.

---

## 6. HIGH — No Structured Logging

- Project explicitly avoids `psr/log` and uses `error_log()` throughout.
- No log levels, no structured context, no log routing, no external integration (Sentry, Datadog, etc.).
- **For a framework claiming to replace Drupal/Laravel, this is a v1 blocker.** Both provide robust logging out of the box.
- The `v2.1 — Logging & Telemetry` milestone exists but has 0 issues.

---

## 7. MEDIUM — Entity System Gaps

### 7.1 No Automatic Validation on Save

`EntityValidator` exists but is opt-in. Neither `EntityRepository::save()` nor entity classes have `preSave()` validation hooks. Developers must remember to call validation manually — this is the #1 data corruption vector in Drupal contrib.

### 7.2 Revision System: Interfaces Only

`RevisionableInterface` and `RevisionableStorageInterface` define contracts, but no implementation exists. No revision tables, no revision save logic. Revisions are table-stakes for CMS.

### 7.3 No Batch Operations

No `saveMany()`, `deleteMany()`, or bulk SQL optimization. Every entity save is a separate query. Importing 10,000 articles means 10,000 INSERT statements.

### 7.4 No Entity Lifecycle Hooks

No `preSave()`, `postSave()`, `preDelete()` methods on entity classes. Events are the only mechanism. Drupal/Laravel both provide entity-level hooks that are easier to discover than event subscribers.

---

## 8. MEDIUM — DRY Violations & Anti-Patterns

### 8.1 Magic Strings in Entity Keys

Entity audit entries use raw strings (`'entity_type_id'`, `'actor'`, `'action'`, `'timestamp'`) instead of class constants or enums. Typos cause silent failures.

### 8.2 Direct Event Instantiation

`SqlEntityStorage` calls `new EntityEvent()` in 5 places instead of using an event factory. This tightly couples storage to a specific event class.

### 8.3 Missing Polymorphism in API Layer

14 `instanceof` checks in `packages/api/src/` with only 1 interface. Controller dispatch uses type checks where strategy pattern would be appropriate.

### 8.4 JSON Encode/Decode Asymmetry

`json_encode(..., JSON_THROW_ON_ERROR)` paired with bare `json_decode()` (no throw flag) in multiple controllers — silent `null` on corrupt data.

---

## 9. MEDIUM — Test Coverage Gaps

### By the Numbers

| Area | Test Count | Concern |
|------|-----------|---------|
| Foundation | 53 | Adequate for package size |
| CLI | 73 | Well covered |
| API | 33 | Low for the primary interface |
| Entity Storage | 13 | Low for the persistence core |
| **Relationship** | **3** | **Critical — 1689 LOC with 3 tests** |
| **Ingestion** | **2** | **Critical — validation pipeline barely tested** |
| **Admin (PHP)** | **0** | Only E2E Playwright specs |
| GraphQL | 14 | Minimal full-stack coverage |
| Search | 7 | Adequate for current scope |

### Packages With Zero Tests
`cms`, `core`, `full` (metapackages — acceptable), `deployer` (has code, needs tests).

### Missing Test Categories
- No performance/load tests
- No property-based / fuzzing tests
- No mutation testing
- No contract tests between packages

---

## 10. MEDIUM — Feature Parity Gaps

### vs Drupal

| Feature | Waaseyaa | Drupal |
|---------|----------|--------|
| Entity system | ✅ Strong | ✅ Mature |
| Field types | ❌ 6 types | ✅ 20+ types |
| Revisions | ❌ Interface only | ✅ Full |
| Views (query builder UI) | ❌ None | ✅ Core |
| Form API | ❌ None | ✅ Core |
| Content moderation | ❌ None | ✅ Core |
| Multilingual | ⚠️ Basic (language negotiation) | ✅ Full (content translation) |
| Media library | ⚠️ Basic | ✅ Full |
| Layout builder | ❌ None | ✅ Core |
| Cron/scheduled tasks | ❌ None | ✅ Core |

### vs Laravel

| Feature | Waaseyaa | Laravel |
|---------|----------|---------|
| ORM/entity system | ✅ Different paradigm, solid | ✅ Eloquent |
| Logging | ❌ `error_log()` only | ✅ Monolog integration |
| Caching backends | ⚠️ Memory/file only | ✅ Redis, Memcached, DynamoDB |
| Queue backends | ⚠️ Present, scope unclear | ✅ Redis, SQS, DB, Beanstalk |
| Mail | ⚠️ Present | ✅ Multiple drivers |
| Notifications | ❌ None | ✅ Multi-channel |
| Broadcasting | ❌ None | ✅ WebSocket/Pusher |
| Task scheduling | ❌ None | ✅ Built-in scheduler |
| Rate limiting | ❌ None (HTTP) | ✅ Middleware |
| OAuth/Socialite | ❌ JWT + API key only | ✅ Full OAuth providers |
| Seeding/factories | ❌ Test fixtures only | ✅ Faker + factories |

---

## 11. LOW — Modern PHP Practices

**Strengths (already good):**
- 836 readonly properties used throughout
- 8 enums defined
- Match expressions used properly
- Named arguments in constructors
- Union/intersection types
- `declare(strict_types=1)` everywhere
- PHP 8.4 minimum

**Minor gaps:**
- First-class callable syntax not used (still using closure wrappers)
- No asymmetric visibility (PHP 8.4 feature: `public private(set)`)
- No property hooks (PHP 8.4)

---

## 12. LOW — Performance Concerns

| Issue | Location | Impact |
|-------|----------|--------|
| N+1 queries in entity references | EntityReferenceItem resolution | Scales poorly with nested includes |
| No eager loading | EntityRepository | Every relation = separate query |
| In-memory array pagination | RelationshipDiscoveryService | Memory pressure on large graphs |
| No query result caching | SqlEntityQuery | Repeated identical queries not cached |
| No dataloader pattern | GraphQL layer | N+1 for every nested field |

---

## Prioritized Roadmap Recommendations

### P0 — Must Fix Before v1 Beta

1. Break `access` ↔ `routing` circular dependency
2. Fix XSS in AuthorizationMiddleware HTML error pages
3. Add session cookie security flags (`HttpOnly`, `Secure`, `SameSite`)
4. Implement DateTime, File/Image, and Link field types
5. Add security headers middleware (CSP, HSTS, X-Frame-Options)
6. Implement structured logging (PSR-3 compatible, even if lightweight)
7. Add HTTP rate limiting middleware

### P1 — Must Fix Before v1 Stable

8. Implement revision storage (interfaces exist, need implementation)
9. Add automatic pre-save validation in EntityRepository
10. Split ControllerDispatcher (986 lines) into domain routers
11. Split AbstractKernel into phase-specific bootstrappers
12. Add batch entity operations (saveMany/deleteMany)
13. Add session regeneration on login
14. Validate redirect targets in login redirect
15. Classify 6 orphan packages into layers
16. Fix `relationship` → `workflows` upward import

### P2 — Should Fix Before v1 GA

17. Add Redis/Memcached cache backends
18. Add remaining field types (Email, Decimal, List/Select, Computed)
19. Add OAuth2/OIDC provider support
20. Add entity lifecycle hooks (preSave, postSave, preDelete)
21. Implement request body size limits
22. Add ETags and conditional request support
23. Add response compression middleware
24. Increase test coverage for Relationship (3 tests) and Ingestion (2 tests)
25. Add N+1 query detection / eager loading for entity references

### P3 — Nice to Have for v1

26. Form API or form handling abstraction
27. Content moderation / editorial workflows
28. Task scheduling (cron)
29. Notification system
30. Factory/seeder framework for development
31. Property hooks and asymmetric visibility (PHP 8.4)
32. DataLoader pattern for GraphQL

---

## Methodology

This audit was conducted by 6 parallel analysis agents examining:
1. Layer violations and dependency health (all composer.json files)
2. Entity system, storage, and field completeness
3. API layer, routing, middleware, and HTTP handling
4. DRY/SRP violations, anti-patterns, and modern PHP usage
5. Test coverage gaps and feature parity analysis
6. Security patterns against OWASP Top 10 2025

All findings reference specific file paths. Line numbers are approximate due to ongoing development.
