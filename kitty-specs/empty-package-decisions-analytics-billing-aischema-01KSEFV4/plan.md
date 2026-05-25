# Implementation Plan: Empty Package Decisions (analytics / billing / ai-schema)

**Mission:** `empty-package-decisions-analytics-billing-aischema-01KSEFV4`
**Spec:** [./spec.md](./spec.md)

Three parallel WPs, no inter-dependencies. Each yields its own PR cross-referencing the others and `ocap-audit-log-substrate-01KSEFTF`.

## WP01 — `analytics` → `audit` rename

**Owns:** the `packages/analytics/` → `packages/audit/` move; every `Waaseyaa\Analytics\` → `Waaseyaa\Audit\` namespace edit; `.github/workflows/split.yml`; `CLAUDE.md` L0 row; `docs/adr/0NN-analytics-renamed-to-audit.md`; any `packages/*/composer.json` and root `composer.json` that requires `waaseyaa/analytics`; `occurrence_map.yaml` at the mission root.

### Bulk-edit gate

Invoke `spec-kitty-bulk-edit-classification`. Produce `occurrence_map.yaml` covering:
- `packages/analytics/src/UmamiClient.php` — `change_mode: namespace_rename` (or, under shim strategy, `extract_to_package`).
- Any other `packages/*/src/**/*.php` consuming `Waaseyaa\Analytics\`.
- Twig template `packages/analytics/templates/_umami_script.html.twig` — `change_mode: path_relocate` (moves with the package directory; no edit needed if path-anchored references stay relative).
- JS helper `packages/analytics/assets/umami.js` — same as above.

### Carving strategy (FR-005) — decide before any edits

**Strategy (i) — Embed in audit:** `UmamiClient` becomes `Waaseyaa\Audit\Umami\UmamiClient`. Twig partial moves to `packages/audit/templates/_umami_script.html.twig`. Single-package result.

**Strategy (ii) — Shim split:** create new `packages/analytics-umami/` containing only the Umami client + Twig + JS, namespace `Waaseyaa\Analytics\Umami\` (kept). Add the new package to split.yml. The `audit` package then has zero Umami code. Two-package result.

Record the chosen strategy at the top of the WP01 activity log AND in the new ADR's Decision section.

### Rename execution

1. `git mv packages/analytics packages/audit` (preserves history per FR-001).
2. Edit `packages/audit/composer.json`:
   - `name`: `waaseyaa/analytics` → `waaseyaa/audit`.
   - `description`: per FR-003 verbatim — `"Audit substrate for OCAP-aligned governance — read/write/export/access-denied event recording, retention policy hooks, query API. Includes legacy Umami pageview proxy."`. Under shim strategy: drop the "Includes legacy Umami pageview proxy." trailing sentence.
   - `autoload`: `Waaseyaa\\Analytics\\` → `Waaseyaa\\Audit\\`.
   - `autoload-dev`: same rename for the `Tests\\` PSR-4 entry.
3. Rename `packages/audit/src/UmamiClient.php` → `packages/audit/src/Umami/UmamiClient.php` (under strategy i) and edit its `namespace` declaration to `Waaseyaa\Audit\Umami`. Under strategy ii, the file stays in the new shim package with namespace `Waaseyaa\Analytics\Umami`.
4. Bulk-edit every consumer:
   ```bash
   grep -rln 'Waaseyaa\\Analytics' packages/ tests/ --include='*.php'
   ```
   Apply namespace rename per the occurrence map.
5. Edit every `packages/*/composer.json` that requires `waaseyaa/analytics`:
   ```bash
   grep -l '"waaseyaa/analytics"' packages/*/composer.json
   ```
   Replace constraint with `waaseyaa/audit` (or, for Umami consumers under strategy ii, `waaseyaa/analytics-umami`).
6. Edit `.github/workflows/split.yml`:
   - Strategy i: change `packages/analytics` / `analytics` entry to `packages/audit` / `audit`.
   - Strategy ii: change to `packages/audit` / `audit` AND add a new entry for `packages/analytics-umami` / `analytics-umami`.
7. Edit `CLAUDE.md` L0 table cell — replace `analytics` with `audit` (strategy i) or `audit, analytics-umami` (strategy ii).
8. Edit `CLAUDE.md` orchestration table row for `packages/analytics/*` — change pattern to `packages/audit/*` and update cold-memory column to reference `packages/audit/README.md` if present.
9. Edit root `composer.json` if a path-repository entry for `./packages/analytics/` exists; replace with `./packages/audit/`.

### New ADR

Write `docs/adr/0NN-analytics-renamed-to-audit.md` (NN = highest existing + 1). Required sections:

```markdown
# ADR-0NN — analytics package renamed to audit

**Status:** Accepted
**Date:** 2026-05-<DD>
**Mission:** empty-package-decisions-analytics-billing-aischema-01KSEFV4 (WP01)
**Consumer mission:** ocap-audit-log-substrate-01KSEFTF

## Context

`packages/analytics/` (1 src file, Umami pageview proxy) carried a generic
"analytics" name that risked overloading future work in confusing directions.
The OCAP audit-log substrate (`ocap-audit-log-substrate-01KSEFTF`) needs a
clean L0 home for read / write / export / access-denied event recording, with
retention-policy hooks and a query API. Naming that substrate "analytics"
would conflate per-user behavioural analytics (Umami) with structural
governance auditing (OCAP).

## Decision

Rename `packages/analytics/` to `packages/audit/`. PSR-4 prefix
`Waaseyaa\Analytics\` becomes `Waaseyaa\Audit\`. Composer package
`waaseyaa/analytics` becomes `waaseyaa/audit`.

Umami carving strategy taken: **<strategy i — embedded | strategy ii — shim split>**.
- Strategy i: `UmamiClient` moves to `Waaseyaa\Audit\Umami\UmamiClient` inside
  the audit package.
- Strategy ii: a new `waaseyaa/analytics-umami` package holds the Umami client
  + Twig partial + JS helper; the `audit` package contains no Umami code.

No `class_alias` shim is added (DIR-003 — Greenfield Removal Policy during
alpha; consumers update `use` statements in lockstep).

## Consequences

- Downstream consumers (Minoo, Claudriel) MUST update `use Waaseyaa\Analytics\…`
  to `use Waaseyaa\Audit\…` (or Umami-shim path under strategy ii).
- `waaseyaa/analytics` Packagist namespace becomes orphaned. <Archive plan
  recorded here.>
- The OCAP audit-log substrate mission populates the renamed package with
  entity types, schema handlers, and query API.

## References

- `ocap-audit-log-substrate-01KSEFTF` (consumer mission).
- DIR-003 Greenfield Removal Policy (governing directive for no-shim policy).
- `docs/adr/007-database-legacy-package-naming.md` (similar
  directory/namespace asymmetry handled differently, for contrast).
```

### WP01 verification

```bash
test -d packages/audit && test ! -d packages/analytics
grep -r "Waaseyaa\\\\Analytics" packages/ --include='*.php' | grep -v 'packages/analytics-umami/'   # empty (strategy i) or empty (strategy ii — analytics-umami uses Waaseyaa\Analytics\Umami)
grep -l '"waaseyaa/analytics"' packages/*/composer.json   # empty
composer check-composer-policy
bin/check-package-layers
bin/check-getquery-bindings
./vendor/bin/phpunit
```

All exit 0 / all green.

### PR

Title: `chore(analytics→audit): rename package to host OCAP audit substrate (DIR-003 no-shim rename)`
Body cites: ADR-0NN, `ocap-audit-log-substrate-01KSEFTF`, DIR-003, the Umami carving strategy taken, downstream consumer migration note (Minoo / Claudriel).

## WP02 — `billing` scaffold marking

**Owns:** every `packages/billing/src/*.php` (adds `@api` PHPDoc where missing); `packages/billing/README.md`; `CLAUDE.md` orchestration row for `packages/billing/*` (verification only — the row already points at `packages/billing/README.md`).

### Verify `@api` coverage

For each file in `packages/billing/src/`:
- `BillingManager.php` — `@api` already present per inventory.
- `StripeClientInterface.php` — verify.
- `FakeStripeClient.php` — verify.
- `PlanTier.php` — verify.
- `WebhookHandler.php` — verify.
- `CheckoutSession.php` — verify.
- `SubscriptionData.php` — verify.

For any file missing the class-level `@api`, add a `/** @api */` block immediately above the `class`/`interface`/`final class` keyword. Do NOT edit method bodies.

### Author README

Replace `packages/billing/README.md`. Sections (in order):

```markdown
# waaseyaa/billing

**Layer 3 — Services**
**Status:** Post-v0.1 scaffolding. Not load-bearing for v0.1 beta.

## Purpose

Subscription billing scaffold for Waaseyaa-based distributions. Wraps Stripe
Checkout, Customer Portal, and Webhook handling behind a small abstraction
so distributions can wire revenue management once a product surface needs it.
The framework does not depend on this package; consumers opt in.

## Public API surface

(Every class marked `@api` for the dead-code gate.)

| Class | Purpose |
|-------|---------|
| `BillingManager` | Top-level façade: create checkout sessions, resolve portal URLs, derive user plan tier from override + active subscriptions. |
| `StripeClientInterface` | Abstraction over the Stripe SDK calls the manager makes. |
| `FakeStripeClient` | Test double implementing `StripeClientInterface`. |
| `PlanTier` | Tier enum / value object (`free`, `pro`, `business`, `growth`, `enterprise`). |
| `SubscriptionData` | Read-side DTO mirroring Stripe subscription state. |
| `CheckoutSession` | DTO for the redirect URL Stripe returns from `createCheckoutSession`. |
| `WebhookHandler` | Verifies Stripe signature, dispatches lifecycle events to listeners. |

## Stripe integration shape

`BillingManager` is constructed with a `StripeClientInterface` plus a
price-ID → tier map plus success / cancel / portal-return URLs plus a
founding-member cap (default 100). Implementations of `StripeClientInterface`
wrap the Stripe SDK; the test double (`FakeStripeClient`) is provided.

## Out of scope for v0.1

The v0.1 beta does NOT enable billing. No Stripe credentials are required to
boot the framework. The package ships compiled but unwired: distributions that
need revenue management bind a real `StripeClientInterface` implementation,
populate the price map, and register a webhook route in their own routing
layer.

Post-v0.1 work for this package is its own mission scope — see the
alpha-to-beta plan for sequencing.

## Tests

`packages/billing/tests/Unit/` covers `BillingManagerTest`, `WebhookHandlerTest`,
`SubscriptionDataTest`, `PlanTierTest`, `CheckoutSessionTest` against
`FakeStripeClient`.
```

Length must land between 80 and 200 lines (FR-011). The template above is ~70 lines as written; the implementer expands the "Public API surface" rows and "Out of scope" paragraph as needed to hit the floor.

### CLAUDE.md orchestration row

Verify the existing row:

```
| `packages/billing/*` | — | `packages/billing/README.md` |
```

Already points at the README; no edit needed unless the row's cold-memory cell currently shows `—` (in which case update to `packages/billing/README.md` to satisfy FR-012).

### WP02 verification

```bash
grep -L "@api" packages/billing/src/*.php   # no matches
wc -l packages/billing/README.md   # between 80 and 200
grep -c "Out of scope for v0.1" packages/billing/README.md   # >= 1
composer check-composer-policy
bin/check-package-layers
./vendor/bin/phpunit packages/billing/tests/
```

### PR

Title: `docs(billing): mark as v0.1 scaffold; @api coverage + scope README`
Body cites: this mission slug, the inventory's "sketch" grade, the alpha-to-beta plan's deferral of billing work.

## WP03 — `ai-schema` activation

**Owns:** `docs/specs/ai-schema.md` (new); `packages/ai-schema/README.md`; `CLAUDE.md` orchestration table row for `packages/ai-*/*`.

### Author `docs/specs/ai-schema.md`

Required sections (the implementer fills in the prose; this is the contract for what MUST appear):

```markdown
# AI Schema (`waaseyaa/ai-schema`)

**Layer 5 — AI**
**Status:** Alpha. Capability-registry contract sketched; implementation deferred to a future mission.

## Purpose

`waaseyaa/ai-schema` is the registry for AI-facing structured-data
contracts. Two responsibilities:

1. **Entity → JSON Schema generation** (shipped today). Derives JSON Schema
   draft-2020-12 documents from `EntityType` definitions so AI agents can
   read entity shapes without inspecting PHP.
2. **AI capability registry** (contract sketched; implementation forthcoming).
   Declares the input / output schemas a given AI capability accepts and
   produces, so MCP, ai-pipeline, and ai-agent can validate AI-tool calls
   against a canonical contract.

## Layer + position

`ai-schema` is in Layer 5 (AI). It depends downward on `entity` for entity
type definitions. It is depended on upward by `ai-pipeline`, `ai-agent`,
`ai-tools`, and `mcp` for capability declarations.

## Current surface

### `EntityJsonSchemaGenerator`

(Document the shipped class — constructor signature, `generate(string $entityTypeId): array`, behaviour on missing keys, mapping of entity keys to JSON Schema properties.)

## Future capability-registry contract (sketched)

The capability registry will surface three concerns. The exact interface
signatures, method names, and registration mechanism are left to the
implementing mission; this section commits to the *categories*.

### Input-schema declaration

(Prose describing what an AI capability MUST publish about its expected input.)

### Output-schema validation

(Prose describing the validator hook that runs an AI capability's output
against its declared output schema.)

### Capability declaration

(Prose describing how an AI capability registers itself — attribute,
interface, service-tag, or extra.waaseyaa.* manifest entry. Decision deferred
to implementing mission.)

## Cross-references

- `packages/ai-pipeline/` — consumes capability declarations to route AI work.
- `packages/ai-agent/` — agent loop consults the registry per turn.
- `packages/ai-tools/` — individual tools register their capabilities.
- `packages/mcp/` — MCP endpoint serialises capability declarations to clients.
- `docs/specs/ai-integration.md` — higher-level AI integration spec.

## Gotchas

(Reserve a section for the implementing mission to populate.)
```

Length: 120–250 lines.

### Update `packages/ai-schema/README.md`

Replace contents (or append the spec link line if existing content is preserved). One-line additive reference:

```markdown
See [docs/specs/ai-schema.md](../../docs/specs/ai-schema.md) for the package
contract, including the capability-registry sketch.
```

### CLAUDE.md orchestration table row

Locate:

```
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md`, `docs/specs/authoring-assist-contract.md`, `docs/specs/semantic-refresh-trigger-contract.md` |
```

Append `docs/specs/ai-schema.md` to the cold-memory column:

```
| `packages/ai-*/*` | `waaseyaa:ai-integration` | `docs/specs/ai-integration.md`, `docs/specs/ai-schema.md`, `docs/specs/authoring-assist-contract.md`, `docs/specs/semantic-refresh-trigger-contract.md` |
```

### WP03 verification

```bash
test -f docs/specs/ai-schema.md
wc -l docs/specs/ai-schema.md   # 120–250
grep "ai-schema.md" CLAUDE.md | wc -l   # >= 1
grep "EntityJsonSchemaGenerator" docs/specs/ai-schema.md | wc -l   # >= 1
grep "docs/specs/ai-schema.md" packages/ai-schema/README.md   # >= 1
```

### PR

Title: `docs(ai-schema): activate package with spec stub + capability-registry contract sketch`
Body cites: this mission slug, the alpha-to-beta plan AI capability work, the cross-referenced AI specs.

## Verification gate (each WP, in lane worktree)

- Per-WP commands above all exit 0 / green.
- `git status` shows only that WP's owned files modified.
- `composer check-composer-policy && bin/check-package-layers && bin/check-getquery-bindings` exits 0.

## Reviewer focus

- **WP01:** Umami carving strategy is recorded in BOTH the activity log AND the ADR. `Waaseyaa\Analytics\` namespace is fully retired (strategy i) or relocated to `Waaseyaa\Analytics\Umami\` in the shim (strategy ii). No `class_alias` shim anywhere.
- **WP02:** `@api` markers are on **classes** not methods. README's "Out of scope for v0.1" statement is unambiguous — no hedging.
- **WP03:** Spec stub commits to the *categories* of the capability-registry contract, not the exact PHP. The "Gotchas" section is reserved for the implementing mission.
- **All WPs:** PRs cross-reference each other and the OCAP audit substrate mission.
