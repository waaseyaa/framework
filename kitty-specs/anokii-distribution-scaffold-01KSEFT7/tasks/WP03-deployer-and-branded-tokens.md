---
work_package_id: WP03
title: Deployer recipe overlay + branded UX baseline
dependencies:
- WP02
requirement_refs:
- FR-006
- FR-007
- NFR-004
- C-001
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T010
- T011
- T012
- T013
agent: ''
history: []
authoritative_surface: deploy.php
execution_mode: code_change
mission_id: 01KSEFT768GN09JZXHWMAMJNFR
owned_files:
- deploy.php
- config/classification.anokii-default.yaml
- config/tenants/example-nation.yaml.example
- assets/theme/anokii-tokens.css
- CHANGELOG.md
tags: []
wp_code: WP03
---

# WP03 — Deployer recipe overlay + branded UX baseline

**Mission:** `anokii-distribution-scaffold-01KSEFT7`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the Anokii repo

You are working in the Anokii repo clone. WP02 has landed (`.kittify/` and charter exist). All commits go to the Anokii repo, not Waaseyaa.

## THE pattern to mirror (read before writing anything)

- **Framework deployer recipe** — `vendor/waaseyaa/deployer/recipes/waaseyaa.php` (or whatever path `packages/deployer` ships its canonical Caddy + PHP-FPM + systemd atomic-deploy recipe at). Read it end-to-end before authoring the overlay.
- **Framework CLAUDE.md §Code Style** — pins the deep-teal palette to exact hex values `#0d4f4f → #0f766e → #14b8a6`. Your token file MUST contain all three. No drift.
- **Framework `config/` shape** — read any existing classification or tenant config to mirror the YAML shape; do not invent new conventions.

## Subtasks

**T010 — Deployer overlay (`deploy.php`).**
- Path: `deploy.php` at Anokii repo root (Deployer convention).
- Inherits via `require 'vendor/waaseyaa/deployer/recipes/waaseyaa.php';`.
- Overlay points (all minimal; each can be ~3–8 lines):
  - **Storage bucket naming:** `set('storage_bucket', fn($nation, $env) => "anokii-{$nation}-{$env}");` (or the equivalent Deployer-3.x setter API).
  - **Classification-policy seed task:** define a `task('anokii:seed:classification', function () { run('bin/waaseyaa config:import config/classification.anokii-default.yaml'); });` and chain it after the framework's `deploy:writable` task.
  - **Sample tenant config:** the `config/tenants/example-nation.yaml.example` file is shipped in T011 below; the deployer task `anokii:tenant:bootstrap` references it as a template for `cp config/tenants/example-nation.yaml.example config/tenants/<nation>.yaml`.
- Add a header comment block: SPDX-License-Identifier: GPL-2.0-or-later. Reference the mission slug.
- `php -l deploy.php` must lint clean.

**T011 — Branded tokens + supporting config files.**
- `assets/theme/anokii-tokens.css` — single CSS file declaring the deep-teal palette. Exactly these CSS custom properties (no additional properties at this scaffold — keep minimal):
  ```css
  /* Anokii brand tokens — deep teal */
  /* SPDX-License-Identifier: GPL-2.0-or-later */
  :root {
    --anokii-color-primary-900: #0d4f4f;
    --anokii-color-primary-700: #0f766e;
    --anokii-color-primary-500: #14b8a6;
    --color-primary: var(--anokii-color-primary-700);
  }
  ```
- `config/classification.anokii-default.yaml` — minimal Anokii default classification taxonomy seed. Three classification levels: `public`, `community`, `nation-restricted`. Each with `label`, `description`, `default_field_access` (matches framework FieldAccessPolicyInterface semantics: `Neutral` for public, `Neutral` for community, `Forbidden` for nation-restricted on cross-Nation reads).
- `config/tenants/example-nation.yaml.example` — minimal example Nation tenant config stub. Fields: `nation_name: "Pilot Nation A Anishnawbek First Nation"`, `nation_short: "example-nation"`, `language: "oji"` (ISO-639-3 for Ojibwe), `dialect: "southern-ojibwe"`, `oiatc_member: true`, `classification_taxonomy: "anokii-default"`, `storage_bucket: "anokii-example-nation-prod"`, `theme: "anokii-default"`.

**T012 — `CHANGELOG.md` init.**
- Create `CHANGELOG.md` at Anokii repo root. Header `# Changelog`. `## [Unreleased]` section with `### Added` listing the scaffold elements (composer.json + LICENSE.txt + README.md + .gitignore + .kittify init + charter + deployer overlay + branded tokens). Link the mission slug at the bottom.

**T013 — Smoke verifications.**
- `php -l deploy.php` → `No syntax errors detected`.
- `grep -c '#0d4f4f\|#0f766e\|#14b8a6' assets/theme/anokii-tokens.css` → `3`.
- `php -r "var_export(yaml_parse_file('config/tenants/example-nation.yaml.example'));"` (if `php-yaml` is available) OR `python -c "import yaml; yaml.safe_load(open('config/tenants/example-nation.yaml.example'))"` — must parse without error.
- `php -r "var_export(yaml_parse_file('config/classification.anokii-default.yaml'));"` — must parse without error.

## Commits

- `feat(deploy): deployer overlay inheriting Waaseyaa reference recipe (anokii-distribution-scaffold-01KSEFT7)`
- `feat(theme): branded UX baseline — deep-teal tokens (anokii-distribution-scaffold-01KSEFT7)`
- `chore: CHANGELOG.md initialised (anokii-distribution-scaffold-01KSEFT7)`

## Report back with

1. Commit SHAs.
2. Outputs of the four smoke verification commands.
3. Confirmation that the deployer overlay file references the framework recipe path that actually exists in `vendor/waaseyaa/deployer/recipes/` (paste the exact path).
4. The three hex values present in the tokens file (paste the relevant `grep` output line).

## Activity Log

- 2026-05-25T05:09:50Z – unknown – Opus review: new repo waaseyaa/anokii live with composer + LICENSE + README + charter (DIR-A001..DIR-A005) + deploy + branded tokens + Pilot Nation A tenant stub. Repo currently public (consider toggling to private). 10 v0.1 surface seeds left in Waaseyaa artifacts/ for future Anokii-repo re-filing.
