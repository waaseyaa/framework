# Bimaaji install command (`bin/waaseyaa bimaaji:install`)

> Ships the framework-canonical agent skill pack to consumer projects in
> per-client formats. Lifted in spirit from Laravel Boost's
> `php artisan boost:install`; framework-native.

**Mission:** `bimaaji-install-command-01KS5W0S`
**Status:** Shipped (M5 WP01–WP05, 2026-05-23). Source of truth relocated and installation made marker-bounded by #2656 (2026-08-29). All sections below reflect the live `bimaaji:install` surface.

## Overview

A first-party CLI command (`bin/waaseyaa bimaaji:install`) that reads the
canonical Agent Skills shipped **inside the installed `waaseyaa/bimaaji`
package** and writes per-client config files to the consumer project root.
Skill source content is canonical (read, not paraphrased — C-003);
per-client transformation is structural (frontmatter strip, format
conversion).

The trust contract is explicit: the command never overwrites a
hand-edited consumer config file without `--force` or an interactive
`overwrite` prompt (C-002), and since #2656 it does not need to — a
re-run refreshes only the delimited region it owns.

## Skill source resolution

| Order | Source | When |
|---|---|---|
| 1 | `bimaaji.skills_directory` | An application declares its own skill set. |
| 2 | `<installed waaseyaa/bimaaji>/resources/skills` | Default. Resolved from `__DIR__` by `Waaseyaa\Bimaaji\Install\PackagedSkillResources`. |

There is no third fallback. Until #2656 the default was
`<projectRoot>/skills/waaseyaa` — a directory that exists only in the
framework monorepo. A consumer requires `waaseyaa/core`/`cms`/`full` and
never has it, so `bimaaji:install` exited 1 with `no skills discovered`
for exactly the projects the command was written for. Moving the skills
into the component package makes one path correct everywhere:
`packages/bimaaji/resources/skills` in the checkout,
`vendor/waaseyaa/bimaaji/resources/skills` in a consumer.

The monorepo root `skills/waaseyaa/` directory is gone. A single copy is
canonical, so there is no build-time sync step and no freshness gate to
keep honest (contrast `bin/check-admin-dist-fresh`, which exists because
`packages/admin-surface/dist` is a copy).

### Failure diagnostics

`SkillSetParser::parse()` raises `SkillResourceException` rather than
returning an empty list. Two classes, because the remedy differs:

| `SkillResourceFailure` | Raised when | Message names |
|---|---|---|
| `Missing` | The directory is absent, unreadable, or holds no `<id>/SKILL.md`. | The resolved absolute directory, plus `composer reinstall waaseyaa/bimaaji` (packaged default) or the `bimaaji.skills_directory` override to correct. |
| `Corrupt` | A `SKILL.md` exists but cannot be parsed — unterminated YAML frontmatter, unreadable bytes, empty document. | The offending file path and the specific parse failure. |

Every message names the *resolved absolute path*. The pre-#2656 text
named `skills/waaseyaa/<id>/SKILL.md`, which sent consumers looking for a
directory their project never had.

## Marker-bounded installation

Every generated file frames its payload between
`<!-- waaseyaa:bimaaji:install BEGIN -->` and
`<!-- waaseyaa:bimaaji:install END -->`
(`Waaseyaa\Bimaaji\Install\ManagedRegion`).

- **Existing file with one well-ordered marker pair** — only the text
  between the markers is replaced. Every byte outside is carried through
  verbatim, so a consumer's notes above and below the block survive a
  framework upgrade. This path needs no `--force` and no confirmation:
  it cannot touch hand-authored content.
- **Existing file with no markers (or an ambiguous/duplicated pair)** —
  treated as wholly hand-authored. The pre-existing overwrite contract
  applies: interactive `Overwrite <path>?`, or `--force`. The
  non-interactive error tells the operator that adding the marker pair
  around the framework block converts future runs to region refreshes.
- **Claude per-skill files** — Claude Code requires YAML frontmatter at
  byte 0, so the marker pair opens *after* it. The skill body refreshes
  on every run; the consumer's `name`/`description` edits and anything
  they append below the closing marker are preserved.

Idempotency composes with this: the sha1 compare runs against the
spliced payload, so an unchanged managed region still reports
`unchanged` rather than rewriting the file.

## Source schema (audit result, M5 WP01)

All shipped `SKILL.md` documents (audited 2026-05-22 at the then-current
`skills/waaseyaa/`, relocated unchanged to
`packages/bimaaji/resources/skills/` by #2656) carry the following YAML
frontmatter:

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

The audit is no longer implicit. `packages/bimaaji/tests/Architecture/PackagedSkillResourcesTest.php`
parses every shipped document on each run and fails when one has no
name, no description, an empty body, or a non-kebab-case directory id.

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
per project. Both shapes are marker-bounded; see
[Marker-bounded installation](#marker-bounded-installation).

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
| Skill source schema | Audited (WP01), now gated by `PackagedSkillResourcesTest`. The kebab-case skill directories ship at `packages/bimaaji/resources/skills/` (relocated from the monorepo root by #2656) with the required `name` + `description` frontmatter. |
| Seven client transformers | Shipped (WP02) — see [Supported clients](#supported-clients) above. |
| CLI command + flags + prompts | Shipped (WP03) — `Waaseyaa\Bimaaji\Command\BimaajiInstallCommand`. |
| Sandbox + exit-code propagation | Shipped (WP04) — three integration-level escape attempts rejected; per-client errors counter feeds the overall exit code. |
| Doctrine spec + README + verification log | Shipped (WP05). |
| Skill source location | Relocated (#2656) — the canonical set ships at `packages/bimaaji/resources/skills/`; default resolution begins at the installed package; the monorepo-root `skills/waaseyaa/` directory is deleted. |
| Marker-bounded install | Shipped (#2656) — `Waaseyaa\Bimaaji\Install\ManagedRegion`; a re-run refreshes only the delimited region. |
| Missing vs corrupt diagnostics | Shipped (#2656) — `SkillResourceException` + `SkillResourceFailure`. |
| Packaged-form proof | Shipped (#2656) — `tests/PackagedForm/check-bimaaji-skill-resources` (CI job `ci/bimaaji-skill-resources`) drives the command from a consumer built out of the candidate tree with no seeded fixtures. |

PR provenance: `#1557` (WP02), `#1563` (WP03), `#1564` (WP04), the
WP05 close-out PR, and `#2656` (packaged skill resources). Full M5
verification artifact:
`kitty-specs/bimaaji-install-command-01KS5W0S/verification.md`.

<!-- Spec reviewed 2026-08-29 — #2656: skills relocated from the monorepo root into packages/bimaaji/resources/skills, default resolution anchored on the installed package via PackagedSkillResources, install made marker-bounded via ManagedRegion, missing/corrupt diagnostics split via SkillResourceException/SkillResourceFailure. Added Skill source resolution, Failure diagnostics, and Marker-bounded installation sections; corrected the WP01 audit claim (now gated by PackagedSkillResourcesTest). -->
<!-- Spec reviewed 2026-05-23 — bimaaji-install-command-01KS5W0S (WP05 close-out): filled in Supported clients table, Flag semantics, Interactive UX, Trust contract details; added Implementation Status section. WP01 scaffold sections superseded by shipped reality. -->
<!-- Spec reviewed 2026-05-22 — bimaaji-install-command-01KS5W0S (WP01 scaffold). -->
