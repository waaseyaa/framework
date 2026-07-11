# Waaseyaa docs

Map of the `docs/` tree. The most important distinction: **`specs/` is canonical
and kept current; `history/` is frozen.** Edit specs; read history.

## Canonical — living knowledge (edit these)

| Path | What |
|------|------|
| `specs/` | Subsystem contracts, file maps, edge cases. The source of truth; kept in sync with code (`tools/drift-detector.sh` gates drift). |
| `adr/` | Architecture Decision Records — why things are the way they are. |

## Reference / how-to

| Path | What |
|------|------|
| `cookbook/` | Task-oriented recipes. |
| `conventions/` | Cross-cutting conventions (cache tags, naming, …). |
| `governance/` | Project governance and policy. |
| `upgrades/`, `upgrade-notes/` | Consumer upgrade guidance. |
| `security/` | Security guidance and notes. |
| `extension-authoring/` | Building third-party extensions. |
| `examples/` | Worked examples. |
| `architecture/`, `references/` | Architecture overviews and reference material. |

## Process / operational (point-in-time or workflow)

| Path | What |
|------|------|
| `audits/` | Dated point-in-time audit snapshots (coverage, milestones, dead-code). Not current state. |
| `reviews/` | Dated review write-ups. |
| `roadmap/`, `roadmap.md` | Forward-looking planning. |
| `triage/`, `migration/`, `packagist/`, `ci/`, `telescope/` | Topic-scoped working notes and generated inventories. |

## Frozen history — NOT current (read-only)

| Path | What |
|------|------|
| `history/plans/` | Dated design & implementation plans (session artifacts), incl. Aurora-era docs. |
| `history/superpowers/` | The pre-Spec-Kitty planning workflow, superseded 2026-04-24 by `kitty-specs/`. |
| repo-root `kitty-specs/` | Spec Kitty mission artifacts (Spec Kitty retired 2026-07-06) — read-only history. |

See `history/README.md`. Active planning follows the anchor-issue + design-first
workflow (`docs/specs/workflow.md`); design/specs land in `docs/specs/`.
