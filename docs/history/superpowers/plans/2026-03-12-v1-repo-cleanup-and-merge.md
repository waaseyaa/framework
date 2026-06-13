# v1.0 Repo Cleanup and Merge Plan

> **For agentic workers:** REQUIRED: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clean up superseded PRs, assign milestones, merge the three v1.0 PRs, and verify repo state.

**Architecture:** Pure GitHub operations — no code changes. Sequential dependency: cleanup before merges, merges before verification.

**Tech Stack:** GitHub CLI (`gh`), GitHub MCP tools

---

## Chunk 1: Cleanup and Merge

### Task 1: Close superseded PR #299

**Context:** PR #299 was an earlier PHP 8.4 attempt. PR #302 supersedes it with a cleaner implementation.

- [ ] **Step 1: Comment on PR #299**
  Add comment: "Superseded by #302. Closing."

- [ ] **Step 2: Close PR #299**
  Close without merging.

### Task 2: Assign milestone v1.1 to issues #295 and #296

**Context:** Issues #295 (ServiceProvider::commands() resolver) and #296 (mail package) were triaged to v1.1 during planning but never assigned on GitHub.

- [ ] **Step 1: Assign v1.1 milestone to #295**

- [ ] **Step 2: Assign v1.1 milestone to #296**

### Task 3: Merge PR #302 (PHP 8.4 + CI quality gates)

**Context:** Closes #299 (the issue, not the PR). Independent of #303/#304. Must merge first since #303/#304 have more tests that benefit from the CI improvements.

- [ ] **Step 1: Check CI status on PR #302**
  Verify checks have passed (or only pre-existing failures like lint/manifest).

- [ ] **Step 2: Merge PR #302 into main**

### Task 4: Merge PR #303 (HttpKernel decomposition)

**Context:** Closes #297. Depends on #302 being merged first (base branch update).

- [ ] **Step 1: Update PR #303 branch with latest main**
  After #302 merges, main has new commits.

- [ ] **Step 2: Check CI status on PR #303**

- [ ] **Step 3: Merge PR #303 into main**

### Task 5: Merge PR #304 (McpController decomposition)

**Context:** Closes #298. Depends on #303 being merged (avoids merge conflicts).

- [ ] **Step 1: Update PR #304 branch with latest main**

- [ ] **Step 2: Check CI status on PR #304**

- [ ] **Step 3: Merge PR #304 into main**

### Task 6: Verification

**Context:** Confirm all GitHub state is correct post-merge.

- [ ] **Step 1: Confirm PRs #302, #303, #304 are merged**

- [ ] **Step 2: Confirm PR #299 is closed (not merged)**

- [ ] **Step 3: Confirm issues #295, #296 have milestone v1.1**

- [ ] **Step 4: Confirm issues #297, #298 are auto-closed**

### Task 7: Finalization

- [ ] **Step 1: Clean up worktrees**
  Remove the three agent worktrees used for implementation.

- [ ] **Step 2: Confirm main branch is clean**
  Pull latest main, verify tests pass.
