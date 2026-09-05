# Waaseyaa Agent Adapter

All Codex and generic agents must read and follow
`docs/governance/agent-contract.md`. It is the canonical cross-agent operating
contract for authorization, isolation, evidence, Git, review, and publication.

Read `CLAUDE.md` for the current architecture map, subsystem routing, commands,
and repository-specific gotchas. Read the applicable `docs/specs/` contracts
and `docs/specs/workflow.md` before substantive work. Do not run retired Spec
Kitty commands.

Harness permissions and available tools do not expand the user's requested
scope. If this adapter, a nested `AGENTS.md`, or a tool skill conflicts with the
shared contract, follow the higher-authority instruction and report the conflict.

When isolating work in a new git worktree and relocating the agent root, follow
the remote-first sequence in `docs/governance/agent-contract.md` ("Starting and
isolating work"): do not point the harness at a local-only branch name before
`origin` has that ref.
