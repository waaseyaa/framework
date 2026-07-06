---
name: waaseyaa-spec-maintenance
description: Use when editing docs/specs/, CLAUDE.md orchestration, or agent rules — keep subsystem specs aligned with code, run drift checks, and follow the design-first workflow (GitHub issues as multi-PR anchors).
---

# Waaseyaa spec maintenance

## When to use

- Touching `docs/specs/**/*.md`, root `CLAUDE.md`, skeleton `CLAUDE.md`, or `.claude/rules/`
- Refactoring a subsystem and updating its enduring spec
- Auditing whether architecture docs match implementation

## Retrieving specs (no MCP)

Subsystem specs live in `docs/specs/`. Load them with the Read tool or search with ripgrep from the repo root, for example:

- `docs/specs/entity-system.md` — full file for the entity stack
- `rg -n "YourSymbol" docs/specs/` — find mentions across specs

The orchestration table in `CLAUDE.md` maps file patterns to spec paths — prefer that table over guessing filenames.

## Drift

After code changes that affect documented behaviour, update the relevant spec in the same PR when practical. Run:

```bash
bash tools/drift-detector.sh 5
```

Session hooks may run a shorter threshold; CI runs drift detection on pushes and PRs.

## Workflow and GitHub

This repo uses a **design-first workflow** (Spec Kitty retired 2026-07-06): brainstorm → spec (`docs/specs/`) → written plan → TDD implementation → review → verification.

**Precedence:** written plans plus **anchoring GitHub issues** are the ledger for structured work. **GitHub** is for PRs, CI, releases, security, and issues (including M11 filings) — see `docs/specs/workflow.md`. Historical Spec Kitty artifacts (`.kittify/`, `kitty-specs/`, `kitty-ops/`) are read-only history.
