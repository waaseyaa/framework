---
work_package_id: WP04
title: Doctrine spec edits + supersession
dependencies:
- WP03
requirement_refs:
- FR-007
- FR-008
- SC-004
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T012
- T013
history: []
authoritative_surface: docs/specs/
execution_mode: code_change
owned_files:
- docs/specs/mcp-endpoint.md
- docs/specs/bimaaji.md
- packages/mcp/README.md
tags: []
---

## Objective

Update doctrine specs to reflect the bimaaji MCP bridge. **Critical invariant (C-005):** The existing 2026-05-20 "Bimaaji MCP positioning (PHP-only)" section in `docs/specs/mcp-endpoint.md` is **preserved verbatim** with a supersession callout at the top. Audit trail wins over tidiness.

## Subtasks

### T012 — `docs/specs/mcp-endpoint.md` supersession + new section (FR-007)

At the top of the existing 2026-05-20 "Bimaaji MCP positioning" section, add a callout:

```markdown
> **Superseded 2026-05-21 by mission `bimaaji-mcp-bridge-01KS5VS8`.**
> The 2026-05-20 PHP-only deferral was correct for the inherited broken Node scaffolding
> but is no longer the framework's posture. See the new "Bimaaji MCP bridge" section
> below for the active doctrine. This section is preserved as the audit trail of the
> M-G decision and its reversal.
```

Do not delete or rewrite the original content beneath the callout.

Add a new "Bimaaji MCP bridge" section below the superseded section, containing:

- The 10 tool inventory (8 read + 2 write), each with name, capability, brief description
- Capability gating: default `bimaaji.read`; opt-in via `WAASEYAA_MCP_CAPABILITIES=bimaaji.read,bimaaji.mutate`
- Transport: stdio only (no HTTP, C-002)
- Disk-write invariant: mutation tools return `PatchSet`; the MCP client is responsible for any disk persistence (C-003 / SC-003)
- The M-G → M3 transition rationale: a one-paragraph explanation that the 2026-05-20 deferral was tied to broken Node scaffolding, not to a "no external transport" principle. PHP-hosted MCP via `packages/mcp/` is the correct path.
- Tool-name prefix convention: `bimaaji_` (NFR-005)
- Pointer to mission `bimaaji-mcp-bridge-01KS5VS8` and to `packages/mcp/README.md`

End the section with the standard `<!-- Spec reviewed 2026-MM-DD — bimaaji-mcp-bridge-01KS5VS8 -->` stamp.

### T013 — `docs/specs/bimaaji.md` MCP exposure section (FR-008)

Add a new "## MCP exposure" subsection enumerating the same 10 tools (one-line each: name + capability + delegates-to) and linking to `docs/specs/mcp-endpoint.md` for transport details. Brief; the full content lives in mcp-endpoint.md.

Update the spec-reviewed stamp.

### Also (in the same WP) — `packages/mcp/README.md`

Add a "Bimaaji tool family" section in `packages/mcp/README.md` enumerating the 10 tools, the capability gating, and a `bin/waaseyaa mcp:serve` example invocation (with and without `bimaaji.mutate`).

## Definition of Done

- [ ] `docs/specs/mcp-endpoint.md` shows both the superseded 2026-05-20 section (with callout) and the new "Bimaaji MCP bridge" section.
- [ ] `docs/specs/bimaaji.md` has the new "MCP exposure" subsection.
- [ ] `packages/mcp/README.md` has the "Bimaaji tool family" section.
- [ ] Both spec files carry an updated `<!-- Spec reviewed ... -->` stamp.
- [ ] `tools/drift-detector.sh` reports no drift on these specs (the spec edits exactly match the WP03 surface).
- [ ] All local gates clean.

## Risks and notes

- **Audit trail discipline (C-005):** Do not delete, condense, or rewrite the superseded section's body — only add the callout above it. This is non-negotiable and is what reviewers will check first.
- **`docs/specs/` drift policy:** Per CLAUDE.md, drift-detector flags specs that haven't been touched recently. The stamp's `YYYY-MM-DD` must match the actual commit date.
