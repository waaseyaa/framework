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

## Ownership and pruning retired targets

Installation used to visit only the *current* target set, so a skill removed
or renamed upstream left its generated file on disk forever: the client kept
discovering retired guidance, and a consumer upgrading across releases
accumulated the union of every skill set they had ever installed.

`Waaseyaa\Bimaaji\Install\InstalledManifest` is the fix. After a non-dry run
the command writes `.waaseyaa/bimaaji-install.json`, recording per client the
exact relative path of every file it generated plus the sha1 of the bytes it
left on disk. The file belongs in version control — it is the provenance for
the generated files committed beside it.

**Ownership is recorded, never inferred.** A `waaseyaa-*` filename is a guess,
and the marker-bounded splice establishes that a generated file can carry
hand-authored content, so a name match is not a licence to delete. A path
absent from the manifest is never touched, whatever it is called.

On each run, a recorded target the current set no longer produces resolves one
of three ways, in descending confidence:

| On-disk state | Outcome |
|---|---|
| sha1 matches the manifest | Nobody has touched it. Delete the file, then remove its directory **only if that leaves it empty** — a skill directory holding supporting files the consumer added stays. |
| sha1 differs, marker pair present | Ours, but edited. Deleting would take hand-authored bytes with it, so the managed region is replaced with a retirement notice and every byte outside the markers is preserved. |
| sha1 differs, no marker pair | Ownership can no longer be demonstrated. The file is left completely untouched and the claim is released, so no future run touches it either. |

"The current set" is what the transformer **declares**, not what the run
managed to write. Deriving it from the write results would make a transient
failure — a permission error, a refused overwrite, a sandbox rejection — look
like an upstream removal, and would make every `--dry-run` report its whole
write set as retired. A declared target that could not be written also keeps
whatever ownership record it already had, so a later run can still prune it
when it genuinely is retired.

`--dry-run` reports every prune and neutralisation and performs none of them,
and does not rewrite the manifest. Installing for one client never forgets
another: `withClient()` replaces one client's record and carries the rest
through.

A missing, unreadable, malformed, or future-schema manifest loads as empty.
The worst outcome is that nothing is pruned this run, which is strictly safer
than guessing at ownership from a file that could not be parsed. The same
property means the first run after adopting this release has nothing to
prune — files generated before the manifest existed carry no ownership
record, and the installer will not delete what it cannot prove it wrote. See
[docs/upgrade-notes/bimaaji-skill-resources.md](../upgrade-notes/bimaaji-skill-resources.md)
for the one-time manual cleanup.

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
| `claude` | `ClaudeClientTransformer` | `.claude/skills/waaseyaa-<id>/SKILL.md` — one **directory** per skill — plus a shared `.claude/CLAUDE-WAASEYAA.md` index | <https://code.claude.com/docs/en/skills> (verified 2026-08-29) |
| `cursor` | `CursorClientTransformer` | `.cursorrules` (single file) — **legacy, see Convention drift** | <https://cursor.com/help/customization/rules> (verified 2026-08-29) |
| `codex` | `CodexClientTransformer` | `AGENTS.md` at the repository root (single file, shared with other AGENTS.md readers) | <https://learn.chatgpt.com/docs/agent-configuration/agents-md>, <https://agents.md> (verified 2026-08-29) |
| `copilot` | `CopilotClientTransformer` | `.github/copilot-instructions.md` (single file) | <https://docs.github.com/en/copilot/how-tos/configure-custom-instructions-in-your-ide/add-repository-instructions-in-your-ide> (verified 2026-08-29) |
| `gemini` | `GeminiClientTransformer` | `GEMINI.md` (single file) | <https://geminicli.com/docs/cli/gemini-md/> (verified 2026-08-29) |
| `windsurf` | `WindsurfClientTransformer` | `.windsurfrules` (single file) — **legacy, see Convention drift** | <https://docs.devin.ai/desktop/devin-desktop-faq> (verified 2026-08-29) |
| `junie` | `JunieClientTransformer` | `.junie/guidelines.md` (single file) — **legacy, see Convention drift** | <https://junie.jetbrains.com/docs/guidelines-and-memory.html> (verified 2026-08-29) |

`ClaudeClientTransformer` is the only multi-file transformer. A Claude
Code project skill is a **directory** — `.claude/skills/<skill-name>/SKILL.md`
— and the command a user types comes from the directory name; the
frontmatter `name` is only the display label. A flat
`.claude/skills/<name>.md` is not a documented layout and is not
discovered, which is exactly what this transformer emitted until the #2656
review: the install reported files written that Claude Code would never
load. The directory-per-skill shape also matches how the canonical set
ships inside the package, so the install is a structural rename rather
than a flattening. The other six clients use the shared
`AbstractSingleFileClientTransformer` base — one consolidated file per
project. Both shapes are marker-bounded; see
[Marker-bounded installation](#marker-bounded-installation).

### Convention drift

Every transformer's target was re-verified against first-party vendor
documentation on 2026-08-29. Two were emitting paths their client does not
read, and both were corrected in #2656:

| Client | Was | Why it was wrong |
|---|---|---|
| `claude` | `.claude/skills/waaseyaa-<id>.md` | Not a discovery layout. A project skill is a directory holding `SKILL.md`. |
| `codex` | `.codex/AGENTS.md` | Not a discovery location at all — it conflated the project scope with the personal `~/.codex/AGENTS.md`. Codex reads a plain root `AGENTS.md`. |

Three more are **documented as legacy by their vendors but still read**, so
they are functional and were deliberately left alone in #2656. Each needs its
own decision rather than a mechanical path swap, and they are tracked
together as follow-up work:

| Client | Current emission | Vendor's current guidance |
|---|---|---|
| `cursor` | `.cursorrules` | `.cursor/rules/*.mdc`, one rule per file, each with `description` / `globs` / `alwaysApply` frontmatter. `.cursorrules` is called legacy and "will be deprecated" but is not removed. |
| `windsurf` | `.windsurfrules` | The product was rebranded to Devin Desktop (Cognition, 2026-06-02); `docs.windsurf.com` now redirects to `docs.devin.ai`. Discovery order is `.devin/rules/*.md`, then `.windsurf/rules/*.md`, then `.windsurfrules` ("legacy… still read"). |
| `junie` | `.junie/guidelines.md` | Priority is `.junie/AGENTS.md`, then root `AGENTS.md` plus `.junie/playbook.md` and `.junie/rules/*.md`, with `.junie/guidelines.md` retained as the legacy fallback. |

Note the interaction the follow-up has to resolve: `codex` now writes the
repository-root `AGENTS.md`, and both Devin Desktop and Junie read that same
file. Migrating Junie to `.junie/AGENTS.md` — or Windsurf to `.devin/rules/`
— has to decide whether those clients keep a private copy or defer to the
shared root file, which is a content-ownership question, not a path swap.

`.github/copilot-instructions.md` and `GEMINI.md` were confirmed current
against first-party docs with no deprecation language.

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
- Modifies any byte a consumer wrote. Inside a managed region the command
  owns the content and says so in the generated prelude; outside it, and
  in a file carrying no markers, nothing changes without `--force` or an
  explicit `yes` to the interactive `Overwrite <path>?` prompt (C-002).
  A marker-bounded refresh is exempt from that prompt precisely because
  it cannot reach hand-authored bytes.
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
| Packaged-form proof | Shipped (#2656) — `tests/PackagedForm/check-bimaaji-skill-resources` (CI job `ci/bimaaji-skill-resources`) drives the command from a consumer built out of the candidate tree with no seeded fixtures, and asserts the exact installed directory structure rather than mere presence. |
| Client convention audit | Re-verified 2026-08-29 (#2656). `claude` and `codex` were emitting paths their client does not read and were corrected; `cursor`, `windsurf` and `junie` are vendor-documented legacy-but-read and are tracked as follow-up. See [Convention drift](#convention-drift). |
| Retired-target pruning | Shipped (#2656) — `InstalledManifest` records ownership; see [Ownership and pruning retired targets](#ownership-and-pruning-retired-targets). |

PR provenance: `#1557` (WP02), `#1563` (WP03), `#1564` (WP04), the
WP05 close-out PR, and `#2656` (packaged skill resources). Full M5
verification artifact:
`kitty-specs/bimaaji-install-command-01KS5W0S/verification.md`.

<!-- Spec reviewed 2026-08-29 — #2656 follow-up review: ClaudeClientTransformer emitted a flat .claude/skills/waaseyaa-<id>.md, which Claude Code does not discover — corrected to the documented .claude/skills/<skill-name>/SKILL.md directory layout, and CodexClientTransformer corrected from the non-existent .codex/AGENTS.md to the repository-root AGENTS.md. Added Convention drift (all seven transformers re-verified; cursor/windsurf/junie recorded as vendor-legacy follow-up) and Ownership and pruning retired targets (InstalledManifest at .waaseyaa/bimaaji-install.json; recorded, never inferred; delete / neutralise / release). -->
<!-- Spec reviewed 2026-08-29 — #2656: skills relocated from the monorepo root into packages/bimaaji/resources/skills, default resolution anchored on the installed package via PackagedSkillResources, install made marker-bounded via ManagedRegion, missing/corrupt diagnostics split via SkillResourceException/SkillResourceFailure. Added Skill source resolution, Failure diagnostics, and Marker-bounded installation sections; corrected the WP01 audit claim (now gated by PackagedSkillResourcesTest). -->
<!-- Spec reviewed 2026-05-23 — bimaaji-install-command-01KS5W0S (WP05 close-out): filled in Supported clients table, Flag semantics, Interactive UX, Trust contract details; added Implementation Status section. WP01 scaffold sections superseded by shipped reality. -->
<!-- Spec reviewed 2026-05-22 — bimaaji-install-command-01KS5W0S (WP01 scaffold). -->
