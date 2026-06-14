---
work_package_id: WP03
title: BimaajiInstallCommand + flags + prompts
dependencies:
- WP02
requirement_refs:
- FR-001
- FR-002
- FR-006
- FR-007
- FR-008
- FR-009
- NFR-001
- NFR-002
- NFR-003
- NFR-004
- C-002
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T008
- T009
- T010
history: []
authoritative_surface: packages/bimaaji/src/Command/
execution_mode: code_change
owned_files:
- packages/bimaaji/src/Command/BimaajiInstallCommand.php
- packages/bimaaji/src/BimaajiServiceProvider.php
tags: []
---

## Objective

Implement `BimaajiInstallCommand` with all four flags (`--client`, `--features`, `--force`, `--dry-run`), interactive prompts, idempotency check, sandbox discipline, and Levenshtein typo suggestion. Register via `BimaajiServiceProvider::nativeCommands()`.

## Subtasks

### T008 — Command class skeleton

`BimaajiInstallCommand::execute(CliIO $io): int`:

1. Resolve flags from `$io->option(...)`.
2. Resolve `$projectRoot = getcwd()` for sandbox discipline (AD-06).
3. Resolve `$features = explode(',', $io->option('features') ?? 'guidelines,skills')`.
4. Resolve `$clients`: if `--client` not passed, prompt interactively (TTY check; abort on non-TTY without `--force`).
5. For each client:
   a. Look up the transformer (use a small static registry: `clientId → ClientTransformerInterface`).
   b. If transformer not found, exit 1 with Levenshtein suggestion (FR-008, NFR-004).
   c. Compute target files via `transformer->targetFiles($parsedSkills)`.
   d. For each target file: idempotency check → write / prompt / skip.
6. Print summary: `Client <id>: X written, Y unchanged, Z skipped.`

### T009 — Interactive prompts + idempotency (FR-006, FR-009)

For each target file:

1. Compute would-be content.
2. If target doesn't exist: write + count as `written`.
3. If target exists and content matches (sha1 equal): skip + count as `unchanged`.
4. If target exists and content differs:
   - If `--force`: write + count as `written`.
   - Else if TTY: show one-line diff summary, prompt `[o/s/d/a]` (overwrite / skip / view diff / overwrite all).
   - Else (non-TTY, no force): exit 2 with error: "Cannot prompt for overwrite confirmation on non-TTY. Pass --force or --dry-run."

Diff output for the `view diff` choice uses `Symfony\Component\Console\Helper\DiffHelper` or a manual unified-diff implementation. NFR-003 — must be pipeable to `less`.

### T010 — Dry-run (FR-007) + sandbox discipline (NFR-002) + registration

`--dry-run`:
- Compute target files exactly as in T008/T009.
- Instead of writing, print `[DRY-RUN] would write <path> (<bytes> bytes from skill=<source>)`.
- Return exit 0.
- Assert no `fwrite` / `file_put_contents` / `mkdir` calls (the test harness wraps writes in a StreamWrapper that fails on write).

Sandbox: every target path resolves via `realpath()` and must be a prefix of `$projectRoot`. Violation triggers an error envelope without writing.

Register: edit `BimaajiServiceProvider::nativeCommands()` (already edited by M1 WP02 / `graph:dump`). Add a second `yield` for `bimaaji:install` with the same inline-FQCN `\Waaseyaa\CLI\CommandDefinition` pattern.

## Definition of Done

- [ ] `BimaajiInstallCommand` exists with all 4 flags wired.
- [ ] Interactive prompts work in TTY mode; non-TTY without `--force` exits with the documented error.
- [ ] Idempotent re-runs print the "no changes needed" summary.
- [ ] Dry-run performs zero writes (provable by the StreamWrapper trick in the test).
- [ ] Levenshtein typo suggestion works (`clade` → "Did you mean 'claude'?").
- [ ] Sandbox check blocks writes outside `$projectRoot`.
- [ ] Command is discoverable via `bin/waaseyaa list` after `bin/waaseyaa optimize:manifest`.
- [ ] All local gates clean.

## Risks and notes

- **Adding a second `yield` to nativeCommands():** Confirm M1 WP02's `nativeCommands()` shape — it currently has one `yield new \Waaseyaa\CLI\CommandDefinition(...)`. Add a second after it, with the same inline-FQCN pattern (no `use Waaseyaa\CLI\...` added to the file — that's the layer-rule escape hatch from M1).
- **CliIO option types:** Per M1 WP02 implementation, `CliIO::option()` returns `string|int|float|bool|array|null`. Repeated `--client=...` flags become an array; `--features=...` is a CSV string requiring `explode()`.
- **Non-TTY detection:** Use `posix_isatty(STDIN)` (works on Unix; the framework's CLI helper layer likely has a `$io->isInteractive()` shortcut — confirm and prefer it).
- **Levenshtein bound:** Suggest only if distance ≤ 2 (typical for short client names). Otherwise just list supported clients.
