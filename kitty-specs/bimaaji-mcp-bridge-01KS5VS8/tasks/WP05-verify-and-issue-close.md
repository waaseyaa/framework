---
work_package_id: WP05
title: Cross-mission gate + verify + #1463 close
dependencies:
- WP04
requirement_refs:
- FR-012
- SC-001
- SC-005
- SC-006
- C-006
- C-007
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T014
- T015
history: []
authoritative_surface: kitty-specs/bimaaji-mcp-bridge-01KS5VS8/
execution_mode: planning_artifact
owned_files:
- kitty-specs/bimaaji-mcp-bridge-01KS5VS8/verification.md
- CHANGELOG.md
tags: []
---

## Objective

Manual smoke test against a real MCP client, record verification, write CHANGELOG, and ensure the merge commit closes GitHub #1463 via footer.

## Subtasks

### T014 — Manual smoke against Claude Code

Per spec SC-001, an external MCP client must be able to connect and call the 8 read tools. The integration tests cover the in-process MCP loop; this subtask exercises the actual MCP wire protocol with a real client.

Steps (record in verification.md):

1. From a clean Waaseyaa app: `claude mcp add -s local -t stdio waaseyaa php bin/waaseyaa mcp:serve` (or whatever entry point WP01 confirmed).
2. Start Claude Code, verify the 8 tools list (default session, no `bimaaji.mutate`).
3. Invoke `bimaaji_list_entities` from a Claude conversation; verify a sensible payload.
4. Invoke `bimaaji_propose_mutation`; verify the `capability_required` error surfaces.
5. Restart the MCP server with `WAASEYAA_MCP_CAPABILITIES=bimaaji.read,bimaaji.mutate`.
6. Invoke `bimaaji_propose_mutation`; verify success path.
7. Confirm no files were written by the MCP server during the mutation calls (snapshot the worktree pre/post).

Record screenshots / transcript excerpts in verification.md. If a Cursor / Codex / Gemini CLI client is also available, repeat the abbreviated smoke against one more client to confirm prefix discipline holds.

### T015 — Verification log + CHANGELOG + #1463 close

`kitty-specs/bimaaji-mcp-bridge-01KS5VS8/verification.md` documenting:

- Local gate sweep (cs-check, phpstan, layer, composer-policy, dead-code, getQuery, full `composer verify` exit code).
- Test surface (3 integration tests, ~12 assertions).
- Manual smoke transcript (T014).
- Mission PR provenance (WP01..WP05 PR numbers).

`CHANGELOG.md` `[Unreleased]` — one bullet for the 10-tool MCP exposure, the capability gating, and the M-G supersession.

**Issue close (FR-012, SC-005):** Ensure the merge commit's footer includes `Closes #1463`. GitHub auto-closes the issue when the PR merges. Per memory `feedback_partial_fix_closes_footer`, the footer must be on the merge commit (squash-merge will use the PR body's footer).

## Definition of Done

- [ ] Manual smoke completed against at least one MCP client; transcript in verification.md.
- [ ] All local gates green.
- [ ] CHANGELOG has the bullet.
- [ ] `Closes #1463` is in the merge-commit / PR-body footer (verified via `gh pr view`).
- [ ] verification.md records all gate exit codes + smoke results.
- [ ] No `--no-verify` on any commit (C-007).

## Risks and notes

- **MCP client availability:** If no MCP client can be tested locally, document the gap explicitly in verification.md and file a follow-up issue to run the smoke once a client is available.
- **`Closes #1463` discipline:** Per memory `feedback_partial_fix_closes_footer`, GitHub will auto-close the issue. If this mission delivers only part of the issue's scope, change to `Refs #1463` and add a comment to the issue explaining what was/wasn't delivered.
- **`gh issue close` redundancy:** Per memory `feedback_pr_traceability_signals`, sometimes auto-close fails. After merge, verify the issue is closed; if not, `gh issue close 1463 -c "Closed by mission bimaaji-mcp-bridge-01KS5VS8 — see #<merge-pr-number>"`.
