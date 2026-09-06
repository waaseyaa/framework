# Distribution exclusion policy

Status: LIVE (#2648)

Related: [per-site-convergence-audit.md](./per-site-convergence-audit.md), [exact-source-artifact.md](./exact-source-artifact.md), [governed-gates.md](./governed-gates.md)

## Why this spec exists

Docker build contexts, deploy artifacts assembled by `rsync`, and source archives are three independent surfaces that previously diverged: `skeleton/.dockerignore` carried its own blanket `*.md` rule, `verify-deploy-rsync` only guarded the `docs/` anchoring footgun, and the root `.gitattributes` had no `export-ignore` entries at all. This contract governs those three named mechanisms; it does not claim to govern every possible production-artifact builder. Secrets (#2647), machine-local MCP configuration, local agent adapters, and local operator scratch state must stay out of each applicable governed surface without reflexively excluding the approved documentation corpus (`docs/`, especially `docs/specs/`).

## The contract

### 1. Canonical policy authority

`support/distribution-exclusion-policy-v1.json` is the sole machine-readable authority. It declares:

- **categories** — `secrets`, `machine_mcp_config`, `local_agent_adapters`, `local_operator_state`
- **approved_docs guards** — patterns forbidden on composer export and deploy rsync surfaces
- **surface targets** — `.gitattributes` export attributes, `skeleton/.dockerignore`, and required deploy-rsync workflow exclusions

`bin/check-distribution-exclusion` validates rendered outputs against the policy. It carries no expected exclusion values of its own.

### 2. Git and Composer archive mechanisms

The repository's split-artifact sealing path uses `git archive`, which honors `.gitattributes export-ignore` (#2649). Composer's own `composer archive` command also applies the export attributes in this repository shape. `composer.json` `archive.exclude` is therefore not a second policy authority. `--self-test` executes both commands against separate isolated throwaway trees and inspects both produced tar inventories; it does not infer Composer behavior from the Git proof.

Secret patterns intentionally re-admit `.env.example` and nested `.env.example` templates with later `-export-ignore` attributes. The proof requires those templates and `docs/specs/live.md` to remain present while representative secret, MCP, agent-adapter, storage, and operator-state files are absent.

### 3. Surface renderers

| Surface | Target | Format |
|---------|--------|--------|
| Docker build context | `skeleton/.dockerignore` | `.dockerignore` patterns inside managed `#2648` markers; docker-specific preamble (`.git`, `vendor`, `*.md`, …) stays outside the managed block |
| Git/composer export | `.gitattributes` | `export-ignore` lines inside managed markers |
| Deploy rsync | each app workflow containing `rsync` | every policy-declared exclusion must appear as an anchored `--exclude='/…'` argument; omission and mutation fail; unanchored `docs/` remains forbidden |

Intentional negations are preserved per syntax. Docker secret patterns keep `!.env.example` and `!**/.env.example` **after** the `.env.*` rules because `.dockerignore` uses last-match wins semantics (#2647).

### 4. Approved documentation

`docs/` and `docs/specs/` must remain exportable. The policy forbids reflexive `docs/`, `docs/**`, or `*.md` export-ignore entries, including rules appended outside the managed block, and unanchored deploy `docs/` excludes. Docker images may still omit `*.md` via the docker preamble — that surface is not the approved-docs distribution channel.

### 5. Verification

- `php bin/check-distribution-exclusion` — fast repo-state parity for all three surfaces
- `php bin/check-distribution-exclusion --self-test` — interruption-safe sentinel mutations in unique temporary trees plus isolated, executable `git archive` and `composer archive` proofs
- `php bin/check-distribution-exclusion --render` — refresh managed sections from policy
- `skeleton/bin/maintenance/verify-deploy-rsync` — delegates to the framework gate in a monorepo or installed `vendor/waaseyaa/framework`, passing the application root in deploy-only mode; retains the legacy `docs/` fallback only when the framework gate is unavailable
- `tests/Architecture/DistributionExclusionGateTest.php` — binds gate, CI, and preflight roster

The deploy controls pin the required exclusion list independently of the policy renderer. A complete workflow must pass, and omitting or mutating each required argument independently must fail. Self-test inputs are copied into temporary files; neither self-test nor the Architecture tests rewrite `.gitattributes` or `skeleton/.dockerignore`.

`bin/check-skeleton-docker-secret-exclusion` (#2647) remains the substantive Docker daemon proof for generated `.env` secrets. This policy gate governs the declarative `.dockerignore` contract it depends on.

### 6. Out of scope

Framework dist size trimming (`kitty-specs/`, `packages/`, `tests/`, allowlists, compressed budgets) is owned by **#2650**, not this policy. Raw-docs vs compiled corpus separation is owned by **#2661**. Client skill file generation is owned by **#2660**; this policy only governs whether `.agents/` and `.claude/` adapter trees reach production artifacts.

## Implementation map

| File | Role |
|------|------|
| `support/distribution-exclusion-policy-v1.json` | Policy authority |
| `tools/lib/DistributionExclusionPolicy.php` | Loader, renderer, verifier |
| `bin/check-distribution-exclusion` | Gate |
| `.gitattributes` | Managed `export-ignore` block |
| `skeleton/.dockerignore` | Managed security exclusions |
| `skeleton/bin/maintenance/verify-deploy-rsync` | Deploy-rsync delegate |
| `tests/Fixtures/DistributionExclusion/workflows/unanchored-docs.yml` | Sentinel fixture |
