# Implementation Plan — bimaaji-install-command-01KS5W0S

**Mission:** `bimaaji-install-command-01KS5W0S`
**Status:** Plan
**Spec:** [spec.md](spec.md)
**Design doc:** `docs/history/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §M5
**Depends on:** M1 `bimaaji-wakeup-01KS5VEY` (CLI command lives in bimaaji's command tree)
**Soft-related to:** M3 `bimaaji-mcp-bridge-01KS5VS8` (an agent with both installed guidelines AND the bimaaji MCP server is the canonical "Boost-equivalent" experience)

## Branch contract

- Current branch at plan time: `main`
- Planning + base branch: `main`
- Merge target: `main`

## Engineering alignment

`bin/waaseyaa bimaaji:install` ships per-client config files (`.claude/`, `.cursorrules`, `.codex/`, `.github/copilot-instructions.md`, etc.) seeded with the framework-canonical skill content from `skills/waaseyaa/*/SKILL.md`. Source content is canonical (C-003 — read, not paraphrased). Per-client transformation is structural (frontmatter strip, format conversion), not semantic. The trust contract is explicit: never overwrite hand-edited consumer config without `--force` or an interactive `overwrite` confirmation (C-002).

Boost-equivalent UX: per-client selection, overwrite consent, diff-before-write, idempotent re-runs. We don't copy Boost's UX pixel-for-pixel; we match the spirit.

## Architecture decisions

### AD-01 — Command location

`packages/bimaaji/src/Command/BimaajiInstallCommand.php`. Registered via M1's `BimaajiServiceProvider::nativeCommands()` (or alongside `GraphDumpHandler` if M1 WP02 made the convention "one command class per CommandDefinition"). bimaaji is L4; command logic stays in bimaaji per C-001.

### AD-02 — Per-client transformer architecture

A `ClientTransformerInterface`:

```php
namespace Waaseyaa\Bimaaji\Install;

interface ClientTransformerInterface
{
    public function clientId(): string;             // 'claude', 'cursor', etc.
    public function targetFiles(array $skills): array; // [{path, content}]
}
```

Concrete implementations under `packages/bimaaji/src/Install/Client/`:

| Client | Class | Target files |
|---|---|---|
| `claude` | `ClaudeClientTransformer` | `.claude/skills/waaseyaa-<skill>.md` per skill + a project-root `CLAUDE.md` snippet appended/created |
| `cursor` | `CursorClientTransformer` | `.cursorrules` (single concatenated file) |
| `codex` | `CodexClientTransformer` | `.codex/instructions.md` (single file) |
| `copilot` | `CopilotClientTransformer` | `.github/copilot-instructions.md` (single file) |
| `gemini` | `GeminiClientTransformer` | `GEMINI.md` at project root (single file) |
| `windsurf` | `WindsurfClientTransformer` | `.windsurfrules` (single concatenated file) |
| `junie` | `JunieClientTransformer` | `.junie/guidelines.md` (single file) |

The exact paths must be re-verified in WP02 against each client's current documentation (assumption flagged in spec). If a client's convention has changed since 2026-05-21, the plan adjusts.

### AD-03 — Skill source format

Source: `skills/waaseyaa/*/SKILL.md`. Each file has YAML frontmatter (`name`, `description`, `triggers`) followed by markdown body. WP01 audits the source files for frontmatter consistency; if any drift, normalize in WP01 before transformer logic depends on the schema (mitigation for spec assumption #2).

### AD-04 — `--features` switch

`--features=guidelines,skills` (default both). Map:

- `guidelines` → always-on rules (layer architecture, code-style invariants, fail-closed defaults). Per-client: a short prelude file or the top section of the main config file. Single shared content (no per-skill enumeration).
- `skills` → on-demand specialist skill payloads. Per-client: one file per skill (Claude convention) or appended sections (single-file clients).

The `guidelines` content is generated from a small curated subset of `CLAUDE.md`'s constitution-style sections — not the full file (too long for Cursor's `.cursorrules` token budget). WP01 picks the exact extract.

### AD-05 — Interactive prompt UX (FR-006)

When `--force` is not set and a target file already exists with different content:

```
~/proj $ bin/waaseyaa bimaaji:install --client=claude
.claude/skills/waaseyaa-entity-system.md exists with different content.
  d - show full diff
  o - overwrite
  s - skip this file
  a - overwrite all remaining files for this client
> 
```

Uses `Symfony\Component\Console\Question\ChoiceQuestion` (or whatever the framework's CLI prompt mechanism is). Diff output uses `--unified` format (NFR-003 — pipeable to `less`).

### AD-06 — Sandbox / write discipline (NFR-002)

`BimaajiInstallCommand::execute()` resolves `$projectRoot = getcwd()` and asserts every target path is `realpath($candidate) === substr($realpath, 0, strlen($projectRoot))` (i.e., inside the project root). Any path that escapes the sandbox triggers an error envelope without writing.

Test asserts this by running against a fixture sandbox and using `find $tempDir -newer $startSentinel` to check no files were created outside.

### AD-07 — Idempotency (FR-009)

For each target file:
1. Compute `sha1_file()` of the existing file (if any).
2. Compute `sha1()` of the would-be content.
3. If equal: log "No changes needed" for that file. Skip.

The command prints a summary at the end: "Client claude: 3 written, 9 unchanged, 0 skipped."

### AD-08 — `--dry-run` discipline (FR-007 / SC-004)

In dry-run mode, the command:

1. Reads source skills (allowed — read-only).
2. Computes target file paths and contents.
3. Prints `[DRY-RUN] would write <path> (<bytes> bytes from skill=<source>)` per file.
4. Returns exit 0.

Asserts no `fwrite`, `file_put_contents`, `mkdir` calls. The contract test wraps the command in a `StreamWrapper` that fails any write attempt.

## Test strategy

### Integration tests (`tests/Integration/PhaseN/BimaajiInstall/`)

| Test | Coverage | Key assertions |
|---|---|---|
| `InstallCommandTest::installsFor<Client>` (×7, parameterized) | FR-011, SC-001, SC-002 | One per client; sandbox dir; files written to documented paths; non-empty content; idempotent re-run |
| `InstallCommandDryRunTest::performsNoWrites` | FR-012, SC-004 | Dry-run output matches non-dry-run target file list; no filesystem changes |
| `InstallCommandPreservesHandEditsTest` | FR-006, SC-005 | Hand-edit a file post-install; re-run without `--force`; assert prompt fires; choosing `skip` leaves file unchanged |
| `InstallCommandSandboxTest` | NFR-002 | Run against a sandbox; assert no writes outside the sandbox root |
| `InstallCommandUnknownClientTest` | FR-008, NFR-004 | `--client=clade` (typo); assert exit code non-zero + Levenshtein suggestion "Did you mean 'claude'?" |

Charter / governance: `.kittify/charter/charter.md` absent. Skipped.

## WP breakdown

| WP | Title | Depends on | Authoritative surface | LOC est. |
|---|---|---|---|---|
| **WP01** | Source content audit + spec scaffold | — | `skills/waaseyaa/*/SKILL.md` (normalize if needed) + `docs/specs/bimaaji-install.md` skeleton | ~120 |
| **WP02** | Client transformers + per-client unit tests | WP01 | `packages/bimaaji/src/Install/Client/*.php` + 7 unit test files | ~600 |
| **WP03** | `BimaajiInstallCommand` + flags + prompts | WP02 | `packages/bimaaji/src/Command/BimaajiInstallCommand.php` + register | ~400 |
| **WP04** | Integration tests | WP03 | 5 integration tests | ~450 |
| **WP05** | Docs + verify | WP04 | `docs/specs/bimaaji-install.md` complete, `packages/bimaaji/README.md` edit, `CHANGELOG.md`, `kitty-specs/.../verification.md` | ~150 |

## File-change summary

| Layer | Path | Action |
|---|---|---|
| Source content | `skills/waaseyaa/*/SKILL.md` | edit only if WP01 audit finds frontmatter inconsistency |
| L4 bimaaji src | `packages/bimaaji/src/Install/ClientTransformerInterface.php` | create (WP02) |
| L4 bimaaji src | `packages/bimaaji/src/Install/Client/{Claude,Cursor,Codex,Copilot,Gemini,Windsurf,Junie}ClientTransformer.php` | create x7 (WP02) |
| L4 bimaaji src | `packages/bimaaji/src/Command/BimaajiInstallCommand.php` | create (WP03) |
| L4 bimaaji src | `packages/bimaaji/src/BimaajiServiceProvider.php` | edit (WP03 — register new command in `nativeCommands()`) |
| L4 bimaaji tests | `packages/bimaaji/tests/Unit/Install/*Test.php` | create x7 (WP02) |
| Integration | `tests/Integration/PhaseN/BimaajiInstall/*Test.php` | create x5 (WP04) |
| Spec | `docs/specs/bimaaji-install.md` | create (WP01 skeleton, WP05 complete) |
| Bimaaji docs | `packages/bimaaji/README.md` | edit (WP05) |
| Root | `CHANGELOG.md` | edit (WP05) |
| Mission | `kitty-specs/bimaaji-install-command-01KS5W0S/verification.md` | create (WP05) |

## Risk analysis

### R1 — Per-client config conventions have shifted (MEDIUM)

**Likelihood:** Medium. AI client config-file conventions evolve fast — 6 months of drift since the 2026-05-21 brainstorming session.
**Mitigation:** WP02's first subtask is a documented per-client convention verification — open each client's current docs, confirm the target path, update AD-02 if needed. Each transformer's class-level docblock cites the source of truth (`https://docs.cursor.com/...` etc.) with a date stamp.

### R2 — Skill frontmatter drift (LOW)

**Likelihood:** Low. The framework already enforces `@api` and other docblock conventions; SKILL.md frontmatter is the loose end.
**Mitigation:** WP01 audit. If any skill is missing required frontmatter fields, normalize in WP01.

### R3 — Interactive prompt UX on non-TTY (MEDIUM)

**Likelihood:** Medium. The command might be invoked in CI or by an agent, both non-TTY.
**Mitigation:** If `STDIN` is not a TTY and `--force` is not set, the command must abort with a clear error explaining the choice: "Cannot prompt for overwrite confirmation on non-TTY. Pass --force to overwrite or --dry-run to preview." Test covers this path.

### R4 — Levenshtein suggestion correctness (LOW)

**Likelihood:** Low. PHP's `levenshtein()` is built-in.
**Mitigation:** Test asserts common typos suggest the right client (`clade` → `claude`, `cursour` → `cursor`).

### R5 — Sandbox escape via symlinks (MEDIUM)

**Likelihood:** Medium. A symlink in the consumer's project root could point outside the project. The sandbox-write discipline (AD-06) uses `realpath()` resolution, which follows symlinks. This is intentional — if the consumer has set up symlinks, the install respects them.
**Mitigation:** Document the `realpath()` behavior in `docs/specs/bimaaji-install.md`. Test asserts that an explicit symlink to outside-the-sandbox is followed (not blocked) — this is the documented behavior, not a bug.

## Dependencies on downstream missions

None within this 5-mission cluster. M5 is the cluster's terminal mission.

## Charter / governance check

`.kittify/charter/charter.md` not present. Skipped.

## Out of scope (per spec)

Per spec §Out of scope: no clients beyond the 7 launch set, no consumer-customization heuristics, no telemetry / network access, no per-skill toggles, no VCS integration.
