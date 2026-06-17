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

<!-- Append further bugs below as encountered. -->
