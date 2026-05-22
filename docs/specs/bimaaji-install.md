# Bimaaji install command (`bin/waaseyaa bimaaji:install`)

> Ships the framework-canonical agent skill pack to consumer projects in
> per-client formats. Lifted in spirit from Laravel Boost's
> `php artisan boost:install`; framework-native.

**Mission:** `bimaaji-install-command-01KS5W0S`
**Status:** Scaffold (M5 WP01). Sections filled in across M5 WP02–WP05.

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

> Filled in M5 WP02 (`tasks/WP02-client-transformers.md`).

Seven launch clients: `claude`, `cursor`, `codex`, `copilot`, `gemini`,
`windsurf`, `junie`. Each row will document the target path, the
expected file format, and a citation URL + date for the source of
truth.

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

> Filled in M5 WP03 (`tasks/WP03-install-command.md`).

`--client=<id>` (multi-value), `--features=guidelines,skills` (CSV),
`--force` (skip overwrite confirmation), `--dry-run` (print intended
writes without writing).

## Interactive UX

> Filled in M5 WP03.

When `--force` is unset and a target file exists with different
content: a `[o]verwrite / [s]kip / [d]iff / [a]ll` prompt with unified
diff display. Non-TTY without `--force` aborts with an explanatory
error and exit code 2.

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

- Writes outside the consumer project root (sandbox check via `realpath()` — NFR-002).
- Overwrites a hand-edited consumer file without `--force` or an explicit `overwrite` prompt response (C-002).
- Makes any network call (C-004 — no telemetry, no downloads).
- Paraphrases or rewrites skill body content (C-003 — structural transformation only).

<!-- Spec reviewed 2026-05-22 — bimaaji-install-command-01KS5W0S (WP01 scaffold). -->
