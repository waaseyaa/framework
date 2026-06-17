# Spec Kitty bugs encountered (to file as issues later)

Running log of `spec-kitty` CLI defects hit while driving mission
`agent-readable-app-full-bar-01KVB4VM`. CLI version **3.1.8**, project Spec Kitty 3.1.8,
host Windows 11 (git-bash), Python 3.14, installed via `uv tool`.

---

## BUG-1 — `ensure_global_agent_skills()` crashes the whole CLI on Windows (read-only rmtree)

**Severity:** high (CLI is 100% unusable until worked around — the bootstrap runs in
`main_callback` on *every* invocation).

**Symptom:** every `spec-kitty` command aborts with a traceback ending in:
`PermissionError: [WinError 5] Access is denied: 'C:\\Users\\jones\\.claude\\skills\\ad-hoc-profile-load\\SKILL.md'`

**Root cause:** `specify_cli/runtime/agent_skills.py`:
- `_sync_skill_root()` line ~77 does `file_path.chmod(mode & ~0o222)` — makes every synced
  skill file **read-only**.
- On the next version bump, `_sync_skill_root()` lines ~68-72 try to `dest.unlink()` /
  `shutil.rmtree(dest)` those files. On Windows you cannot unlink a read-only file, so it
  raises `PermissionError [WinError 5]`. POSIX `unlink` ignores the read-only bit, so this is
  Windows-only.
- Aggravating factor: the global skill root resolves to `~/.claude/skills`, which the Claude
  Code harness also manages — so the CLI is fighting another process for those files.

**Fix ideas:** on Windows, `os.chmod(path, stat.S_IWRITE)` before unlink (or pass an
`onexc`/`onerror` to `shutil.rmtree` that clears the read-only bit and retries); or don't
chmod skill files read-only at all; or skip syncing into a skills root owned by another tool.

**Workaround used:** pre-seed the version short-circuit at
`agent_skills.py:91-94` — write `%LOCALAPPDATA%\spec-kitty\cache\agent-skills.lock` with the
exact CLI version string so the resync returns early without touching the skill roots.

---

## BUG-2 — `next` on `tasks_outline` blocks on `tasks.md` with a confusing "no eligible steps" / guard message

**Severity:** medium (confusing UX; not obvious how to proceed).

**Symptom:** after writing `wps.yaml`, calling
`spec-kitty next --agent claude --mission <slug> --result success` while in `tasks_outline`
does **not** advance to `tasks_packages`. It returns either:
- `kind: query`, `reason: "No eligible steps: remaining steps have unmet dependencies."`, or
- `kind: step, action: tasks-outline` (same step) with
  `guard_failures: ["Required artifact missing: tasks.md"]`.

**Why it's confusing:** the runtime DAG is `tasks_outline → tasks_packages → tasks_finalize`,
and `tasks.md` is only produced at/after `tasks_finalize`. So the guard for leaving the
*first* tasks step requires an artifact produced by the *last* tasks step. There's no prompt
or hint telling the agent that the expected path is "author `tasks/WP*.md`, author `tasks.md`,
then run `spec-kitty agent mission finalize-tasks`" — the agent has to discover that from the
command-template files on disk. A `next` decision that surfaced the concrete follow-up command
(like `specify`/`plan` steps do via `prompt_file`) would resolve this.

**Status:** worked around by reading `command-templates/tasks.md` + `tasks-packages.md`
directly and materializing artifacts manually.

---

## BUG-3 — WARNING text printed to stdout before JSON breaks `--json` parsing

**Severity:** low-medium (every JSON consumer must strip leading non-JSON).

`spec-kitty next --json` (3.2.0) prints `WARNING  charter preflight failed
(consumer=dashboard): synthesized_drg missing; run 'spec-kitty charter synthesize'` to
**stdout** ahead of the JSON object. Piping to `python -m json.tool`/`jq` fails silently
(no output). Warnings should go to stderr, or `--json` should emit pure JSON on stdout.

---

## BUG-4 — `specify` under 3.2.0 does not create `status.json`; runtime then BLOCKS

**Severity:** high (a freshly-specified 3.2.0 mission cannot be driven by `next`).

Mission `charter-truth-up-2026-06-01KVB6MW` created with `spec-kitty specify` under CLI 3.2.0.
Its dir has `status.events.jsonl` but **no `status.json`**. `spec-kitty next ... --result success`
then returns:
`[BLOCKED] ... Status read path not found ... checked .worktrees\<slug>-coord\kitty-specs\<slug>
and kitty-specs\<slug>`.
A mission created under 3.1.8 (`agent-readable-app-full-bar-01KVB4VM`) *does* have `status.json`,
so the 3.2.0 `specify` path regressed status-file creation. Workaround under test:
`spec-kitty doctor mission-state --fix` (the upgrade tried to run this automatically but
aborted on a dirty tree — see also BUG-5).

---

## BUG-5 — upgrade's mission-state repair silently skipped on dirty tree, leaving missions half-migrated

**Severity:** medium.

`spec-kitty upgrade --yes` (3.1.8→3.2.0) ran migrations but its final TeamSpace/mission-state
repair refused: *"Refusing mission-state repair with dirty relevant paths. Commit/stash them
first or pass --allow-dirty."* The upgrade still exited as if successful, leaving mission-state
artifacts unmigrated (contributes to BUG-4 and to "Template changed during active run" on the
in-flight mission). Upgrade should either stage/commit its own outputs first, run the repair
with `--allow-dirty` for paths it owns, or hard-fail loudly so the operator knows the repair
didn't run.

---

## BUG-6 — in-flight mission blocked by "Template changed during active run. Migration required" after CLI upgrade, with no one-shot fix surfaced

**Severity:** medium.

After upgrading mid-mission, `spec-kitty next` on the active run (`agent-readable-app-full-bar`,
run `c5e03d06…`) returns `Reason: Template changed during active run. Migration required.` The
message names no command to perform the migration; the operator must discover it
(`spec-kitty migrate` / `doctor mission-state`?). A blocked decision should cite the exact
recovery command.

---

<!-- Append further bugs below as encountered. -->

