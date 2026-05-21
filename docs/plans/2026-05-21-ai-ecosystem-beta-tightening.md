# AI Ecosystem Beta Tightening — Design (2026-05-21)

> Session artifact. Captures decisions taken during the 2026-05-21 brainstorming
> session about Bimaaji's role, the relationship to the `ai-*` packages, and the
> AI ecosystem cuts needed before beta. Drives 5 Spec Kitty missions.

## Context

Bimaaji (`packages/bimaaji/`) currently ships 25 PHP classes spanning graph
introspection (Entity, Routing, JsonApi, Admin, Sovereignty, PublicSurface),
a validated mutation protocol (`MutationRequest` → `MutationValidator` →
`PatchSet` with `SovereigntyGuardrails`), a Task DSL, and a Spec index
provider. The README still describes the package as "scaffolding only" and
the package has zero in-tree consumers, no `ServiceProvider`, no CLI
commands, and no HTTP surface. The 2026-05-20 M-G mission concluded that
bimaaji would stay PHP-only and deferred MCP exposure (#1463 closed
not-planned).

Comparison research (2026-05-21):

- **Laravel Boost** is the direct comparator — a Composer dev dependency that
  registers a stdio MCP server with ~12 tools (`application-info`,
  `database-schema`, `list-routes`, `read-log`, `tinker`, `search-docs`, …)
  plus a guidelines/skills prompt-injection layer. Read-mostly with one
  mutation surface (`tinker` — raw PHP eval). Per-client install for Claude
  Code, Cursor, Codex, Copilot, Gemini CLI, Windsurf, Junie. MIT, stable.

- **Laravel PAO** is orthogonal — an autoloader hook that detects agent
  environment variables and emits compact JSON for PHPUnit, Pest, PHPStan,
  Rector, and Artisan output. ~99.8% token reduction on test runs.
  Framework-agnostic. Not a Boost replacement.

Bimaaji already has **deeper introspection** than Boost (sovereignty profile,
JsonApi-aware, public-surface map) and a **better mutation story** (validated
patches with content hashes, not arbitrary eval). What it lacks is reachability:
no transport, no service wiring, no consumer.

## Decisions

| # | Question | Decision |
|---|---|---|
| D1 | Primary consumer | Both external agents and embedded `ai-agent` runtime — keep bimaaji separate, build two transports |
| D2 | Beta scope | All four pillars: Wakeup, MCP bridge, in-process integration + guidelines install, agent-output package |
| D3 | MCP mutation surface | Read + mutation tools, mutation gated by per-session `bimaaji.mutate` capability token (default off) |
| D4 | PAO equivalent home | New top-level `packages/agent-output/` at Layer 0 (Foundation tier) |
| D5 | Mission sequence | M1 Wakeup → M2 In-process → M3 MCP bridge → (M4 Agent-output ∥ M5 Guidelines install) |

D3 explicitly reverses the 2026-05-20 M-G "PHP-only" call. Rationale: Boost's
shipped success confirms MCP exposure is the value, not a deferred nicety. The
M-G mission's stated path (Option 2: extend `packages/mcp/`) becomes the
implementation route, not a "wait" signal.

## Missions

### M1 — Bimaaji wakeup

Promote bimaaji from library-with-no-consumers to a wired-up Layer 5 package.

**Surfaces:**
- `Waaseyaa\Bimaaji\BimaajiServiceProvider` registered via
  `extra.waaseyaa.providers` in `packages/bimaaji/composer.json`.
- Tagged service collection for `GraphSectionProviderInterface` — default 6
  providers (Entity, Routing, JsonApi, Admin, Sovereignty, PublicSurface)
  registered out of the box, third-party providers discoverable.
- `bin/waaseyaa graph:dump [--section=<key>] [--format=json|yaml]` — first-party
  CLI command. Lives in `packages/bimaaji/src/Command/` or `packages/cli/src/Command/Bimaaji/`
  (decide during plan; mirrors the existing cross-package command pattern).
- README rewrite — drop "scaffolding only", document the wired pipeline,
  enumerate default providers, link to spec.
- Integration tests in `tests/Integration/PhaseN/Bimaaji/` covering the full
  generate-graph flow over a booted kernel.
- Spec refresh: `docs/specs/bimaaji.md` Implementation Status section flips
  from "scaffolding" to "shipped"; provider inventory + service-provider
  contract documented.

**Dependencies:** none.
**Blocks:** M2, M3, M5.

### M2 — In-process integration (ai-agent ↔ bimaaji)

Validate the bimaaji API by wiring it into the embedded `ai-agent` runtime
*before* freezing the external (MCP) shape.

**Surfaces:**
- New tools under `packages/ai-agent/src/Tool/Bimaaji/`:
  - `IntrospectGraphTool` — wraps `ApplicationGraphGenerator::generate()`.
    Capability: `bimaaji.read`.
  - `IntrospectSectionTool` — wraps one provider by key. Capability: `bimaaji.read`.
  - `ProposeMutationTool` — wraps `MutationValidator::validate()`. Capability:
    `bimaaji.mutate`.
  - `GeneratePatchTool` — wraps `PatchGenerator::generate()`. Capability:
    `bimaaji.mutate`. Returns `PatchSet` (does not write to disk).
- All four use `#[AsAgentTool]` so attribute discovery picks them up.
- Contract tests verifying each tool delegates correctly + capability gates fire.
- One end-to-end agent definition demonstrating the "agent improves your app"
  story — uses introspection + proposes a patch + emits structured result.
- Update `packages/ai-agent/README.md` to enumerate bimaaji-backed tools.

**Dependencies:** M1.
**Blocks:** M3 (the API shape used here gets reused by the MCP bridge).

### M3 — MCP bridge

Expose bimaaji over MCP via `packages/mcp/`. Reverses the 2026-05-20 M-G
"PHP-only" decision; reopens or supersedes #1463.

**Surfaces (read tools — capability `bimaaji.read`):**
- `application_info`
- `list_routes`
- `list_jsonapi`
- `list_entities`
- `list_admin`
- `sovereignty_profile`
- `public_surface`
- `search_specs` — queries `docs/specs/` (Boost-`search-docs` analog)

**Surfaces (write tools — capability `bimaaji.mutate`, default off):**
- `propose_mutation`
- `generate_patch`

**Capability gate:** per-session token, default `bimaaji.read` only. Consumers
opt in to mutation via session config. Mirrors the existing capability pattern
in `ai-agent` (`AgentRunController` + `AgentRunAccessPolicy`).

**Docs / governance:**
- `docs/specs/mcp-endpoint.md` positioning + tool inventory updated.
- `docs/specs/bimaaji.md` adds MCP exposure section.
- Reopen #1463 with the new decision, or supersede with a new tracking issue.

**Dependencies:** M1; ideally M2 (validates API).

### M4 — Agent-output package

New top-level package at Layer 0 — agent-optimized output for CI gates and
test runs. PAO-equivalent, kept independent because it's framework-wide
infrastructure that any consumer (including non-bimaaji users) benefits from.

**Surfaces:**
- New `packages/agent-output/` (Layer 0). Requires:
  - `composer.json` with sort-packages, layer-policy compliance, branch-alias.
  - Split.yml matrix entry (per `feedback_release_split_pre_flight_gap`).
  - GitHub repo provisioning + Packagist registration after first split push
    (per `feedback_new_package_release_checklist`).
- Env detector — looks for `CLAUDE_CODE`, `CURSOR_AGENT`, `CODEX_CLI`,
  `GEMINI_CLI`, `WINDSURF`, `JUNIE`. Pluggable so new clients can be added.
- Formatter interface + JSON formatters for:
  - PHPUnit, Pest
  - `phpstan` (level 5 output)
  - `bin/check-dead-code`
  - `bin/check-getquery-bindings`
  - `bin/check-composer-policy`
  - `bin/check-package-layers`
  - `tools/drift-detector.sh`
- Opt-in flag `--output=json`; auto-on under agent env. Human terminal output
  unchanged when no agent detected (PAO-style transparency).
- New spec `docs/specs/agent-output.md`.

**Naming:** Package slug `agent-output`. Anishinaabemowin name candidate left
for the mission's `specify` step — leading idea: stay descriptive
(`waaseyaa/agent-output`). If a cultural name lands, it's a rename within the
mission.

**Dependencies:** none (independent of bimaaji).

### M5 — Guidelines install command

Boost-shaped install command that ships Waaseyaa's existing `skills/waaseyaa/*`
content into per-project agent client configs.

**Surfaces:**
- `bin/waaseyaa bimaaji:install [--client=…] [--features=guidelines,skills]`
- Supported clients at launch: `claude` (Claude Code), `cursor`, `codex`,
  `copilot`, `gemini`, `windsurf`, `junie`.
- Reads from `skills/waaseyaa/*/SKILL.md`, writes per-client config files
  (`.claude/`, `.cursor/rules/`, `.codex/`, etc.).
- Interactive UX matches Boost's `boost:install`.
- New spec `docs/specs/bimaaji-install.md`.

**Dependencies:** M1 (CLI command lives in bimaaji's command tree).

## Cross-cutting decisions

- **Bimaaji stays Layer 5, separate package.** `ai-*` is the embedded agent
  runtime; bimaaji is the surface external and embedded agents call into. This
  mirrors Laravel's split (Boost for external, AI-SDK for embedded). Folding
  bimaaji into `ai-*` would conflate two different consumers and lose the
  cultural name.

- **Mutation safety.** Bimaaji never exposes `tinker`-style raw eval. All
  mutations go through `MutationValidator` → `PatchSet`, content-hashed and
  reviewable. This is our key safety advantage over Boost and must be
  preserved across both transports.

- **API stability.** M2 validates the API surface in-process before M3 freezes
  it for external consumers. If M2 reveals shape problems, M3 inherits the
  fixes. Do not file M3 in parallel with M2 — the dependency is real.

- **Capability tokens.** Reuse the existing capability framework from `ai-agent`
  rather than inventing a parallel one. Default off for mutation.

- **Agent-output is independent.** M4 ships as its own package because PAO-style
  output is framework-wide infrastructure, not bimaaji-specific. A consumer
  who never installs bimaaji still benefits from agent-output for their test
  runs.

## Risks and open items

- **Naming for `packages/agent-output/`.** Stay descriptive or pick an
  Anishinaabemowin name? Defer to the mission's `specify` step.
- **Bimaaji ServiceProvider auto-wiring.** M1 must decide whether
  `BimaajiServiceProvider` is opt-in (consumers add it manually) or
  auto-registered via `extra.waaseyaa.providers`. Recommend auto.
- **MCP transport choice.** Boost uses stdio MCP. Verify `packages/mcp/`
  already supports stdio (the M-G mission research should have confirmed
  this; revalidate before M3 begins).
- **Guidelines install conflicts with existing user config.** Boost handles
  this with interactive prompts. M5 must do the same — never silently
  overwrite.
- **#1463 closure.** Reopen vs. supersede with a new tracking issue. Likely
  supersede with a new "bimaaji MCP bridge" tracker that references the M-G
  decision and the reversal rationale.

## Filing checklist (each mission)

Per `feedback_spec_kitty_mission_filing_pattern`, every mission needs:

1. `spec-kitty specify` with mission name and description
2. Doctrine spec at `docs/specs/<mission-slug>.md` (or update existing spec)
3. Kitty-specs mirror at `kitty-specs/<id>-<slug>/`
4. `mission.json` with goals, FRs, NFRs, success criteria
5. Manifest entry in `kitty-specs/manifest.json`
6. `meta.json` enrichment: stakeholders, related missions, deps
7. Checklists for plan, tasks, implement, review
8. Cross-link to this design doc
9. PR template traceability — every PR cites mission ID + work-package

## Next steps

1. Commit this design doc.
2. File M1 (bimaaji-wakeup) — unblocks the chain.
3. File M2, M3, M4, M5 in parallel after M1 lands (M2/M3 have a soft sequence
   noted in cross-cutting; the lane scheduler will respect it).
4. Each mission goes through `spec-kitty specify` → `plan` → `tasks` →
   `implement` → `review` → `accept` → `merge`.
