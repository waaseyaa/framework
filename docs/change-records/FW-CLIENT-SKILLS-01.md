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
fallback, installs all seven registered clients, proves each canonical body
appears exactly once per client, rejects machine-specific source paths, and
bounds Codex root guidance. Its second, minimal consumer keeps
`waaseyaa/core` and `waaseyaa/cli` at runtime while installing the canonical
`waaseyaa/ai-development` bundle through `require-dev`; the bundle depends on
`waaseyaa/ai-agent`, whose runtime requirements bring in the Bimaaji sync tool.
It resolves every referenced Waaseyaa command against
the installed catalogue, checks manifest-backed drift and marker-bounded
update, and preserves human content before a literal
`composer install --no-dev` removes both the development bundle and Bimaaji
from the installed package graph without deleting the portable generated
guidance. Focused verification passed
292 tests; the original packaged proof and 42 preflight checks passed. The
expanded lifecycle proof, full exact-commit qualification, and hosted CI remain
required before governed landing.

## Residual scope

Shared root guidance ownership (#2686), MCP descriptors (#2663), generated-state
updates (#2664), and broader client acceptance (#2665) remain separate. No
release or deployment is included. The current packaged proof covers install,
idempotent rerun, marker-bounded refresh, stale-file removal, ownership, and
human-content preservation. The added minimal-consumer lifecycle checks the
existing manifest hashes, observes a seeded drift failure, reconciles through a
real update, and proves the development bundle and Bimaaji are absent after
literal `composer install --no-dev`. Package removal intentionally preserves
portable generated guidance and consumer-authored content. It is not a new
generated-output uninstall command: reconciliation and removal of generated
state remain owned by #2664. Agent review is not human approval.

Containment evidence is deliberately split. This packaged lifecycle checks its
manifest verifier's rejection of absolute, traversal, symlinked, and resolved
out-of-root paths. The real `bimaaji:install` write/prune sandbox is covered by
`InstallCommandSandboxContainmentTest` and the Windows junction gate documented
in `docs/specs/bimaaji-install.md`; this packaged proof does not exercise or
claim a real-installer escape sentinel. Adding that packaged sentinel remains
optional hardening rather than completed acceptance evidence here.
