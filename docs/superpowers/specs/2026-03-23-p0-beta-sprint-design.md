# P0 Beta Sprint — Single-Session Blitz

**Date:** 2026-03-23
**Goal:** Close as many of the 28 P0 milestone issues as possible in one session using parallel agents in worktrees.

## Context

Waaseyaa has 5 P0 milestones blocking beta release, totalling 28 open issues across Security Hardening, Field Type Completion, Layer Model Enforcement, Logging & Telemetry, and HTTP Middleware Completion. All 4,889 existing tests pass. This sprint tackles them all in parallel.

## Strategy

- **5 branches**, one per milestone, each worked by an isolated agent in a git worktree
- **Merge in dependency order** to minimize conflicts
- **One PR per milestone**, referencing all issues closed by that branch
- **Cut line** if time compresses: #566 (gzip), #567 (ETag) first, then #555 (ComputedField)

## Branch 1: `p0/layer-enforcement` (Milestone #36 — 3 issues)

Merge order: **1st** (other branches may benefit from clean architecture)

| Issue | Priority | Summary |
|---|---|---|
| #556 | P0-critical | Break access↔routing circular dependency — extract shared contract to foundation or introduce interface package |
| #557 | P1-high | Remove relationship→workflows upward layer import — replace with event-based decoupling |
| #558 | P1-high | Classify 6 orphan packages (auth, billing, deployer, github, inertia, ingestion) — assign layer numbers |

**Key files:** `packages/access/`, `packages/routing/`, `packages/relationship/`, orphan package `composer.json` files.

**Acceptance:** No circular deps between layers. `tools/drift-detector.sh` clean. Tests pass.

## Branch 2: `p0/logging` (Milestone #37 — 3 issues)

Merge order: **2nd** (provides logger that middleware and security branches could reference)

| Issue | Priority | Summary |
|---|---|---|
| #559 | P0-critical | Design PSR-3 compatible logging interface in `packages/foundation` — **framework logger contract, not Monolog-lite** |
| #560 | P0-critical | Replace all `error_log()` calls throughout codebase with the new logger |
| #561 | P2-medium | Add external log sink support — file rotation at minimum, Sentry hook point |

**Design constraint:** The interface is a framework contract. Apps bring their own implementation (Monolog, custom, etc.). Waaseyaa provides a default `ErrorLogHandler` for backward compat and a `NullLogger` for tests.

**Key files:** `packages/foundation/src/Log/` (new), every file currently calling `error_log()`.

**Acceptance:** Zero `error_log()` calls remain (except inside the default handler). LoggerInterface follows PSR-3 method signatures. Tests pass. **Post-merge:** Update CLAUDE.md to replace the "No psr/log" gotcha with the new logging guidance.

## Branch 3: `p0/security-hardening` (Milestone #34 — 7 issues)

Merge order: **3rd**

| Issue | Priority | Summary |
|---|---|---|
| #542 | P0-critical | Fix XSS in AuthorizationMiddleware HTML error page — escape all dynamic output |
| #543 | P0-critical | Add session cookie security flags: HttpOnly, Secure, SameSite=Lax |
| #544 | P0-critical | Validate redirect target in login flow — allowlist of hosts |
| #545 | P1-high | Regenerate session ID on login to prevent session fixation |
| #547 | P2-medium | Add `Cache-Control: no-store` header on 401/403 responses |
| #599 | P1-high | Add `JSON_THROW_ON_ERROR` to all `json_decode()` calls for symmetry |
| #609 | P1-high | Implement `User::hasPermission()` with role-based defaults |

**Key files:** `packages/access/src/Middleware/`, `packages/user/src/`, session handling code, all files with `json_decode()`.

**Acceptance:** No unescaped HTML output. Session cookies have all three flags. Redirect targets validated. JSON decode is symmetric. Tests pass.

## Branch 4: `p0/http-middleware` (Milestone #38 — 6 issues)

Merge order: **4th** (may use logger from branch 2)

| Issue | Priority | Summary |
|---|---|---|
| #562 | P0-critical | Security headers middleware — CSP, HSTS, X-Frame-Options, X-Content-Type-Options |
| #563 | P0-critical | Rate limiting middleware — configurable, token bucket or fixed window |
| #564 | P1-high | Request logging / audit trail middleware |
| #565 | P1-high | Request body size limit middleware |
| #566 | P2-medium | Response compression middleware (gzip) — **slideable** |
| #567 | P2-medium | ETag / conditional request middleware — **slideable** |

**Key files:** `packages/foundation/src/Middleware/` (new middleware classes), middleware registration.

**Acceptance:** All middleware follows `HttpMiddlewareInterface` with `#[AsMiddleware(priority: N)]`. Configurable via `config/waaseyaa.php`. Tests pass.

## Branch 5: `p0/field-types` (Milestone #35 — 9 issues)

Merge order: **5th** (independent — could merge in any position, placed last to prioritize structural/security branches)

| Issue | Priority | Summary |
|---|---|---|
| #548 | P0-critical | DateTimeItem field type |
| #549 | P0-critical | DateItem field type |
| #550 | P0-critical | FileItem + ImageItem field types |
| #551 | P0-critical | LinkItem field type |
| #552 | P1-high | EmailItem field type |
| #553 | P1-high | DecimalItem field type |
| #554 | P1-high | ListItem / SelectItem field type |
| #555 | P2-medium | ComputedField support — **slideable** |
| #608 | P2-medium | Add `@method` PHPDoc annotations to EntityInterface |

**Pattern:** Each field type extends `FieldItemBase`, implements validation, declares schema column type, includes unit tests. Follow existing field types (StringItem, IntegerItem, BooleanItem, TextItem, EntityReferenceItem, FloatItem) as templates.

**Key files:** `packages/field/src/Item/`, `packages/field/tests/Unit/Item/`.

**Acceptance:** All new field types registered, validated, schema-aware, and tested. EntityInterface has IDE-friendly PHPDoc.

## Conflict Zones

| Area | Touched by | Risk | Mitigation |
|---|---|---|---|
| `packages/foundation/` | Logging + Middleware | Medium — both add new files and may touch ServiceProvider/composer.json autoload | Review ServiceProvider registration and autoload entries for overlap |
| `public/index.php` | Security (maybe) | Low | Only Security agent touches it if needed |
| `packages/access/` | Layer Enforcement + Security | Medium | Layer branch merges first, Security rebases |

## Merge Protocol

1. All agents complete and tests pass in their worktrees
2. Merge `p0/layer-enforcement` → main, run full test suite
3. Merge `p0/logging` → main (rebase if needed), run tests
4. Merge `p0/security-hardening` → main (rebase if needed), run tests
5. Merge `p0/http-middleware` → main (rebase if needed), run tests
6. Merge `p0/field-types` → main (rebase if needed), run tests
7. Create 5 PRs, each referencing its milestone issues
8. Close resolved issues

## Success Criteria

- All P0-critical issues (12) closed
- All P1-high issues (9) closed
- Unlabeled issues (2: #608, #609) closed — treated as P1-high and P2-medium respectively
- P2-medium issues (5) closed unless cut line triggered
- 4,889+ tests passing (net increase expected from new field type and middleware tests)
- No regressions in existing functionality
