# Work Packages: ocap-audit-log-substrate-01KSEFTF

**Mission:** OCAP audit log substrate (gap-matrix A3; alpha-to-beta-plan §1 item #2). See `spec.md`, `plan.md`.

Three WPs. WP02 + WP03 may proceed in parallel after WP01 is approved.

## Work Package WP01: Substrate — rename, entity, contracts, append-only guard

**Owns:** `packages/audit/**` (renamed from `packages/analytics`), `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`, root `composer.json`, metapackage `composer.json` files, `CLAUDE.md` (layer + orchestration tables).
**Depends on:** none.
**Blocks:** WP02, WP03.
**Authoritative surface:** `packages/audit/src/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-001, FR-002, FR-003, FR-004, FR-005, FR-008, FR-012, NFR-002, NFR-003, NFR-004, C-001, C-002, C-004.
**Subtasks:** T-A (rename + composer), T-B (entity + schema + migrations), T-C (writer + append-only guard), T-D (query + read interface), T-E (service provider + foundation route), T-F (unit + contract tests).
**Prompt:** `tasks/WP01-substrate-rename-and-entity.md`.

## Work Package WP02: Cross-cutting listeners + NFR-001 chaos test

**Owns:** `packages/audit/src/Listener/*`, `packages/audit/tests/Unit/Listener/*`, `packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php`, `packages/audit/tests/Integration/AuditChaosTest.php`.
**Depends on:** WP01.
**Blocks:** none (WP03 can parallel).
**Authoritative surface:** `packages/audit/src/Listener/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-006, FR-007, NFR-001, C-003.
**Subtasks:** T-G (entity lifecycle), T-H (API request), T-I (agent tool), T-J (MCP dispatch), T-K (broadcast), T-L (NFR-001 chaos test).
**Prompt:** `tasks/WP02-cross-cutting-listeners.md`.

## Work Package WP03: API endpoint + CLI prune + integration tests + docs

**Owns:** `packages/api/src/Audit/*`, `packages/api/src/Controller/AuditQueryController.php`, `packages/api/src/Http/Router/AuditApiRouter.php`, `packages/api/src/ApiServiceProvider.php`, `packages/api/composer.json`, `packages/cli/src/Command/Audit/PruneCommand.php`, `tests/Integration/PhaseOcapAudit/*`, `docs/specs/ocap-audit-log.md`, `docs/specs/codified-context-integration.md` (cross-ref append), `CHANGELOG.md`.
**Depends on:** WP01.
**Blocks:** none.
**Authoritative surface:** `packages/api/src/Audit/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-009, FR-010, FR-011, FR-013, FR-014, FR-015, NFR-002, NFR-005, C-002, C-005.
**Subtasks:** T-M (API endpoint), T-N (CLI prune), T-O (integration tests — dead-code guard + retention), T-P (docs + CHANGELOG).
**Prompt:** `tasks/WP03-api-endpoint-cli-and-tests.md`.

## Mission-level acceptance

- All FRs / NFRs / constraints in `spec.md` honoured.
- `rg -n 'Waaseyaa\\\\Analytics' .` returns nothing (FR-001 rename total).
- `rg -nE 'use Waaseyaa\\\\Audit' packages/api/src/` returns nothing (NFR-002 / C-002).
- `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy` all green.
- Dead-code guard (FR-013) confirmed by reviewer: removing the `AuditQueryReadModelInterface` binding makes `OcapAuditEndpointTest` fail.
- NFR-001 chaos test (`AuditChaosTest`) passes — primary requests survive an unwritable audit table.
- M-A5 flagship `per-record-ai-access-flagship-*` mission can dispatch into the substrate (the `AgentToolAuditListener` is in place).
