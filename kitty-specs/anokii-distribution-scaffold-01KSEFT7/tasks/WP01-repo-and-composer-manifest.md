---
work_package_id: WP01
title: GitHub repo creation + initial Composer manifest
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-011
- NFR-001
- NFR-002
- C-001
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-anokii-distribution-scaffold-01KSEFT7
base_commit: 7c70014b76a71cf32cb475cc13b6e3b7e4208ad5
created_at: '2026-05-25T04:56:30.049085+00:00'
subtasks:
- T001
- T002
- T003
- T004
- T005
- T006
agent: ''
shell_pid: '4190488'
history: []
authoritative_surface: composer.json
execution_mode: code_change
mission_id: 01KSEFT768GN09JZXHWMAMJNFR
owned_files:
- composer.json
- LICENSE.txt
- README.md
- .gitignore
tags: []
wp_code: WP01
---

# WP01 — GitHub repo creation + initial Composer manifest

**Mission:** `anokii-distribution-scaffold-01KSEFT7`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — this WP creates a NEW GitHub repo

You are NOT working in a Waaseyaa worktree for this WP's code commits. You are creating a separate GitHub repo (`anokii/anokii` or `<org>/anokii` per T001 decision) and committing the four scaffold files there. The Waaseyaa-side mission lane only records the OUTCOME (repo URL, commit SHAs) in this WP's activity log on completion.

## THE pattern to mirror (read before writing anything)

- **Framework Composer policy** — `bin/check-composer-policy` in the Waaseyaa repo enforces CP002 (no `@dev`), CP003 (no wildcards for `waaseyaa/*`), CP-NEW (caret-bounded internal constraints). Read the script before writing the Anokii `composer.json` so you ship a passable manifest first try.
- **Framework root `composer.json`** — read it; mirror the `config.sort-packages: true` and `prefer-stable: true` shape; mirror author block format; mirror `minimum-stability: alpha` (Waaseyaa is alpha, Anokii inherits the same posture).
- **Framework `LICENSE.txt`** — copy verbatim; do not modify the GPL-2.0-or-later text.
- **Framework metapackages** (`packages/full/composer.json`, `packages/core/composer.json`, `packages/cms/composer.json`) — read them to decide between `waaseyaa/full` (kitchen sink) vs `waaseyaa/core + waaseyaa/cms` (lean). Document your choice in the WP01 commit message.

## Subtasks

**T001 — Org & repo creation.**
- Decide `anokii/` org vs `<existing-waaseyaa-org>/anokii` sibling. Default preference: `anokii/anokii` for clean separation aligned with the framework-vs-distribution doctrine (DIR-004). If creating the `anokii` GitHub org, do so as a Russell-owned org with public visibility.
- `gh repo create anokii/anokii --public --license=GPL-2.0-or-later --description="Anokii — the first opinionated distribution built on Waaseyaa. Sovereign workspace for First Nations. OCAP-by-architecture. Working name pending language-keeper verification." --gitignore=php`
- Verify: `gh repo view anokii/anokii` returns the repo metadata; default branch is `main`.

**T002 — `composer.json`.**
- Author per spec.md FR-002 + plan.md T002 shape. Key fields:
  - `name: "anokii/anokii"`, `type: "project"`, `license: "GPL-2.0-or-later"`, `description` matches repo description.
  - `config.sort-packages: true`, `prefer-stable: true`, `minimum-stability: "alpha"`.
  - `authors`: Russell Jones (jonesrussell42@gmail.com) + "Anokii Project Contributors".
  - `require`: pick (a) `"waaseyaa/full": "^0.1.0-alpha.<latest>"` OR (b) `"waaseyaa/core": "^0.1.0-alpha.<latest>", "waaseyaa/cms": "^0.1.0-alpha.<latest>"`. Document the choice in the commit message with the metapackage-survey reasoning.
  - `require-dev`: `{}`.
  - `autoload`: `{ "psr-4": {} }` (empty; will populate as Anokii packages are added in future missions).
  - `autoload-dev`: `{ "psr-4": {} }`.
  - `scripts`: `{ "post-install-cmd": ["@php -r \"echo 'Anokii installed — see README.md\\n';\""] }` (optional; remove if not desired).
- NO `repositories` section. NO `replace`/`provide` (Anokii is a project, not a metapackage).

**T003 — `LICENSE.txt`.**
- Copy framework `LICENSE.txt` verbatim. Single file.

**T004 — `README.md`.**
- ~3–4 KB. Sections per plan.md T004 shape (What Anokii is / Framework vs distribution / Status / Install / License / Working name / How to contribute).
- Include explicit link to `https://github.com/waaseyaa-org/waaseyaa/blob/main/.kittify/charter/charter.md` (framework charter) and reference this scaffold mission slug in the "How we got here" section.
- Note the deep-teal palette is the brand baseline (visible once admin overlay lands).

**T005 — `.gitignore`.**
- Use PHP gitignore from `gh repo create --gitignore=php` as starting point. Append: `/var/`, `/cache/`, `/storage/`, `/.env`, `/.env.local`, `/.idea/`, `/.vscode/`, `/node_modules/`, `/public/build/`. Do NOT exclude `composer.lock` (per Composer convention for project repos; only library/metapackage repos exclude it).

**T006 — Verify install + policy.**
- In a scratch directory outside the Anokii repo: `mkdir /tmp/anokii-install-check && cp <anokii-repo-path>/composer.json /tmp/anokii-install-check/ && cd /tmp/anokii-install-check && composer install`. Expect success once Packagist has indexed the metapackages. If Packagist has not yet indexed (e.g., the framework release just happened), document this as "verified locally against framework checkout via temporary path-repository override; will re-verify post-Packagist-indexing".
- From a Waaseyaa checkout: `bin/check-composer-policy <anokii-repo-path>/composer.json` (or copy the policy script to the Anokii repo temporarily and run it locally). Must pass.

## Verification gate

1. `gh repo view <org>/anokii` resolves successfully; public visibility; default branch `main`.
2. `git -C <anokii-repo-path> ls-tree HEAD --name-only` shows `composer.json`, `LICENSE.txt`, `README.md`, `.gitignore`.
3. `composer install` in a scratch directory against the Anokii `composer.json` succeeds (or is documented as deferred pending Packagist indexing).
4. `bin/check-composer-policy` (run from Waaseyaa) returns 0 against the Anokii `composer.json`.

## Commit + handoff

- Commits in the Anokii repo:
  - `chore(scaffold): initial composer.json, LICENSE.txt, README.md, .gitignore (anokii-distribution-scaffold-01KSEFT7)`
  - (Optional second commit if README is fleshed out separately) `docs(readme): scaffold-state README explaining framework-vs-distribution position`
- On completion, record in this WP file's activity log:
  - Anokii repo URL.
  - Commit SHAs.
  - `composer install` verification outcome (success / deferred-pending-Packagist).
  - `bin/check-composer-policy` outcome.
  - Choice of `waaseyaa/full` vs `waaseyaa/core + waaseyaa/cms` and reasoning.
- Then move the WP to for_review.

## Report back with

1. Anokii repo URL.
2. Commit SHAs (each commit).
3. `composer install` outcome.
4. `bin/check-composer-policy` outcome.
5. Metapackage choice + reasoning.
