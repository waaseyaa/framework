# Bimaaji Install Command (Guidelines / Skills)

**Mission:** `bimaaji-install-command-01KS5W0S`
**Status:** Spec
**Target branch:** `main`
**Cross-references:** Design doc `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M5 of 5). Depends on: M1 `bimaaji-wakeup-01KS5VEY` (CLI command lives in bimaaji's command tree). Soft-related to M3 `bimaaji-mcp-bridge` (a session that has the bimaaji MCP server *and* installed guidelines is the canonical Boost-equivalent agent experience).

## Why this mission exists

Laravel Boost ships two complementary capabilities. The MCP tool surface is one (M3). The **guidelines / skills install** is the other — and it is arguably what makes Boost stick: `php artisan boost:install` writes per-client config files (`.cursorrules`, `.claude/`, `.codex/`, `.copilot-instructions.md`, `.windsurfrules`, etc.) seeded with Laravel-specific conventions, idioms, and best practices. An agent connecting to a Boost-installed project starts pre-loaded with framework awareness, before any tool call.

Waaseyaa already carries the raw material for this. `skills/waaseyaa/*/SKILL.md` files document the framework's specialist subsystems (entity-system, access-control, api-layer, admin-spa, ai-integration, ingestion, infrastructure, mcp-endpoint, middleware-pipeline, operator-diagnostics, security-defaults, spec-maintenance). The orchestration table in `CLAUDE.md` references them. But no command **pushes them to a consumer project's agent-client config**. A developer who installs Waaseyaa and opens Claude Code gets none of that knowledge — they have to discover it from `CLAUDE.md` (which is technically reachable as project context, but is sized for Claude Code's repo-aware tooling, not for Cursor's `.cursorrules`, Codex's instructions file, etc.).

**The contract.** A new `bin/waaseyaa bimaaji:install [--client=<name>] [--features=guidelines,skills] [--force]` command. Reads `skills/waaseyaa/*/SKILL.md`, transforms them for each client's expected format, writes per-client config files to the consumer's project root. Interactive UX matches Boost's: if no `--client` is given, the command prompts; if config files already exist, it asks before overwriting.

## User scenarios

### Primary flow: a developer installs guidelines for Claude Code

1. Developer adds `waaseyaa/framework` to their project. They run `bin/waaseyaa bimaaji:install`.
2. The command detects no existing client config and prompts: "Which AI clients should I configure? [claude, cursor, codex, copilot, gemini, windsurf, junie]" (or a checklist UX).
3. Developer selects `claude`. Command reads `skills/waaseyaa/*/SKILL.md`, generates `.claude/CLAUDE.md` (or `.claude/skills/waaseyaa-*.md` — exact path picked during plan based on what Claude Code currently consumes) with framework conventions, layer architecture, operation checklists, and pointers to docs/specs.
4. Developer opens Claude Code. The agent has framework awareness before any tool call.

### Primary flow: a developer installs guidelines for multiple clients

1. `bin/waaseyaa bimaaji:install --client=claude --client=cursor --client=codex`.
2. The command writes per-client config for each. Output is one line per client: "Wrote .claude/skills/waaseyaa-entity-system.md (and 11 more) for client=claude."
3. Each client's config respects its own file-format conventions (Cursor uses `.cursorrules`, Copilot uses `.github/copilot-instructions.md`, etc.).

### Primary flow: a developer wants only the guidelines, not the skills

1. `bin/waaseyaa bimaaji:install --client=claude --features=guidelines`.
2. The command writes the always-on guidelines (core layer rules, code-style invariants, fail-closed defaults) but skips the on-demand specialist skill payloads.
3. Token cost is lower; the agent gets the high-leverage rules without the long-tail subsystem detail.

### Primary flow: re-running after framework updates

1. Six months later, `waaseyaa/framework` ships new specialist skills. Developer runs `bin/waaseyaa bimaaji:install --client=claude --force`.
2. The command overwrites existing client config with the current skill content. Without `--force`, it shows a diff and asks per file.

### Edge cases

- **Consumer config already exists and was hand-edited.** Without `--force`, the command shows a diff and asks per file: overwrite / skip / merge (the merge path uses a documented merge convention — likely "preserve consumer additions between marker comments"). With `--force`, it overwrites silently.
- **Unknown `--client` value.** Command exits non-zero, lists supported clients, suggests the closest match.
- **A skill file is malformed (missing frontmatter, etc.).** Command logs a warning, skips that skill, continues. Other skills install normally.
- **Consumer project has no `.git/`.** Command works anyway — writing to client config doesn't require git. (Boost has the same behavior.)
- **Re-running with no changes.** Command detects identical content and prints "No changes needed for client=claude" rather than no-oping silently.

## Requirements

### Functional

| ID | Status | Requirement |
|---|---|---|
| FR-001 | Mandatory | `bin/waaseyaa bimaaji:install` exists as a first-party CLI command, registered with `packages/bimaaji/`'s command discovery (per M1 C-002). |
| FR-002 | Mandatory | Command flags: `--client=<name>` (multi-value: can be repeated; if omitted, command prompts interactively), `--features=guidelines,skills` (comma-separated; default `guidelines,skills`), `--force` (skip overwrite confirmation), `--dry-run` (print intended writes without writing). |
| FR-003 | Mandatory | Supported clients at launch: `claude` (Claude Code), `cursor`, `codex` (OpenAI Codex CLI), `copilot` (GitHub Copilot), `gemini` (Gemini CLI), `windsurf`, `junie`. Each client has its own file-path convention documented in the command's help text and in `docs/specs/bimaaji-install.md`. |
| FR-004 | Mandatory | The command reads `skills/waaseyaa/*/SKILL.md` from the installed `waaseyaa/framework` package (via Composer's vendor path). It does not require the consumer project to have a `skills/` directory. |
| FR-005 | Mandatory | Per-client transformations: each skill is rewritten into the client's expected format. Plan decides the exact mappings; minimum is "drop frontmatter, prepend a friendly title, preserve markdown body" for clients that accept plain markdown files. |
| FR-006 | Mandatory | Interactive prompts when `--force` is not set and a target file already exists: command shows a diff (unified format) and asks (a) overwrite (b) skip (c) view full diff. No silent overwrites. |
| FR-007 | Mandatory | `--dry-run` mode prints to stdout the list of files that would be written, their full target paths, and a one-line summary of each (size, source skill). Exits 0. Performs no disk I/O beyond reading. |
| FR-008 | Mandatory | The command's exit code reflects the operation: 0 on success, non-zero on user-cancelled prompt, unknown client, unreadable skill, or unwritable target. |
| FR-009 | Mandatory | Re-running with no changes detects identical content (by hash) and reports "No changes needed for client=<name>" rather than silently writing identical bytes. |
| FR-010 | Mandatory | A new `docs/specs/bimaaji-install.md` documents: supported clients, file-path conventions per client, transformation rules per client, `--force` / merge conventions, and the steps to add a new client. |
| FR-011 | Mandatory | Integration test `tests/Integration/PhaseN/BimaajiInstall/InstallCommandTest.php` runs the command against a sandbox consumer directory for each supported client; asserts files are written to the documented paths with non-empty content; asserts re-run with `--force` is idempotent. |
| FR-012 | Mandatory | Integration test `InstallCommandDryRunTest.php` runs `--dry-run`, asserts zero filesystem changes, asserts the printed file list matches FR-011's actual-write set. |
| FR-013 | Mandatory | `packages/bimaaji/README.md` adds an "Installing guidelines / skills" section documenting the command and supported clients. |

### Non-functional

| ID | Status | Threshold |
|---|---|---|
| NFR-001 | Mandatory | `bimaaji:install` for a single client completes in ≤ 1 s on a developer machine (median), excluding interactive prompts. Read + transform + write of ~15 skill files is cheap; the budget is to catch any accidental I/O storms. |
| NFR-002 | Mandatory | The command never writes outside the consumer project's root (sandboxed to `$projectRoot/`). Test asserts this by running the command against a sandbox and verifying no writes occur outside the sandbox root. |
| NFR-003 | Mandatory | Diff output (FR-006) uses standard unified-diff format so consumers can pipe it to `less` or copy-paste into a PR. |
| NFR-004 | Mandatory | Error messages on unknown `--client` value (FR-008) include the closest-match suggestion via Levenshtein distance. Mirrors `bin/waaseyaa list`'s typo-handling. |

### Constraints

| ID | Status | Constraint |
|---|---|---|
| C-001 | Mandatory | The install command lives in `packages/bimaaji/` (Layer 5) — same package as the rest of bimaaji. It does not require new infrastructure outside bimaaji + the framework's CLI kernel. `bin/check-package-layers` passes. |
| C-002 | Mandatory | The command never overwrites a consumer's hand-edited config without explicit consent (`--force` flag or interactive `overwrite` prompt). This is the trust contract: install is opt-in and respectful. |
| C-003 | Mandatory | The skill content shipped to clients is canonical — it is read from `skills/waaseyaa/*/SKILL.md` and not rewritten or paraphrased at install time. Per-client transformation is structural (format conversion), not semantic. |
| C-004 | Mandatory | No network access. The command operates purely on the installed `waaseyaa/framework` vendor directory and the consumer's project root. No downloads, no telemetry. |
| C-005 | Mandatory | `composer verify` is green on the merge commit. |
| C-006 | Mandatory | No CI hooks bypassed. |

## Success criteria

| ID | Metric | How verified |
|---|---|---|
| SC-001 | A developer can install Waaseyaa guidelines for Claude Code with one command and zero manual file creation. | `InstallCommandTest::installsForClaude` passes (FR-011). |
| SC-002 | The command supports all 7 launch clients (FR-003). | `InstallCommandTest` parameterized over each client passes. |
| SC-003 | Re-running is idempotent and shows "no changes needed" when content matches (FR-009). | Test asserts second run prints the no-change message and writes nothing. |
| SC-004 | `--dry-run` performs no disk I/O. | `InstallCommandDryRunTest` asserts file-tree byte-identical before and after. |
| SC-005 | Hand-edited consumer config is preserved without `--force`. | Test edits a file after first install, re-runs without `--force`, asserts the prompt fires and skipping leaves the file unchanged. |
| SC-006 | `composer verify` green on merge commit. | CI status check. |

## Key entities

| Entity | Role | Net change |
|---|---|---|
| `Waaseyaa\Bimaaji\Command\BimaajiInstallCommand` (new) | First-party CLI command. | +1 file. |
| Per-client transformer classes (7) | Convert skill content per client format. | +7 files (or fewer if shared base). |
| `docs/specs/bimaaji-install.md` (new) | Doctrine spec. | +1 file. |
| `packages/bimaaji/README.md` | Public README. | Edit. |
| Integration tests (2 files) | End-to-end + dry-run tests. | +2 files. |
| `skills/waaseyaa/*/SKILL.md` | Source content. | No change (existing). |
| `CHANGELOG.md` | `[Unreleased]` entry. | Edit. |

## Assumptions

- M1 is merged. Bimaaji has a `ServiceProvider` and the CLI kernel auto-discovers commands.
- `skills/waaseyaa/*/SKILL.md` files have stable frontmatter (`name`, `description`, `triggers`) the transformer can rely on. If frontmatter is inconsistent, WP01 normalizes the source files before any client transformation logic.
- Each launch client has a stable, documented config-file convention. Re-verify in WP02 against each client's current docs. If a client's convention has changed since the brainstorming session (2026-05-21), the plan adjusts.
- Boost's interactive UX is a fine reference for our own UX. We don't need to copy it pixel-for-pixel; matching the spirit (per-client selection, overwrite consent, diff-before-write) is sufficient.

## Out of scope

- Adding new client support beyond the 7 launch clients. Adding a client is a documented extension per FR-010.
- Editing existing client config files based on consumer customization heuristics (e.g. "don't overwrite their preferred tone"). Consumers either accept overwrites or skip.
- Telemetry, download counts, or any network access.
- Per-skill installation toggles (`--skill=entity-system`). The command installs everything in the chosen features. Per-skill granularity is a follow-up if needed.
- Integration with the consumer's git workflow (auto-commit, branch creation). Consumers handle their own VCS.

## WP outline (for /spec-kitty.plan)

The planner is free to revise. Indicative shape:

- **WP01 — Source content audit.** Audit `skills/waaseyaa/*/SKILL.md` for frontmatter consistency. Normalize if needed. Document the source schema in `docs/specs/bimaaji-install.md`.
- **WP02 — Client research + transformer contracts.** For each of the 7 launch clients, document the current config-file convention. Define the transformer contract (input: parsed `SKILL.md`; output: per-client config text + target path). Unit tests for each transformer.
- **WP03 — Command implementation.** `BimaajiInstallCommand` with `--client`, `--features`, `--force`, `--dry-run`. Interactive prompts. Idempotency check (FR-009). Help text.
- **WP04 — Integration tests.** Sandbox-based tests for each client (FR-011). Dry-run assertion (FR-012). Hand-edited preservation (SC-005).
- **WP05 — Docs + verify.** `docs/specs/bimaaji-install.md` (FR-010). README edit (FR-013). CHANGELOG. Full `composer verify`.

## References

- Laravel Boost research summary: `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §"Context". External reference: https://laravel.com/ai/boost.
- `skills/waaseyaa/*/SKILL.md` — source content for the install.
- CLAUDE.md "Orchestration" — the skill-routing table that informs which skills are framework-canonical.
- M1 `bimaaji-wakeup-01KS5VEY` — provides the CLI command tree this mission extends.
- M3 `bimaaji-mcp-bridge-01KS5VS8` — complementary: an agent with both installed guidelines and the bimaaji MCP server is the canonical "Boost-equivalent" agent experience.
- Memory: `feedback_modern_php_rules` — typed interfaces only.
- Memory: `feedback_pr_traceability_signals` — close any tracking issue via merge footer if filed.
