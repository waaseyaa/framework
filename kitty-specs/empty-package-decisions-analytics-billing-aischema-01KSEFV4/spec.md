# Empty Package Decisions — analytics, billing, ai-schema

**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue (framework cleanup). Coordinates with `ocap-audit-log-substrate-01KSEFTF` (consumer of the `analytics → audit` rename) and the v0.1 alpha-to-beta gap matrix.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape.

## Why this mission exists

The Waaseyaa inventory grades three packages as **sketch** or under-utilised:

- **`analytics`** (L0, "sketch", 1 src / 0 tests) — exists as a Umami pageview proxy + Twig partial. Subject-matter scope ("analytics") is generic enough that future work could overload it in confusing directions. The roadmap pre-resolves this: the package will be renamed to `audit` and become the home of the OCAP audit substrate (`ocap-audit-log-substrate-01KSEFTF`). This mission **performs the rename only** — the audit-log entity types and query API are owned by the OCAP substrate mission.
- **`billing`** (L2/services, "sketch", 8 src / 5 tests) — actually has more shape than its sketch grade suggests (BillingManager, StripeClientInterface, FakeStripeClient, PlanTier, WebhookHandler, CheckoutSession, SubscriptionData). It is real scaffolding for post-v0.1 revenue management, not vestigial code. Decision pre-resolved: **KEEP as scaffold; mark with `@api`**. No further work in v0.1.
- **`ai-schema`** (L5/AI, alpha, 1 src / 1 test) — single class `EntityJsonSchemaGenerator` produces JSON Schema (draft 2020-12) for entity types. Position in L5 is sound. Per the alpha-to-beta plan, AI capability work will need a **capability-registry** for AI-tool input/output schemas. Decision pre-resolved: **ACTIVATE** — author a `docs/specs/ai-schema.md` stub describing the capability-registry contract that a future mission will fill in.

Three independent decisions, three parallel WPs. No WP edits another's owned files.

## Scope

### In scope

- **WP01 — `analytics` → `audit` package rename.** Directory move, `composer.json` `name` change, PSR-4 namespace `Waaseyaa\Analytics\` → `Waaseyaa\Audit\`, split.yml entry rename, CLAUDE.md L0 table edit, ADR documenting the rename, Umami helper class renamed within the audit package OR carved into a small follow-on `waaseyaa/analytics-umami` shim (decision deferred to implementer — see Decisions deferred).
- **WP02 — `billing` package marked as scaffold.** Audit `billing/` for `@api` markers on every public class. Author `packages/billing/README.md` (replace existing if shape differs) stating the scope ("revenue management scaffolding; not v0.1-blocking"), the deferred-to-post-v0.1 status, and the existing API surface (BillingManager, StripeClientInterface, etc.). No code changes other than `@api` PHPDoc additions where missing.
- **WP03 — `ai-schema` activated.** Author `docs/specs/ai-schema.md` (does not currently exist). Stub describes: package purpose (JSON Schema for entity types + future capability-registry contract), current surface (`EntityJsonSchemaGenerator`), contract sketch for the future capability-registry (input-schema declaration, output-schema validation, capability declaration), cross-references (`ai-pipeline`, `ai-agent`, `mcp`). Update CLAUDE.md orchestration table row for `packages/ai-*/*` to add `ai-schema.md` to the cold-memory column.

### Out of scope

- Implementing the OCAP audit substrate (entity types, schema, query API, listeners) — that is `ocap-audit-log-substrate-01KSEFTF`. This mission only renames the package so the OCAP mission has a clean home.
- Implementing the AI capability registry — a future mission consumes the contract this mission stubs.
- Removing the Umami proxy — Umami pageview proxying is a real shipped feature. WP01 must preserve it (either as `Waaseyaa\Audit\Umami\UmamiClient` inside the renamed package, or as a tiny `waaseyaa/analytics-umami` shim — implementer's call).
- Building any billing functionality. WP02 is documentation-only.
- Touching `packages/ai-agent/`, `packages/ai-pipeline/`, `packages/ai-tools/`, `packages/ai-vector/`, or `packages/ai-observability/`.

## Requirements

### Functional

#### analytics → audit rename (WP01)

- **FR-001** `packages/analytics/` directory is renamed to `packages/audit/` via `git mv` (history preserved).
- **FR-002** `packages/audit/composer.json` `name` field is `waaseyaa/audit` (was `waaseyaa/analytics`).
- **FR-003** `packages/audit/composer.json` `description` reflects the new scope: leads with "Audit substrate for OCAP-aligned governance — read/write/export/access-denied event recording, retention policy hooks, query API. Includes legacy Umami pageview proxy."
- **FR-004** PSR-4 autoload prefix changes from `Waaseyaa\Analytics\` to `Waaseyaa\Audit\` in `packages/audit/composer.json`. All `Waaseyaa\Analytics\*` class FQCNs in the package are renamed accordingly.
- **FR-005** The Umami pageview proxy is preserved. Implementer chooses one of two carving strategies (see Decisions deferred): (i) move `UmamiClient` into `Waaseyaa\Audit\Umami\UmamiClient` inside the audit package, OR (ii) split into a tiny `waaseyaa/analytics-umami` shim package. The chosen strategy MUST be recorded in the WP01 activity log.
- **FR-006** `.github/workflows/split.yml` entry for `packages/analytics` is updated to `packages/audit` / `audit`.
- **FR-007** `CLAUDE.md` Layer 0 table cell — replace `analytics` with `audit`.
- **FR-008** A new ADR `docs/adr/0NN-analytics-renamed-to-audit.md` (NN = next free number) documents the rename, cites the OCAP audit-log substrate mission as the consumer, and lists the Umami carving decision taken under FR-005.
- **FR-009** Internal `waaseyaa/analytics` constraints anywhere in the repo (`packages/*/composer.json` `require`, root `composer.json` path repositories) are updated to `waaseyaa/audit`.

#### billing scaffold marking (WP02)

- **FR-010** Every public class in `packages/billing/src/` carries a class-level `@api` PHPDoc tag. WP02 verifies by reading the file list and adding `@api` to any class missing it. (Existing classes already mark `@api` on `BillingManager` per the inventory; WP02 verifies for the others.)
- **FR-011** `packages/billing/README.md` is authored (or replaced) with these sections: Purpose, Status (post-v0.1 scaffolding), Public API surface (enumerate every `@api`-marked class), Stripe integration shape, Out-of-scope-for-v0.1 statement. Length: 80–200 lines markdown.
- **FR-012** `CLAUDE.md` orchestration table row for `packages/billing/*` updates its cold-memory column to point at `packages/billing/README.md` (currently points at `packages/billing/README.md` per inventory — verify and keep authoritative).

#### ai-schema activation (WP03)

- **FR-013** `docs/specs/ai-schema.md` is authored (does not currently exist). Required sections: Purpose, Layer + position, Current surface (`EntityJsonSchemaGenerator`), Future capability-registry contract (input-schema declaration, output-schema validation, capability declaration), Cross-references to `ai-pipeline`, `ai-agent`, `mcp`, `docs/specs/ai-integration.md`. Length: 120–250 lines markdown.
- **FR-014** `CLAUDE.md` orchestration table row for `packages/ai-*/*` updates its cold-memory column to include `docs/specs/ai-schema.md` alongside the existing entries.
- **FR-015** `packages/ai-schema/README.md` is updated to point at the new spec (one-line `See [docs/specs/ai-schema.md](../../docs/specs/ai-schema.md)` reference) — README content stays brief and authoritative; the spec is the contract.

### Non-functional

- **NFR-001** `composer check-composer-policy` and `bin/check-package-layers` exit 0 after each WP merges to lane.
- **NFR-002** `bin/check-getquery-bindings` exits 0 throughout (WP01 may touch billing tests if Stripe testing surfaces depend on analytics; verify no binding regressions).
- **NFR-003** No GitHub issue filed; Spec Kitty mission state is canonical.
- **NFR-004** WP01's namespace rename is a **bulk edit** (same identifier touched in many files). The implementer MUST invoke `spec-kitty-bulk-edit-classification` and produce an `occurrence_map.yaml` for WP01.
- **NFR-005** No PHPUnit tests deleted in WP01; tests are renamed/moved alongside their source files. All suites remain green.

### Constraints

- **C-001** WP01 ↔ WP02 ↔ WP03 are independent and MUST execute in parallel (no inter-WP dependencies in `wps.yaml`).
- **C-002** This mission COORDINATES WITH `ocap-audit-log-substrate-01KSEFTF`. That mission expects `packages/audit/` (the renamed package) to exist as its home. The two missions MAY land in either order, but their PRs MUST reference each other.
- **C-003** PSR-4 namespace `Waaseyaa\Analytics\` is fully retired; no `class_alias` shim is added during alpha (DIR-003 — Greenfield Removal Policy applies to internal namespaces too).
- **C-004** `billing` keeps its current name. No rename is in scope.
- **C-005** `ai-schema` keeps its current name and namespace `Waaseyaa\AI\Schema\`. No rename in scope.

## Acceptance

### WP01 (analytics → audit)

- `test -d packages/audit && test ! -d packages/analytics` returns success.
- `grep -r "Waaseyaa\\\\Analytics" packages/ --include='*.php'` returns no matches (after FR-005 carving).
- `grep "waaseyaa/audit" .github/workflows/split.yml | wc -l` returns exactly 1.
- `grep "waaseyaa/analytics" .github/workflows/split.yml` returns no matches (unless FR-005 split chose the shim strategy, in which case `waaseyaa/analytics-umami` may appear instead).
- `grep -l '"waaseyaa/analytics"' packages/*/composer.json` returns no matches.
- New ADR exists at `docs/adr/0NN-analytics-renamed-to-audit.md` and cites `ocap-audit-log-substrate-01KSEFTF`.
- `composer check-composer-policy && bin/check-package-layers` exits 0.

### WP02 (billing)

- `grep -L "@api" packages/billing/src/*.php` returns no matches (every src file has at least one `@api`).
- `wc -l packages/billing/README.md` is between 80 and 200.
- README contains an explicit "Out of scope for v0.1" statement.

### WP03 (ai-schema)

- `test -f docs/specs/ai-schema.md` returns success.
- `wc -l docs/specs/ai-schema.md` is between 120 and 250.
- `grep "ai-schema.md" CLAUDE.md | wc -l` returns ≥ 1.
- `grep "EntityJsonSchemaGenerator" docs/specs/ai-schema.md` returns ≥ 1.
- `packages/ai-schema/README.md` contains the spec link.

### Mission acceptance

- All three WPs merged to lane; `composer check-composer-policy`, `bin/check-package-layers`, `bin/check-getquery-bindings`, and PHPUnit all green.
- One PR per WP (three PRs total) cross-referencing each other and the OCAP audit substrate mission.

## Risks

- **Umami carving misjudgement (WP01).** If implementer picks the embedded strategy and a downstream consumer (Minoo) only uses the Umami client, the rename forces a constraint update for what is logically an unchanged surface. **Mitigation:** the FR-005 decision MUST be recorded in the WP01 activity log AND the ADR; downstream consumer impact is documented in the PR.
- **Bulk-edit blast radius (WP01).** `Waaseyaa\Analytics\` is small (1 src file) but the namespace touches every consumer's `use` statement. **Mitigation:** NFR-004 mandates `occurrence_map.yaml`.
- **OCAP-mission coordination (C-002).** If the OCAP mission lands first, it would need to create `packages/analytics/` (since `audit/` doesn't exist yet) or wait. **Mitigation:** both PRs reference each other; reviewer-of-record sequences merges.
- **Billing `@api` over-marking.** Marking internal helpers with `@api` defeats the dead-code gate's purpose. **Mitigation:** WP02 marks **public** classes only — `BillingManager`, `StripeClientInterface`, `PlanTier`, `SubscriptionData`, `CheckoutSession`, `WebhookHandler`. Internal helpers (if any are added later) stay unmarked.
- **ai-schema scope creep.** The spec stub is a contract sketch, not a full design. **Mitigation:** length envelope (120–250 lines) explicit; spec explicitly defers capability-registry implementation to a future mission.

## Decisions pre-resolved

- **`analytics` → `audit` rename, full namespace change.** No `class_alias` shim during alpha (DIR-003).
- **`billing` kept as scaffold.** No removal, no rename. `@api` markers on every public class. README documents the deferral.
- **`ai-schema` activated and given a spec stub.** No code changes; the contract sketch unblocks future capability-registry work.
- **Three parallel WPs**, no inter-WP dependencies, three independent PRs cross-referencing each other and the OCAP audit substrate mission.

## Decisions deferred to implementer

- **WP01 Umami carving strategy (FR-005):** embed `UmamiClient` inside `Waaseyaa\Audit\Umami\` OR split into a `waaseyaa/analytics-umami` shim package. Either is acceptable; the chosen path MUST be recorded in the WP01 activity log and the ADR.
- **WP03 capability-registry contract shape:** the spec stub describes the contract sketch in prose; the exact interface signatures, method names, and registration mechanism are left to the future implementing mission. WP03 commits to the *categories* (input-schema declaration, output-schema validation, capability declaration), not the exact PHP.
- **New ADR number** (next free under `docs/adr/`).

## Out-of-band

- The OCAP audit-log substrate implementation lives in `ocap-audit-log-substrate-01KSEFTF`.
- The AI capability-registry implementation belongs to a future mission consuming the WP03 contract sketch.
- Any post-v0.1 billing functionality (Stripe wire-up, subscription lifecycle, founding-member cap enforcement) belongs to its own future mission.
