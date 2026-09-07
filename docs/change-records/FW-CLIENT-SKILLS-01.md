# FW-CLIENT-SKILLS-01

Issue mirror: #2660. Candidate: Codex/Claude canonical per-skill delivery and
explicit client capability diagnostics. Parent source: c0a8d5d4dab09d9bb527ec502f5c61b88b027564.

## Contract

ADR-026 records the root integrator's technical decisions. Enduring behavior
is specified in `docs/specs/bimaaji-install.md` and `docs/specs/bimaaji.md`.
Both per-skill clients receive the full canonical inventory; unsupported
mechanics are diagnosed, not silently omitted or claimed equivalent.

## Review and evidence

Claude implemented the leased candidate; independent Codex review required an
installed-consumer proof. The repaired proof installs copied package bytes,
checks eleven skills with identical IDs/hashes/content, rejects monorepo
fallback, and bounds Codex root guidance. Focused verification passed 292 tests;
the packaged proof and 42 preflight checks passed. Full exact-commit
qualification and hosted CI remain required before governed landing.

## Residual scope

Shared root guidance ownership (#2686), MCP descriptors (#2663), generated-state
updates (#2664), and broader client acceptance (#2665) remain separate. No
release or deployment is included. The current packaged proof covers install,
idempotent rerun, marker-bounded refresh, stale-file removal, ownership, and
human-content preservation; it does not provide an explicit verify or uninstall
lifecycle, and it does not run Composer with the issue's literal `--no-dev`
condition. This checkpoint is therefore part of #2660 rather than closure.
Agent review is not human approval.
