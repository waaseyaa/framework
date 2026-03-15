# Drift Detection — Waaseyaa v1.1

Documents the drift detection strategy for specs, templates, config, and policies.

## What is Drift?

Drift occurs when the codebase diverges from its codified context (specs, CLAUDE.md, contracts).
Undetected drift causes agents to generate code that conflicts with recent changes.

## Detection Tools

| Tool | Purpose | How to run |
|------|---------|-----------|
| `tools/drift-detector.sh` | Finds stale specs by comparing last-modified dates | `bash tools/drift-detector.sh` |
| `waaseyaa_search_specs` MCP | Cross-references specs during development | Via Claude Code MCP tools |
| `bin/check-milestones` | Validates milestone hygiene at session start | `bin/check-milestones` |

## Drift Categories

### Spec drift
Spec in `docs/specs/` describes behaviour that no longer matches the code.

**Detection:** `drift-detector.sh` compares spec mtime vs. source file mtime.
**Resolution:** Update the spec. Run `waaseyaa_get_spec <name>` to load current version.

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

## v1.1 Goals

- [ ] Template checksum verification in `tools/drift-detector.sh`
- [ ] Config schema drift report via `bin/waaseyaa schema:diff`
- [ ] Migration drift report via `bin/waaseyaa schema:migrate --dry-run`
