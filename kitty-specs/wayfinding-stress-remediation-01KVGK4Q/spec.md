# Feature Specification: Wayfinding Stress-Test Remediation (alpha.233 backlog)

**Mission:** `wayfinding-stress-remediation-01KVGK4Q` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-20 · **Status:** Active — P0 GREEN-LIT and shipping in alpha.234; P1/P2 held for go.

**Parent / context:** Remediation pass over the flagship `wayfinding-01KVGH5X` (5 phases shipped through alpha.233). Source backlog: the **alpha.233 Wayfinding end-to-end stress-test (Codex report)** plus the living `docs/audits/cleanup-backlog.md` (CL-1..CL-5) and one flagged admin-table list-view bug (UX-1). This mission **prioritizes, then ships ONLY the P0 set**; P1/P2 are specified and held.

## Summary

The five Wayfinding phases shipped, but an end-to-end stress test (an agent driving the live demo: authenticate the write tier → pair to a viewer's session → emit a live trail → record/translate/re-record a saved trail) surfaced a cluster of defects. Three are **demo-blockers or correctness blockers** that prevent the flagship from working as designed under a realistic app; the rest are integrity and hygiene issues that do not block the demo. This mission triages the whole backlog into P0/P1/P2, fixes the **P0 set finish-and-ship** (acceptance test = release gate, no BC shims — C-002 of the parent holds: there are no deployed downstream apps), and **holds P1/P2 for an explicit go**.

The throughline of the three P0 items is the same: **the flagship's seams exist but are not actually reachable from an app.** The write-tier auth seam is documented as the app override point but is structurally un-overridable; the per-session pairing token is computed and broadcast by the server but never surfaced by the client; the two-axis trail schema is built correctly but never provisioned by the command operators actually run. Each P0 fix makes an already-correct mechanism reachable, rather than redesigning it.

## Actors

- **Guiding agent (authenticated, write tier)** — needs to authenticate `/mcp/write` with a real app-issued token mapped to an account holding `present guided content`, then target one viewer's session.
- **App integrator** — needs to bind their own `WriteTierAuthInterface` (a `BearerTokenAuth` mapping a token to a real account) and have it actually take effect, without forking the framework.
- **End user (human viewer)** — opens the admin SPA, whose client must surface its own session token so the agent can pair to it; receives a live trail in their own session only.
- **Operator** — runs `db:init` on a fresh database and expects a fully provisioned schema (including the saved-trail two-axis tables) with no manual `revisions:enable` step.

## Prioritization (triage of the full backlog)

| Pri | Item | One-line | Disposition |
|----|------|----------|-------------|
| **P0-1** | Write-tier auth unconfigurable from an app | App-bound `WriteTierAuthInterface` never wins over the package default — `/mcp/write` always 401s | **FIX (this mission)** |
| **P0-2** | SSE session token not exposed for pairing | Client drops the `sessionToken` from the `connected` event; agent cannot target a viewer's session | **FIX (this mission)** |
| **P0-3** | Trail revision/translation tables not provisioned | A fresh `db:init` creates no entity-storage tables; trail two-axis tables require a manual step | **FIX (this mission)** |
| P1-4 | Translation revision-ID allocation is racy | 12 concurrent re-records → 6 SQLite unique-constraint failures on `(entity_id, langcode, revision_id)` | **HOLD for go** |
| P1-5 | `composer run dev` can't find PHP on Windows/Git Bash (`bin/dev.sh`) | The on-ramp is still broken on the actual shell | **ROUTE to `windows-runtime-ergonomics`** (reopen) |
| P2-6 | No documented token→account config example | — | **HOLD for go** |
| P2-7 | Packaged Wayfinding tests miss support-class autoloading | — | **HOLD for go** |
| P2-8 | `/api/mercure/subscribers` 403 + no subscriber monitoring in FrankenPHP fallback | wire or document | **HOLD for go** |
| P2-9 | Enter-activation on the Dismiss control (Escape works; Enter failed under automation) | confirm a11y vs automation artifact | **HOLD for go** |
| P2-10 | Noisy MCP tool-resolution failures during public `tools/list` (unavailable Bimaaji/vector deps) | quiet them; **folds in CL-2** (stale MCP auth docs) | **HOLD for go** |
| UX-1 | All admin SPA list tables render full long-text/rich-text bodies as columns | `SchemaList` needs a list-view column policy (truncate / exclude text-format fields) | **ROUTE OUT** to its own admin-list fix mission |

CL-1, CL-3, CL-4, CL-5 from `docs/audits/cleanup-backlog.md` remain in the backlog (not P0); CL-2 is folded into P2-10.

## P0 root-cause findings (read-only, verified first-hand)

### P0-1 — Authenticated write tier is unconfigurable from an app

The write tier is wired correctly *except* that an app's override never reaches it. Two compounding causes:

1. **Provider-local shadowing (primary).** `McpServiceProvider::register()` binds **both** `WriteTierAuthInterface → BearerTokenAuth([])` *and* `AuthenticatedMcpEndpoint`. The endpoint's binding closure resolves its auth with `$this->resolve(WriteTierAuthInterface::class)`, and `ServiceProvider::resolve()` (`packages/foundation/src/ServiceProvider/ServiceProvider.php:136`) checks the provider's **own** `$this->bindings` first — so the local empty-token default is returned and an app binding is never consulted.
2. **Cross-provider first-wins (secondary).** Even setting (1) aside, `HttpKernelServiceResolver::resolve()` (`packages/foundation/src/Kernel/Http/HttpKernelServiceResolver.php:44`) and `ProviderRegistryKernelServices::get()` (`.../Bootstrap/ProviderRegistryKernelServices.php:99`) return the **first** provider that has the binding. Package providers register before app providers, so a package default beats an app override.

`WriteTierAuthInterface` is already declared as the override seam (`docs/public-surface-map.php:483` — "the app override point for write-tier credentials"; `WriteTierAuthInterface.php` docblock — "until a deployment re-binds this"). The defect is that the seam does not work.

### P0-2 — SSE session token not exposed for presenter pairing

The **server is already correct**: `BroadcastRouter` emits the `connected` SSE frame with `{channels, sessionToken}` (`packages/foundation/src/Http/Router/BroadcastRouter.php:157`), where `sessionToken = substr(sha256(session_id), 0, 32)` (`SessionChannel::tokenForSessionId`) — a non-secret, per-session pairing token. The emit path already accepts it: `EmitBeaconController` reads `session` from the body and the `EmitBeaconTool` reads `session_token`, both mapping through `SessionChannel::forToken()` to the session's private channel (server-side isolation, NFR-001).

The **client drops it**: `useRealtime.ts` registers a `connected` listener that funnels the payload through `appendMessage()` into the generic `messages` buffer (`packages/admin/app/composables/useRealtime.ts:59`) and never extracts `sessionToken`; neither `useRealtime` nor `useBeacons` returns it. There is no supported API for the admin client to surface its own session token, so a presenter cannot target that viewer's session.

### P0-3 — Trail revision/translation tables not provisioned by normal install

The two-axis schema code is **correct**: `EntitySchemaSync::syncAll()` calls `ensureRevisionTable()` + `ensureTranslationRevisionTable()` for revisionable+translatable types, and `ensureTranslationRevisionTable()` is gated only on `isRevisionable() && isTranslatable()` (backend-agnostic; `SqlSchemaHandler.php:341`). `schema:sync`, `db:init --sync-schema`, and `revisions:enable` all run the **identical** `EntitySchemaSyncRunner`.

Reproduced first-hand against a fresh SQLite db:
- **`schema:sync`** creates all three: `wayfinding_trail`, `wayfinding_trail_revision`, `wayfinding_trail__translation__revision`. ✅
- **plain `db:init`** creates **zero** entity-storage tables (not even the base `wayfinding_trail` — it is not migration-backed). ❌

Root cause: `db:init` only runs `EntitySchemaSync` when the operator passes `--sync-schema` (an opt-in `HandlerOptionMode::None` flag; `DbInitHandler.php:100`, registered at `ConfigCacheDbAuditServiceProvider.php:67`). The canonical first-deploy command therefore provisions only migration-defined tables; the trail's tables (and every entity-storage table) require a separate `schema:sync` or the manual `revisions:enable wayfinding_trail` the stress test fell back to. `schema:sync` itself is correct.

## The P0-1 precedence decision (and its blast radius)

Two mechanisms could make the app override win:

- **(A) Global precedence flip — REJECTED.** Change the cross-provider resolvers from first-wins to last-wins so any app re-binding beats a package default. **Blast radius: every cross-provider binding in the framework.** Any service bound by more than one provider would flip which one wins, including intentional core-over-default shadowing and the `mergeChildProvider` stack-provider semantics. This is a sweeping change to the DI container's resolution contract; it cannot be validated by the P0 acceptance suite and is out of proportion to a demo-unblocker. Not taken.
- **(B) Dedicated app override point — CHOSEN.** Keep the existing `@api WriteTierAuthInterface` seam and make it effective, with the change **confined to the write-tier auth resolution path only**:
  1. `McpServiceProvider` **stops binding** the package default `WriteTierAuthInterface` (removing the provider-local shadow).
  2. The `AuthenticatedMcpEndpoint` closure resolves the write-tier auth through the **cross-provider kernel-services bus** (`resolveOptional`, which falls through the provider's own empty bindings to the bus and thus to an app provider's binding), with a **fail-closed `BearerTokenAuth([])` fallback** when no provider supplies one.

  Because the package no longer binds `WriteTierAuthInterface`, the bus deterministically reaches the app's binding (the only one present); when no app binds it, behaviour is unchanged (HTTP 401 for every `/mcp/write` request). **Blast radius: the write-tier auth resolution only.** No other binding's precedence changes; `McpAuthInterface` (the public read tier) is untouched, so C-001 holds.

**Decision recorded:** mechanism (B). The global precedence flip (A) is explicitly out of scope and is the documented reason a broader DI-precedence change is not attempted here.

## Requirements

### Functional (FR)

| ID | Requirement | Pri |
|----|-------------|-----|
| FR-001 | An application MUST be able to bind `WriteTierAuthInterface` (e.g. a `BearerTokenAuth` mapping a token to an account holding `present guided content`) from its own service provider and have that binding take effect for `/mcp/write`. | P0 |
| FR-002 | With an app override bound, `POST /mcp/write` MUST accept a request bearing the mapped token (authenticated, capability-holding) and MUST reject **no-token**, **junk-token**, and **valid-token-but-unprivileged** requests — the first two with HTTP 401; the unprivileged one fails closed per-tool (NFR-002). | P0 |
| FR-003 | When no app binds `WriteTierAuthInterface`, `/mcp/write` MUST continue to fail closed (HTTP 401) — the framework ships no usable default credential. | P0 |
| FR-004 | The admin client MUST expose its own session token (from the SSE `connected` frame) through a supported composable API (`useRealtime` / `useBeacons` return value). | P0 |
| FR-005 | A presenter MUST be able to emit a beacon into exactly the session identified by that token and into **no other** session (server-side isolation, existing). | P0 |
| FR-006 | A fresh `db:init` (no extra flags) MUST provision the full registered entity-storage schema, including the saved-trail two-axis tables `wayfinding_trail`, `wayfinding_trail_revision`, and `wayfinding_trail__translation__revision`. | P0 |
| FR-007 | After a fresh `db:init`, `record-trail` / `translate` / `re-record` MUST all work with **no manual `revisions:enable`** step. | P0 |
| FR-008 | `db:init` MUST provide an explicit opt-out (`--no-sync-schema`) for the rare case where an operator wants migrations only. | P0 |
| FR-101 | Translation revision-ID allocation MUST be transactional/concurrency-safe so N concurrent re-records on one trail produce zero unique-constraint failures and never lose the human-owned live value. | P1 (hold) |
| FR-102 | `composer run dev` MUST boot FrankenPHP on Windows + Git Bash with no manual PHP path. | P1 (hold — routed to `windows-runtime-ergonomics`) |

### Non-Functional / Security (NFR)

| ID | Requirement | Pri |
|----|-------------|-----|
| NFR-001 | Session isolation stays server-side: the pairing token surfaced to the client is the non-secret derived token, never the raw session id; a client cannot subscribe to another session's channel. | P0 |
| NFR-002 | Write-tier auth fails closed: absent/invalid token ⇒ 401; valid token without the capability ⇒ per-tool refusal (`isError`). | P0 |
| NFR-003 | The P0-1 change MUST NOT alter the public read tier (`McpAuthInterface` / `/mcp`); the public `tools/list` output stays byte-identical (C-001). | P0 |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | The alpha.221 public read-only trio (HTML/Markdown/MCP `entity.read`) and the public `/mcp` surface MUST NOT change behaviour. | Accepted |
| C-002 | No BC shims / deprecation layers (no deployed downstream apps) — inherited from the parent mission. | Accepted |
| C-003 | The global DI-precedence flip is out of scope (see "The P0-1 precedence decision"); the fix is confined to the write-tier auth path. | Accepted |
| C-004 | Ship P0 only; P1/P2 are specified and held for an explicit go. | Accepted |

## Acceptance (release-gate) — P0 only

- **AC-1 (P0-1):** An integration test binds an app `WriteTierAuthInterface` (a `BearerTokenAuth` mapping `TOKEN → account holding 'present guided content'`) via a provider and drives the wired `AuthenticatedMcpEndpoint`: the mapped token lists + calls the write tool; no-token and junk-token both return 401; an authenticated-but-unprivileged account is refused per-tool. The public `/mcp` `tools/list` is asserted byte-identical (C-001). *(Maps FR-001..FR-003, NFR-002/003.)*
- **AC-2 (P0-2):** A Vitest spec asserts that on a `connected` SSE frame `{channels, sessionToken}`, `useRealtime()` / `useBeacons()` expose `sessionToken`; the existing `EmitBeaconControllerTest` proves a presenter beacon lands in exactly that session's channel and no other. *(Maps FR-004/FR-005, NFR-001.)*
- **AC-3 (P0-3):** A test runs a fresh `db:init` (no flags) and asserts `wayfinding_trail`, `wayfinding_trail_revision`, `wayfinding_trail__translation__revision` all exist afterwards; `--no-sync-schema` opts out. *(Maps FR-006..FR-008.)*

All three acceptance tests are the release gate for alpha.234. P1/P2 do not ship.

## Routing (explicitly NOT this mission)

- **UX-1 (admin-list body-dump)** → its own **admin-list fix mission**. All admin SPA list tables render full long-text/rich-text bodies as columns; `SchemaList` needs a list-view column policy (truncate / exclude text-format fields). Not folded here.
- **P1-5 (`bin/dev.sh` PHP path on Windows/Git Bash)** → **reopen `windows-runtime-ergonomics-01KVGEPD`**. The shipped on-ramp (`@php bin/dev`) is correct in design but the skeleton still wires `composer dev` to `bash bin/dev.sh`, which assumes `php` on PATH; appended as a follow-up acceptance item to that mission.

## Key Entities / touch-points

- **P0-1:** `packages/mcp/src/McpServiceProvider.php` (write-tier auth resolution), `WriteTierAuthInterface` (unchanged contract), `AuthenticatedMcpEndpoint` (unchanged).
- **P0-2:** `packages/admin/app/composables/useRealtime.ts`, `packages/admin/app/composables/useBeacons.ts` (server side already correct: `BroadcastRouter`, `SessionChannel`, `EmitBeaconController`/`EmitBeaconTool`).
- **P0-3:** `packages/cli/src/Handler/DbInitHandler.php` + the `db:init` command definition (`ConfigCacheDbAuditServiceProvider.php`); `EntitySchemaSync`/`SqlSchemaHandler` unchanged.

## Assumptions

- A-001: The stress test ran in a dev environment (`APP_ENV=local`); P0-3's fix targets the operator's canonical `db:init` path. (Production `db:init` boot hardening is pre-existing and out of P0 scope.)
- A-002: `schema:sync` already provisions the trail tables correctly (verified); only `db:init`'s default needs to change.
- A-003: The pairing token is the existing non-secret `SessionChannel` token; no new token type is introduced.

## Scope

**In:** the prioritized backlog, the P0 root-cause analysis, the P0-1 precedence decision, the three P0 fixes + their release-gate acceptance tests, the coupled spec/CHANGELOG updates, and the route-outs.

**Out (held for go):** P1-4 (concurrency-safe revision-ID allocation), P2-6..P2-10 (+CL-2), CL-1/CL-3/CL-4/CL-5. **Routed out:** UX-1 (admin-list mission), P1-5 (`windows-runtime-ergonomics`).
