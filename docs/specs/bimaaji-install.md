# Bimaaji install command (`bin/waaseyaa bimaaji:install`)

> Ships the framework-canonical agent skill pack to consumer projects in
> per-client formats. Lifted in spirit from Laravel Boost's
> `php artisan boost:install`; framework-native.

**Mission:** `bimaaji-install-command-01KS5W0S`
**Status:** Shipped (M5 WP01–WP05, 2026-05-23). All sections below reflect the live `bimaaji:install` surface.

## Overview

A first-party CLI command (`bin/waaseyaa bimaaji:install`) that reads
`skills/waaseyaa/*/SKILL.md` from the installed framework and writes
per-client config files to the consumer project root. Skill source
content is canonical (read, not paraphrased — C-003); per-client
transformation is structural (frontmatter strip, format conversion).

The trust contract is explicit: the command never overwrites a
hand-edited consumer config file without `--force` or an interactive
`overwrite` prompt (C-002).

## Source schema (audit result, M5 WP01)

All `skills/waaseyaa/*/SKILL.md` files audited 2026-05-22 carry the
following YAML frontmatter:

```yaml
---
name: <kebab-case identifier>
description: <one-line description>
---
```

Required fields: `name`, `description`. Both are non-empty strings.
Optional fields: `triggers` (list of strings) — not present in the
audited set as of 2026-05-22 but accepted by the schema for downstream
extensions.

The body is markdown with at least one `## ` heading and is sized at or
below ~8 KB per skill. Single-file clients (Cursor, Copilot) may apply
their own truncation strategy in M5 WP02 if a downstream skill grows
materially beyond that bound.

The audit script is implicit (re-run by re-executing the M5 WP01
verification block) — there is no committed audit binary. If a new
skill is added with non-conforming frontmatter, the WP02 transformer
suite will fail at the unit-test layer.

## Supported clients

Seven launch clients shipped via per-client
`ClientTransformerInterface` implementations under
`packages/bimaaji/src/Install/Client/`. Each transformer's
class-level docblock cites the upstream convention URL + verification
date so convention drift is caught at the WP05 manual smoke (re-run
when a downstream operator integrates a new MCP client).

| Client id | Transformer | Target path(s) | Upstream convention |
|---|---|---|---|
| `claude` | `ClaudeClientTransformer` | `.claude/skills/waaseyaa-<id>.md` per skill plus a shared `.claude/CLAUDE-WAASEYAA.md` index | <https://docs.claude.com/en/docs/claude-code/skills> (verified 2026-05-22) |
| `cursor` | `CursorClientTransformer` | `.cursorrules` (single file) | Cursor docs (verified 2026-05-22) |
| `codex` | `CodexClientTransformer` | `.codex/AGENTS.md` (single file) | Codex docs (verified 2026-05-22) |
| `copilot` | `CopilotClientTransformer` | `.github/copilot-instructions.md` (single file) | GitHub Copilot docs (verified 2026-05-22) |
| `gemini` | `GeminiClientTransformer` | `GEMINI.md` (single file) | Gemini CLI docs (verified 2026-05-22) |
| `windsurf` | `WindsurfClientTransformer` | `.windsurfrules` (single file) | Windsurf docs (verified 2026-05-22) |
| `junie` | `JunieClientTransformer` | `.junie/guidelines.md` (single file) | Junie docs (verified 2026-05-22) |

`ClaudeClientTransformer` is the only multi-file transformer (Claude
Code loads `.claude/skills/<id>.md` individually so users can `/skill
<id>` an individual entry). The other six clients use the shared
`AbstractSingleFileClientTransformer` base — one consolidated file
per project, content framed by
`<!-- waaseyaa:bimaaji:install BEGIN -->` / `END` markers so a future
`--merge` mode can splice the block into a hand-authored config
without clobbering content outside the markers.

## Transformer contract

> Filled in M5 WP02.

`Waaseyaa\Bimaaji\Install\ClientTransformerInterface` defines:

```php
public function clientId(): string;
public function targetFiles(array $skills): array;
```

`ParsedSkill` and `TargetFile` DTOs accompany the interface. See
`packages/bimaaji/src/Install/` after M5 WP02 lands.

## Flag semantics

| Flag | Mode | Default | Behavior |
|---|---|---|---|
| `--client=<id>` | `Array_` (repeatable, accepts comma-separated values) | (none) | Clients to install for. Comma-separated values are split (`--client=cursor,codex`); repetition accumulates (`--client=cursor --client=codex`). When omitted on an interactive TTY, the command asks `"Install for which client(s)? (comma-separated; available: ...)"`. When omitted on a non-TTY stdin, the command errors with `--client is required when stdin is non-TTY` and exits non-zero. |
| `--features=<csv>` | Required value | `guidelines,skills` | Comma-separated feature filter. Currently advisory; reserved for future-skill-categorisation work. |
| `--dry-run` | Boolean | off | Print the would-be write set as `[DRY-RUN] would write <path> (<bytes> bytes from skill=<source>)` lines without touching the filesystem. Returns exit 0. Per-client summary still reports `written` (would-write count), `unchanged` (sha1 matches existing), `skipped` (sandbox-rejected). |
| `--force` | Boolean | off | Skip every confirmation prompt and overwrite existing files unconditionally. Required when running non-interactively against a project that has a diverging existing target file — without `--force` on non-TTY stdin, the command errors and exits non-zero rather than silently overwriting. |

Exit codes:

- `0` — every requested client installed cleanly (writes, no-ops, or successful overwrites).
- `1` — at least one error occurred during the run: an unknown client (Levenshtein suggestion in stderr), a sandbox rejection, a non-interactive overwrite-needed failure (`--force` absent + non-TTY + diverging existing file), or a write failure (permission denied / disk full).

The per-client summary line is always printed regardless of exit code:
`Client <id>: X written, Y unchanged, Z skipped.`

## Interactive UX

The shipped surface uses the framework's `CliIO::ask()` + `confirm()`
prompts — a deliberate scope reduction from the original `[o]verwrite
/ [s]kip / [d]iff / [a]ll` plan in the WP01 scaffold. Two prompts:

1. **Client selection** — when `--client` is omitted on a TTY:

   ```
   Install for which client(s)? (comma-separated; available: claude, codex, copilot, cursor, gemini, junie, windsurf)
   ```

   An empty or whitespace-only answer exits with a `no clients
   selected; nothing to do` message and exit code 1.

2. **Overwrite confirmation** — when an existing target file
   diverges from the would-be content and `--force` is unset:

   ```
   Overwrite <path>? [yes/no]
   ```

   Default is `no`. Answering `no` increments the per-client `skipped`
   counter (no overwrite, no errors). Answering `yes` writes the new
   content.

Non-TTY stdin (CI, scripts, piped invocations):

- Client selection without `--client` is a hard error (exit 1).
- Diverging-file overwrite without `--force` is a hard error per
  target (exit 1 at end of run via the per-client errors counter).
- Dry-run and identical-file-no-op cases still work non-interactively.

The reduced prompt surface is documented as the shipped contract;
a richer `[o]verwrite / [s]kip / [d]iff / [a]ll` flow can land later
once a real consumer asks for it.

## Adding a new client

> Filled in M5 WP05 (`tasks/WP05-docs-and-verify.md`).

The five-step extension guide:

1. Implement `ClientTransformerInterface` in `packages/bimaaji/src/Install/Client/<NewClient>ClientTransformer.php`.
2. Add a per-client unit test mirroring the existing ones.
3. Add a row to §"Supported clients" with the target path + citation URL.
4. Add a row to `InstallCommandTest`'s `#[DataProvider]`.
5. Bump CHANGELOG `[Unreleased]`.

## Trust contract

The command never:

- Writes outside the consumer project root. The textual guard rejects
  absolute paths and `..` traversals before any write happens; the
  nearest-existing-ancestor realpath check catches symlink-based
  escapes that get past the textual guard. (NFR-002.)
- Overwrites a hand-edited consumer file without `--force` or an
  explicit `yes` answer to the interactive `Overwrite <path>?` prompt
  (C-002).
- Makes any network call (C-004 — no telemetry, no downloads).
- Paraphrases or rewrites skill body content (C-003 — structural
  transformation only; multi-file Claude transformer adds frontmatter
  + per-skill index entries, single-file transformers add a prelude +
  begin/end markers).

## Implementation Status (M5 close-out, 2026-05-23)

| Concern | Resolution |
|---|---|
| Skill source schema | Audited (WP01); seven kebab-case skill directories at `skills/waaseyaa/` ship with the required `name` + `description` frontmatter. |
| Seven client transformers | Shipped (WP02) — see [Supported clients](#supported-clients) above. |
| CLI command + flags + prompts | Shipped (WP03) — `Waaseyaa\Bimaaji\Command\BimaajiInstallCommand`. |
| Sandbox + exit-code propagation | Shipped (WP04) — three integration-level escape attempts rejected; per-client errors counter feeds the overall exit code. |
| Doctrine spec + README + verification log | Shipped (WP05 — this commit). |

PR provenance: `#1557` (WP02), `#1563` (WP03), `#1564` (WP04), and
this WP05 PR. Full verification artifact:
`kitty-specs/bimaaji-install-command-01KS5W0S/verification.md`.

<!-- Spec reviewed 2026-05-23 — bimaaji-install-command-01KS5W0S (WP05 close-out): filled in Supported clients table, Flag semantics, Interactive UX, Trust contract details; added Implementation Status section. WP01 scaffold sections superseded by shipped reality. -->
<!-- Spec reviewed 2026-05-22 — bimaaji-install-command-01KS5W0S (WP01 scaffold). -->
