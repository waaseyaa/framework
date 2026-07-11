# Drift Detection — Waaseyaa v1.1

Documents the drift detection strategy for specs, templates, config, and policies.

For steady-state drift scans and C17+ logging, follow [m11-periodic-drift-scan-protocol.md](../../docs/specs/m11-periodic-drift-scan-protocol.md) and the [M11 drift-scan log issue template](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md).

## What is Drift?

Drift occurs when the codebase diverges from its documented contracts (specs, CLAUDE.md, policies).
Undetected drift causes agents to generate code that conflicts with recent changes.

## Detection Tools

| Tool | Purpose | How to run |
|------|---------|-----------|
| `tools/drift-detector.sh` | Finds stale specs by comparing last-modified dates | `bash tools/drift-detector.sh` |
| Read / `rg` on `docs/specs/` | Cross-reference subsystem specs during development | Local files in the repo |
| Anchor issues | Scope and work-package state for multi-PR efforts | `gh issue view <N>` (incl. comment trail) |
| `bin/check-milestones` | Optional GitHub issue ↔ Track milestone hygiene | `bin/check-milestones` |

## Drift Categories

### Spec drift
Spec in `docs/specs/` describes behaviour that no longer matches the code.

**Detection:** `drift-detector.sh` compares spec mtime vs. source file mtime.
**Resolution:** Update the spec. Open `docs/specs/<name>.md` from the repo to load the current version.

### Template drift (SSR)
Twig templates render content that diverges from the PHP domain model.

**Detection:** Template checksums (planned for v1.1).
**Resolution:** Regenerate or manually update the template.

### Config drift
`config/waaseyaa.php` differs from the schema exported by `SchemaController`.

**Detection:** Compare schema endpoint output vs. config file (planned for v1.1).

### Policy drift
Access policies registered in `PackageManifest` diverge from `#[PolicyAttribute]` declarations.

**Detection:** `PackageManifestCompiler` re-scans on boot when manifest is stale.

### Migration drift
Database schema differs from what `SqlSchemaHandler` would generate.

**Detection:** Planned — compare live schema vs. generated DDL (v1.1).

## Operating cadence (M11 steady-state)

| When | Action |
|------|--------|
| Every development session | Under an ongoing effort, read the anchor issue (including its comment trail) before generating work (`docs/specs/workflow.md`). |
| Quarterly (or after major roadmap reshuffles) | Full GitHub milestone/issue audit: see template and history under `docs/audits/` (e.g. `2026-04-25-github-milestones-issues-audit.md`). |
| Weekly (or before each release tag) | Run `bash tools/drift-detector.sh`; if output lists stale specs, update specs or touching code; log exceptional batch results via `.github/ISSUE_TEMPLATE/m11-drift-scan-log.md`. |
| Intentional architecture or contract change | Open a governed-change issue from `.github/ISSUE_TEMPLATE/m11-governed-change.md` before large diffs. |
| Composer/manifest edits | Run `composer check-composer-policy` (CI gate). |

## v1.1 Goals

- [ ] Template checksum verification in `tools/drift-detector.sh`
- [ ] Config schema drift report via `bin/waaseyaa schema:diff`
- [ ] Migration drift report via `bin/waaseyaa schema:migrate --dry-run`
