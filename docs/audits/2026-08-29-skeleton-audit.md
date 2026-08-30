# Skeleton Audit — `skeleton/` (`waaseyaa/waaseyaa`)

**Date:** 2026-08-29
**Scope:** all 51 tracked files under `skeleton/`, plus the root `waaseyaa/framework`
manifest it depends on and the `sync-skeleton` / `ci/skeleton-create-project` path
**Status:** findings only — no code changed

---

## Reconciliation — 2026-08-30 (#2653)

This document was written on 2026-08-29 and never committed. It is committed here
**with its original findings unchanged**, and a `Status (2026-08-30)` block
appended to each one. The original observations are preserved verbatim so the
record stays readable; where a claim was wrong *when written*, the status block
says so rather than editing the claim away.

- **Reconciled against:** `origin/main` at `4cbe2c9bbf956a07c24f9af15245ef9bf27f0228`.
- **Legend:** `FIXED` — the defect is gone and current source proves it.
  `STILL OPEN` — the defect survives, with the issue that owns it where one exists.
  `SUPERSEDED` — the finding no longer describes the system, including the case
  where it never did.
- **Unverified** is stated explicitly where it applies; nothing here is guessed.
- S12–S15 are new: findings discovered *during* the follow-up work that were never
  in the 2026-08-29 pass.
- **Second pass.** S10, S14 and S15 were corrected again after review, because the
  first pass of this reconciliation introduced errors of its own. The same rule
  applies to those: the wrong claim is named and corrected in place, not deleted.
  S15 in particular was wrong twice, and both formulations are recorded there.

---

## Verdict

The skeleton is in good shape structurally: the front controller is a careful
three-runtime adapter, `docs/application-anatomy.md` is better than most
frameworks ship, the distributed `.claude/rules/*` are byte-identical to their
`packages/foundation/.claude/rules/` source, and `golden-public-index.php` is
byte-identical to `public/index.php` so `composer audit-site` passes clean.

Two defects have real consequences (S1, S2). Three more are structural
(S3–S5). The rest is documentation drift.

> **Status (2026-08-30):** S2 and S3 are fixed. S1 is materially narrowed but not
> closed. S4 is split — its blocking half is fixed, its packaging half is not.
> S5 is untouched. Of the documentation drift, S6 and S7 are fixed, S8 was
> incorrect when written, and S9–S11 survive.

---

## S1 — The shipped CI workflow fails on a new project's first push

`skeleton/.github/workflows/site-verify.yml` runs on **every** `push` and
`pull_request` and does exactly:

```
composer install --no-interaction --prefer-dist
.ci/site-verify
```

`.ci/site-verify` is a four-line shim:

```sh
exec "$project_root/bin/maintenance/site-verify"
```

That script **does not exist in the skeleton**. `skeleton/bin/maintenance/`
contains `deploy-artifact-smoke`, `golden-public-index.php`,
`verify-deploy-rsync`, `waaseyaa-audit-site`, `waaseyaa-version` — and nothing
else. `bin/maintenance/site-verify` is *generated* by
`Waaseyaa\SiteContract\Generation\SiteArtifactRenderer` when the operator runs
`vendor/bin/waaseyaa site:init`.

The README's happy path is correct and ordered — `create-project` → `site:init`
→ `.ci/site-verify`. The **workflow** is not: it never runs `site:init`, and
nothing in the skeleton makes the ordering a precondition. So a developer who
scaffolds, commits, and pushes before running `site:init` — or who runs it and
does not commit the generated artifact — gets a red build whose entire output is
the shell's `not found`. No guidance, no mention of `site:init`.

**Fix shape:** guard the shim (`[ -x bin/maintenance/site-verify ] || { echo
"run: vendor/bin/waaseyaa site:init"; exit 1; }`) so the failure names its own
remedy, and/or have the workflow run `site:init` before verifying.

> **Status (2026-08-30): STILL OPEN — materially narrowed by #2644 (`cec48d66f`,
> PR #2684).** The finding was re-evaluated against the new shape, not the old one.
>
> **What changed.** The invocation chain is no longer shell-only. The workflow now
> runs `composer site-verify` (`skeleton/.github/workflows/site-verify.yml:20-21`),
> which resolves through `skeleton/composer.json:37` — `"site-verify": "@php
> .ci/site-verify.php"` — to a PHP entry point. `.ci/site-verify` survives only as
> a POSIX adapter that execs the same file (`skeleton/.ci/site-verify:6`), so the
> audit's quoted four-line shim body is **no longer accurate**: it does not exec
> `bin/maintenance/site-verify` any more. `skeleton/.ci/site-verify.php:38-46`
> makes two `is_file()` checks — `.waaseyaa/site.yaml`, then
> `bin/maintenance/site-verify` — and on a miss prints the exact two-command
> remedy and exits 3 (`skeleton/.ci/site-verify.php:29-36`). It loads no
> autoloader and boots no kernel, so it answers correctly even before
> `composer install` has run (`skeleton/.ci/README.md:24-27`).
> `skeleton/bin/post-create-setup.php:74-78` now prints the ordered lifecycle as
> the first thing a created project ever shows.
>
> **Can a freshly created project commit and push without a red build?** Yes —
> **if and only if it runs `site:init` first and commits what that generates.**
> Neither generated artifact is ignored: `skeleton/.gitignore:1-9` lists
> `/vendor/`, `/storage/framework/`, `/files/*`, `*.sqlite*`, `.env` and
> `composer.local.json` and nothing else, so `.waaseyaa/site.yaml` and
> `bin/maintenance/site-verify` are committable and the workflow then passes.
>
> **Otherwise, no.** Nothing runs `site:init` for the developer: not
> `post-create-project-cmd` (`skeleton/composer.json:43-45` runs only
> `bin/post-create-setup.php`, which generates `.env` and prints instructions),
> and not the workflow, which still goes straight from `composer install` to
> verification. Exit 3 is a non-zero exit and GitHub Actions renders it red.
>
> **So the finding's title still holds; its evidence does not.** The first half of
> the stated fix shape — guard the shim so the failure names its own remedy — is
> exactly what shipped, and shipped better than sketched: portable PHP rather than
> a `[ -x ]` test, and exit 3 with a documented meaning rather than exit 1. The
> second half — have the workflow run `site:init` before verifying — did not ship,
> and that is the entire residue. A first push before initialization is still a
> red build, now a *legible* one. The residue is owned by **#2665** acceptance
> item 7 ("Pass first-commit CI and `ai:verify` on the supported OS matrix"), with
> the lifecycle that would discharge it in **#2664** (`project:init`).

---

## S2 — The Dockerfile bakes `.env` into the production image

`skeleton/.dockerignore`:

```
.git
.github
node_modules
vendor
storage/*.sqlite
tests
phpunit.xml.dist
*.md
.claude
.cursor
```

`.env` is **not** excluded, and the production stage does `COPY . /app`.
`bin/post-create-setup.php` writes `.env` at `create-project` time containing a
freshly generated `WAASEYAA_JWT_SECRET` (32 random bytes, hex) and
`WAASEYAA_APP_SECRET` (`base64:` + 32 random bytes). Both land in an image layer,
readable by anyone who can pull the image or read the layer cache.

**What is *not* wrong here**, checked rather than assumed: the same `.env` also
carries `APP_ENV=local`, `APP_DEBUG=true`, and
`WAASEYAA_DEV_FALLBACK_ACCOUNT=true`, and none of those take effect.
`EnvLoader::load()` mirrors the process environment into `$_ENV` before calling
Symfony Dotenv, so externally injected values win against files — the
Dockerfile's `ENV APP_ENV=production` / `APP_DEBUG=false` hold. And
`HttpKernel::shouldUseDevFallbackAccount()` is triple-gated (SAPI ∈
{`cli-server`, `frankenphp`} **and** `isDevelopmentMode()` **and** the explicit
`auth.dev_fallback_account` opt-in), so the dev admin stays locked regardless.

The exposure is secret material in a distributable artifact, not an auth bypass.

**Fix shape:** add `.env`, `.env.local`, `composer.local.json`, and
`storage/files` to `.dockerignore`.

> **Status (2026-08-30): FIXED — #2647 (`cd9af6138`, PR #2671).**
> `skeleton/.dockerignore:12-26` now excludes `.env`, `.env.*`, `**/.env` and
> `**/.env.*` at the build-context level — the boundary no later Dockerfile
> instruction can reach behind — with `!.env.example` and `!**/.env.example`
> deliberately last, because in `.dockerignore` the last matching pattern wins.
>
> The exclusion is gated, not merely written. `bin/check-skeleton-docker-secret-exclusion`
> is executable in the repository and runs in CI at
> `.github/workflows/ci.yml:1112-1113`; the built image is asserted directly at
> `.github/workflows/ci.yml:1049`
> (`test ! -e /app/.env && test -e /app/.env.example`), and the saved layers are
> scanned at `.github/workflows/ci.yml:1052-1065`.
>
> Two deviations from the stated fix shape, both deliberate. `composer.local.json`
> and `storage/files` were not added: `storage/*.sqlite` already covers the
> database, and the secret-bearing file was the actual finding. Both remain
> candidates for the canonical three-surface exclusion policy in **#2648**.

---

## S3 — `HomeController` bypasses SSR and Twig

`AppServiceProvider::routes()` binds `/` to `HomeController::index()`, which does:

```php
$template = dirname(__DIR__, 2) . '/templates/home.html.twig';
$html = (string) file_get_contents($template);
return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
```

A `.twig` file read as bytes and returned to the browser. Meanwhile
`packages/ssr/src/RenderController.php:71` already resolves `home.html.twig`
through the real Twig environment, and `packages/ssr/tests/Unit/HomepageTemplateTest.php`
renders it that way.

So the skeleton ships **two competing homepage renderers**, and the app-level one
wins on `/` — bypassing the SSR theme, cache-max-age, locale negotiation, and
error handling. It works today only by accident: `home.html.twig` contains zero
Twig tags. The sibling templates do — `page.html.twig` and `404.html.twig` each
contain one — and are reachable only through the SSR path. The first developer
who adds `{{ site_name }}` to the homepage gets it echoed literally.

Secondary: `file_get_contents` is unchecked; a missing or unreadable template
yields a warning and a 200 with an empty body rather than a 500.

**Fix shape:** either render through the SSR controller (delete the route and let
`waaseyaa/ssr` own `/`), or rename the file to `.html` so it stops advertising a
templating engine that never runs.

> **Status (2026-08-30): FIXED — #2651 (`e546d1ffc`, PR #2672).** The first
> option was taken. `skeleton/src/Controller/` now holds only `.gitkeep`;
> `HomeController` is gone. `skeleton/src/Provider/AppServiceProvider.php:17-27`
> carries a docblock recording *why* the homepage route is absent — the
> framework's `public.home` route binds `/` to the SSR render pipeline, and the
> application template outranks the framework's copy in the theme chain — and
> `routes()` now registers only the clean-URL probe
> (`skeleton/src/Provider/AppServiceProvider.php:28-42`).
>
> The renderer the finding named as the correct one is still live:
> `packages/ssr/src/RenderController.php:71` still pushes `home.html.twig` onto
> the template candidate list, and all three templates remain under
> `skeleton/templates/`. The secondary unchecked `file_get_contents` went away
> with the controller.

---

## S4 — A created project gets `bimaaji` but nothing that can reach it

Computed transitive closure of `waaseyaa/framework`'s `require` graph: **63
packages**. Not reachable:

```
ai-agent  ai-observability  cms  core  engagement  full
genealogy  mcp  messaging  oidc  testing  wayfinding  workspace
```

(`cms`/`core`/`full` are metapackages and `genealogy`/`testing` are correctly
excluded — the rest are not obviously intentional.)

Consequences for the skeleton specifically:

- `waaseyaa/bimaaji` **is** installed, but its five `#[AsAgentTool]` adapters
  live in `packages/ai-agent/src/Tool/Bimaaji/`, which is not. And
  `waaseyaa/mcp` is not installed, so there is no `/mcp` endpoint to call them
  through. Bimaaji arrives as a library reachable only via `graph:dump`.
- `bimaaji:install` is registered and will run — and fail. It resolves
  `<projectRoot>/skills/waaseyaa`, which no created project has, and exits 1
  with `no skills discovered`. (Cross-reference: D1 in
  `2026-08-29-boost-parity-agent-surface.md`. The skeleton is where that defect
  is felt.)
- The skeleton has no `.claude/skills/` directory, so nothing has prepared a
  home for the output either.
- `waaseyaa/oidc` being outside the closure is worth a second look, since
  `Waaseyaa\Routing\AuthOidcRouteServiceProvider` (in the closure) exists to wire
  its routes.

> **Status (2026-08-30): SPLIT — the blocking bullet is FIXED, the rest is STILL OPEN.**
>
> - **`bimaaji:install` failing in a created project — FIXED (#2656, `aa0d70d2f`,
>   PR #2683).** Resolution no longer guesses at a project root.
>   `packages/bimaaji/src/BimaajiServiceProvider.php:417-425` falls back to
>   `PackagedSkillResources::directory()`, which anchors on `__DIR__`
>   (`packages/bimaaji/src/Install/PackagedSkillResources.php:39-42`), so the same
>   path resolves in the monorepo and in `vendor/waaseyaa/bimaaji`. All eleven
>   skills ship as package resources under `packages/bimaaji/resources/skills/`
>   (`access-control`, `admin-spa`, `ai-integration`, `api-layer`,
>   `app-development`, `entity-system`, `framework-extraction`, `infrastructure`,
>   `mcp-endpoint`, `middleware-pipeline`, `spec-maintenance`), the monorepo-root
>   `skills/` directory is deleted, and freshness is now a **required** check —
>   `ci/bimaaji-skill-resources` (`.github/workflows/ci.yml:1383`), confirmed
>   present in the `main-protection` ruleset's required-status-check roster.
>   `SkillSetParser` also stopped failing silently: a missing directory or an
>   unreadable document raises `SkillResourceException`
>   (`packages/bimaaji/src/Install/SkillSetParser.php:27-31`).
> - **The `.claude/skills/` bullet — SUPERSEDED.** It was never a defect: the
>   install command creates its own target directory. `skeleton/.claude/` still
>   ships only `rules/`, and that is correct.
> - **No transport that can reach bimaaji — STILL OPEN.**
>   `packages/ai-agent/src/Tool/Bimaaji/` still holds the same five adapters, and
>   `waaseyaa/ai-agent`, `waaseyaa/mcp` and `waaseyaa/wayfinding` are still
>   `require-dev` in the root manifest (`composer.json:397-405`), so a created
>   project does not get them. Owned by **#2655** (the `waaseyaa/ai-development`
>   metapackage), **#2657** (transport-neutral registry bridge) and **#2659**
>   (local stdio server).
> - **`waaseyaa/oidc` outside the closure — STILL OPEN, intent still unresolved.**
>   `composer.json:403` keeps it in `require-dev`. Whether that is deliberate is
>   **unverified**: `changes/unreleased/2643.oidc-readme-status.fixed.md` records
>   the same placement as a deviation from ADR-006 §7 without settling it.

---

## S5 — `waaseyaa/framework` has no archive exclusions

`.gitattributes` contains **zero** `export-ignore` entries and the root
`composer.json` has no `archive.exclude`. The Packagist dist tarball is therefore
the entire tracked monorepo — ~11 MB, 7,744 files — copied into every consumer's
`vendor/waaseyaa/framework/`:

| Directory | Tracked size | Files | Reaches the consumer as |
|---|---|---|---|
| `kitty-specs/` | 10.6 MB | 1,207 | retired, read-only Spec Kitty mission history |
| `docs/` | 8.5 MB | 485 | useful, but see the note below |
| `packages/` | 5.7 MB | 5,295 | a **second complete copy** of every package already installed at `vendor/waaseyaa/<pkg>/` |
| `tests/` | 4.1 MB | 412 | framework test suites |
| `bin/`, `tools/`, `.github/` | 1.5 MB | 147 | release/CI machinery |

`kitty-specs/` alone is the single largest directory and is explicitly retired
history per `CLAUDE.md`. Shipping `packages/` inside `framework` is the worst of
it: it duplicates the whole installed tree and gives PSR-4 scanners a second set
of paths to trip over.

One caveat before acting: `docs/specs/` is the corpus the Boost-parity audit
wants to *deliberately* ship (D3 there). Exclude it only if that work moves the
specs into a package resource first — otherwise `export-ignore` on `docs/` closes
a door we are about to want open.

**Fix shape:** `.gitattributes` `export-ignore` for `kitty-specs/`, `tests/`,
`packages/`, `.github/`, `tools/`, `skeleton/`, `.worktrees/`.

> **Status (2026-08-30): STILL OPEN — owned by #2650, with #2648 as its policy
> sibling.** Re-verified: `.gitattributes` is 91 lines of `text eol=lf` and
> `linguist-language` normalization and contains **no** `export-ignore` entry;
> the root `composer.json` has no `archive` key. Nothing changed.
>
> The caveat the finding raised turned out to be the right call, and #2650
> encodes it: its acceptance requires that "raw docs and compiled agent corpus
> have separate ownership", and it closes with "do not delete repository history
> or exclude docs merely to reduce size" — so the `docs/` exclusion the fix shape
> already omitted stays omitted on purpose, pending the corpus compiler in
> **#2661**. #2650 also adds a control the fix shape did not: an allowlist with
> compressed and expanded size budgets that fail closed, rather than a denylist
> that silently stops matching when a directory is renamed.
>
> The byte and file counts in the table above were **not re-measured** in this
> pass and should be read as of 2026-08-29.

---

## S6–S11 — Documentation and consistency drift

| # | Finding | Evidence |
|---|---|---|
| S6 | README's Directory Structure documents `bin/dev` — "Cross-platform FrankenPHP dev launcher (`composer run dev`)". No such file. The `dev` script actually runs `@php vendor/bin/waaseyaa dev`, a command owned by `waaseyaa/frankenphp` (`Command/DevCommand.php`). | `skeleton/README.md`, `find skeleton/bin` |
| S7 | `skeleton/CLAUDE.md` still carries `<!-- Note: waaseyaa:* skills are placeholders. They will not function until the skills are built. -->`. Eleven skills exist under `skills/waaseyaa/` and `bimaaji:install` ships them. The same orchestration table routes `src/Provider/**` to a `feature-dev` skill that does not exist, and `docs/specs/**` to a directory the skeleton does not have. | `skeleton/CLAUDE.md`, `ls skills/waaseyaa` |
| S8 | `docs/application-anatomy.md` instructs "Keep application migrations in `migrations/`". The skeleton ships no `migrations/` directory and no `.gitkeep` for one, while providing `.gitkeep` for eight other `src/` directories. | `skeleton/docs/application-anatomy.md`, `find skeleton -name .gitkeep` |
| S9 | `extra.merge-plugin.include` lists `composer.site-recipes.json` and `composer.subscription-recipe.json`, neither of which exists until `site:init` generates them. Harmless — merge-plugin's `include` tolerates missing files, unlike `require` — but undocumented anywhere in the skeleton. | `skeleton/composer.json` |
| S10 | `post-create-setup.php` does `$content = file_get_contents($envExample)` with no `false` check, then `str_replace(..., $content)`. On an unreadable `.env.example` that is a PHP 8.5 deprecation and an empty `.env`, silently. | `skeleton/bin/post-create-setup.php` |
| S11 | `phpunit.xml.dist` sets no `failOnWarning`, `failOnRisky`, or `failOnDeprecation`. New projects inherit a laxer test posture than the framework's own. | `skeleton/phpunit.xml.dist` |

### S6 — FIXED — #2644 (`cec48d66f`, PR #2684)

The finding was **correct when written**, and establishing that took a second
pass. A `git log -S 'bin/dev'` pickaxe over `skeleton/README.md` returns nothing,
because the string never appeared literally — the directory tree rendered it as a
`dev` row nested under a `bin/` heading. Reading the file at the commit that was
`main` on 2026-08-29 shows the row verbatim:

```
bin/
├── dev                  Cross-platform FrankenPHP dev launcher (`composer run dev`)
├── post-create-setup.php  One-time setup after `create-project`
└── maintenance/         Audit/release helpers (optional for beginners)
```

It is gone. `skeleton/README.md:57-64` now lists `bin/post-create-setup.php` and
`bin/maintenance/`, plus a new `.ci/` block, and `skeleton/bin/` on disk contains
exactly `maintenance/` and `post-create-setup.php`.

**The correct command is `composer run dev`.** It resolves through
`skeleton/composer.json:38-41` — `"dev": ["Composer\\Config::disableProcessTimeout",
"@php vendor/bin/waaseyaa dev"]` — to `Waaseyaa\FrankenPHP\Command\DevCommand`
(`packages/frankenphp/src/Command/DevCommand.php:32`), owned by the optional
`waaseyaa/frankenphp` package the skeleton installs by default. So the README's
*documented command* was right all along; what was wrong was the tree claiming a
`bin/dev` file as its entry point. There is no supported `bin/dev`, and there is
no longer a claim that there is one.

### S7 — FIXED in both main claims; one sub-claim STILL OPEN — #2656 (`aa0d70d2f`, PR #2683)

Both claims were correct when written: `skeleton/CLAUDE.md` as of 2026-08-29
carries the placeholder note verbatim and routes `src/Provider/**` to a bare
`feature-dev`.

- The placeholder comment is **gone**. `skeleton/CLAUDE.md:106-117` now tells the
  reader how to install the skills (`bimaaji:install --client=claude`), states
  that they ship as resources of the installed `waaseyaa/bimaaji` package, names
  the `.claude/skills/waaseyaa-<id>/SKILL.md` landing path, and explains the
  marker-bounded re-run that preserves surrounding hand-authored content.
- `feature-dev` is **gone**. `skeleton/CLAUDE.md:102` routes `src/Provider/**` to
  `waaseyaa-infrastructure`, which exists at
  `packages/bimaaji/resources/skills/infrastructure/`.
- **STILL OPEN (minor):** `skeleton/CLAUDE.md:104` still routes `docs/specs/**`,
  and a created project has no `docs/specs/` — `skeleton/docs/` ships
  `application-anatomy.md` and `local-dev.md` only. It is now *explained* rather
  than silent (`skeleton/CLAUDE.md:121`: specs "ship in the `waaseyaa/framework`
  repo under `docs/specs/`"), but the row still points at a path the project does
  not have. Closing it properly depends on the corpus work in **#2661** / **#2662**.

### S8 — SUPERSEDED — incorrect when written

This finding was wrong on 2026-08-29, and that is worth stating plainly rather
than quietly dropping. It quoted line 36 of `skeleton/docs/application-anatomy.md`
in isolation and concluded the document told readers to keep migrations in a
directory the skeleton never creates. The same document already said the opposite
twice, and had said so since `74dd4ffd2` (2026-08-26 — three days before the
audit):

- `skeleton/docs/application-anatomy.md:100-101` — "The `migrations/` directory
  is created by `make:migration` on first use."
- `skeleton/docs/application-anatomy.md:135` — "`migrations/` — created on first
  `make:migration` invocation."

The reasoning behind the finding was also unsound. Git does not track empty
directories, so neither a tree listing nor a `.gitkeep` census can establish that
a directory is missing at runtime — only behaviour can. Behaviour is correct in
both directions:

- **Creation.** `packages/cli/src/Handler/MakeMigrationHandler.php:165-168` does
  `if (!is_dir($targetDir)) { mkdir($targetDir, 0o755, true); }` against
  `$this->projectRoot . '/migrations'`; the package-targeted path does the same at
  `:73-74`, and `packages/cli/src/Handler/MakeStorageMigrationHandler.php:109`
  shares the convention.
- **Tolerance of absence.** `packages/foundation/src/Migration/MigrationLoader.php:81-85`
  reads `$this->basePath . '/migrations'` through `loadFromDirectory()`, which
  returns `[]` when the directory does not exist
  (`packages/foundation/src/Migration/MigrationLoader.php:246-248`) — so the
  migration runner works on a project that has never run `make:migration`.

A `.gitkeep` for `migrations/` would be harmless but buys nothing. No defect exists.

### S9 — STILL OPEN, partially addressed

`skeleton/composer.json:47-55` still lists `composer.site-recipes.json` and
`composer.subscription-recipe.json` under `extra.merge-plugin.include`, and both
are still generated only by `site:init`
(`packages/cli/src/Site/Recipe/PublishedContentRecipe.php:57`,
`packages/cli/src/Site/Recipe/SubscriptionRecipe.php:50`). The harmlessness
claim holds.

What changed is the third entry in that list: `composer.local.json` is now
documented at `skeleton/docs/local-dev.md:9` — "The app loads
`composer.local.json` through `wikimedia/composer-merge-plugin`, and
`prepend-repositories: true` makes local path repositories win over Packagist
during development." The two recipe fragments remain undocumented anywhere under
`skeleton/`; a search for either filename across the skeleton's Markdown returns
nothing. Unfiled.

### S10 — STILL OPEN

Unchanged. `skeleton/bin/post-create-setup.php:9-11`:

```php
if (!file_exists($envFile) && file_exists($envExample)) {
    $content = file_get_contents($envExample);
```

`$content` is passed straight into `str_replace()` at `:17-18` with no `false`
check. The unchecked read is real and the finding stands.

**Two corrections to the original write-up, and to the first pass of this
reconciliation, which repeated the second one.**

First, the trigger. `file_exists()` at `:9` already guards the *missing* case, so
reaching the unchecked read needs a file that exists but cannot be read —
permissions, or an I/O error.

Second, and more consequentially, **the stated outcome is wrong**. The audit said
the failure produces "a PHP 8.5 deprecation and an empty `.env`, silently", and
the first pass of this reconciliation carried that forward as "a silently empty
`.env`, and therefore an application with no generated secrets". Neither is what
happens. `skeleton/bin/post-create-setup.php:3` declares `strict_types=1`, and the
first call to consume `$content` is `str_replace()` at `:17` — which runs
*before* `file_put_contents($envFile, $content)` at `:24`. Under strict types,
`str_replace()`'s `$subject` parameter is `array|string`, so `false` throws
immediately:

```
PHP Fatal error:  Uncaught TypeError: str_replace(): Argument #3 ($subject)
must be of type array|string, false given
```

Confirmed by execution on PHP 8.5.8: uncaught `TypeError`, exit status 255. The
script therefore **aborts setup loudly**, and because it runs as
`post-create-project-cmd` (`skeleton/composer.json:43-45`) Composer reports the
failed script rather than completing quietly. No empty `.env` is written, because
control never reaches `:24`.

So the defect is a missing `false` check that converts an I/O failure into an
unhandled `TypeError` several lines away from its cause — a legibility problem,
not a silent-corruption one. That is a materially smaller defect than the audit
claimed, and the smaller claim is the true one. Unfiled.

### S11 — STILL OPEN

Unchanged and re-verified. `skeleton/phpunit.xml.dist` is 22 lines carrying
`bootstrap`, `colors`, `cacheDirectory`, two testsuites and a `<source>` block —
no `failOnWarning`, no `failOnRisky`, no `failOnDeprecation`. Unfiled.

---

## S12–S15 — Found during the follow-up work (new, not in the original pass)

These were discovered while acting on S1–S11 and were never findings in the
2026-08-29 audit. They get the same treatment.

### S12 — The skeleton production Dockerfile had never been built at all — FIXED — #2673 (`b96c7c95a`, PR #2698)

The original audit checked `.dockerignore` (S2) but not whether the image that
`.dockerignore` protects could be produced. It could not. Three independent build
failures were stacked in one `RUN`:

- `intl` cannot compile against `icu-libs` alone — that package carries only the
  shared objects, so `configure` aborted with `Package requirements (icu-uc >=
  57.1 icu-io icu-i18n) were not met`. It needs `icu-dev` **and** a C toolchain
  (`$PHPIZE_DEPS`, exported by the official PHP images).
- `docker-php-ext-install opcache` is a hard failure on `php:8.5-fpm-alpine`, not
  a no-op: OPcache is statically linked into that image's binary, so there is no
  shared module to install and the step dies on `cp: can't stat 'modules/*'`.
- `ext-zip` is a runtime requirement of `waaseyaa/structured-import`, so without
  it the `deps` stage's `composer install --no-dev` refuses the lock file.

The repair is `skeleton/Dockerfile:30-45`: runtime libraries installed as explicit
non-virtual packages first, build headers and toolchain under a `.build-deps`
virtual package deleted **inside the same RUN** — splitting the delete into its
own `RUN` would leave every build-only byte committed in the earlier layer — and
five assertions that run inside the image being built (`php -m | grep -qx` for
`intl`, `zip`, `pdo_sqlite` and `Zend OPcache`, plus a live `Collator`
comparison), so a future base image that stops bundling OPcache fails the build
loudly instead of degrading silently.

It is now proven rather than asserted. `ci/skeleton-create-project` builds
`--target production` and runs the image
(`.github/workflows/ci.yml:1034-1065`), with a guarded skip on release-cut refs
that fails if `skeleton/Dockerfile` changed in a release commit
(`.github/workflows/ci.yml:1018-1028`) — because a release-cut consumer resolves
`waaseyaa/*` from host path repositories that do not exist inside the build
container.

### S13 — The production image speaks FastCGI while everything else in the skeleton is FrankenPHP — STILL OPEN — #2702

Surfaced while repairing S12 and deliberately **not** changed there: repairing a
build that never worked is a bug fix, whereas changing what the image serves is a
deployment-contract migration and must not ride along with one.

`skeleton/Dockerfile:1` builds on `php:8.5-fpm-alpine` and `:71` ends with
`EXPOSE 9000`. That image answers FastCGI and nothing else, and the skeleton ships
no web-server configuration to sit in front of it. Everything else assumes
FrankenPHP: `composer run dev` routes to `waaseyaa/frankenphp`
(`skeleton/composer.json:38-41`), `skeleton/README.md` has a "Serving with
FrankenPHP" section as *the* documented serving path including worker mode,
`config/frankenphp/Caddyfile` and `config/frankenphp/php.ini` are committed, and
`skeleton/public/index.php`'s worker-mode branch keys off the
`WAASEYAA_FRANKENPHP_WORKER` marker that Caddyfile sets. A consumer who follows
the documentation locally and then builds the shipped Dockerfile gets a production
image that does not serve, on a runtime they never tested against.

#2702 is a decision issue: compare FPM compatibility against a self-serving
FrankenPHP default, and do not assume the answer.

### S14 — The Dependabot admin-dist rebuild's privileged job ran an unpinned PHP that could not parse the verifier — FIXED — #2704 (`7cc9dfcba`, PR #2705)

Every admin dependency bump was blocked. `dependabot-admin-dist.yml`'s `publish`
job — the one holding `contents: write` under `pull_request_target` — ran
`php bin/admin-dist-acceptance verify` with **no `setup-php` step**, so it
executed whatever interpreter the runner ships by default.

**Wording correction.** The first pass of this reconciliation said the job "ran
`php` with no interpreter", and #2704's own title says the same. That is
inaccurate, and the shipped source says so precisely
(`.github/workflows/dependabot-admin-dist.yml:83-86`): the job "executed the
runner's default interpreter and died parsing this repository's PHP 8.4+ syntax".
PHP was present. Its **version** was unsuitable. The specific construct is
`bin/admin-dist-acceptance:73`:

```php
new AdminDistWorkspaceGuard()->assertAcceptable($root, array_values($porcelain));
```

`new` without parentheses before a method call is PHP 8.4+ syntax, so an older
interpreter fails at *parse* time — before any logic runs, and with an error that
names a syntax problem rather than a version mismatch.

The repair is a three-job split rather than adding `setup-php` to the privileged
job: `build` (`.github/workflows/dependabot-admin-dist.yml:14`), `validate`
(`:90`), `publish` (`:188`). That is the better shape for a second reason the
issue title does not carry: it **keeps the pinned interpreter, and the
third-party action that installs it, out of a `contents: write` job running under
`pull_request_target`**. `build` and `validate` run at `contents: read`
(`:19-20`, `:93-94`) and own the PHP toolchain, pinned to `php-version: '8.5'`
(`:35-37`, `:123-125`); `publish` holds the write credential (`:205-206`) and
sets up no PHP by design, the workflow being explicit that "adding setup-php to
`publish` would put a third-party action inside the privileged boundary; that
separation is the security property" (`:87-89`). Verification now happens in a
clean checkout rather than where the bundler ran.

### S15 — A `GITHUB_TOKEN`-authored rebuild leaves its own `pull_request` runs awaiting approval, and the dispatch workaround measures less — STILL OPEN — #2707

**This finding was wrong in its first two formulations, and the corrections are
the substance of it.** Recording them, rather than editing them away, is the
point of the exercise.

**The original framing was false.** It was handed to this reconciliation as "a
rebuild that commits via `GITHUB_TOKEN` cannot report the `pull_request` checks
its own PR requires". Checks are not structurally unreportable.

**The first rewrite was better but still wrong.** It kept a "three workflows are
never re-run at all" bullet naming `admin.yml`, `surface-parity.yml` and
`changelog-discipline.yml`, and it listed `spec-drift` as a required gate. All
four of those claims are incorrect. What is actually true:

- **The `pull_request` runs exist; they sit at `action_required`, awaiting
  approval.** GitHub documents that a workflow-created or workflow-updated PR
  using `GITHUB_TOKEN` produces approval-required `opened` / `synchronize` /
  `reopened` `pull_request` runs. Once approved, they complete normally. The
  evidence is direct: run **33287914400** is `name=Admin SPA`,
  `event=pull_request`, `status=completed`, `conclusion=success`,
  `head_sha=2a05e10d180f804d449b95540261958fd0d23262` — that is `admin.yml`
  running on a rebuilt Dependabot head, successfully, on the `pull_request`
  event. `changelog-discipline` likewise succeeded on that SHA after approval.
  So approval, not impossibility, was the blocker.
- **`admin.yml` and `changelog-discipline.yml` do re-run.** They were pending
  approval, not skipped. The earlier bullet inferred "never re-run" from their
  lacking a `workflow_dispatch` trigger, which does not follow.
- **`surface-parity.yml` correctly does not run.** It carries path filters
  (`.github/workflows/surface-parity.yml:14-23`: `packages/**/src/**`,
  `packages/**/testing/**`, `src/**`, the surface maps, `CHANGELOG.md`,
  `changes/unreleased/**`, the checker, and itself). An admin dependency bump and
  its `packages/admin-surface/dist/**` rebuild match none of them. Its silence is
  designed behaviour, not a gap.
- **`spec-drift` is not a required context.** The `main-protection` ruleset's
  required-status-check roster holds 22 contexts, and `spec-drift` is absent from
  it (verified via `gh api repos/waaseyaa/framework/rulesets/15181711`). Calling
  it a "required gate" was simply wrong.
- **The workflow's own comment is part of the problem.**
  `.github/workflows/dependabot-admin-dist.yml:285` asserts "A `GITHUB_TOKEN`
  push does not emit another `pull_request` synchronize run." The first rewrite
  cited that comment as authoritative. It is the obsolete claim #2707's
  acceptance calls out for refresh: push-event suppression and documented
  approval-required PR behaviour are two different things, and the comment
  conflates them.

**What survives, and it is real: the dispatch workaround measures less than the
run it substitutes for.** `.github/workflows/dependabot-admin-dist.yml:281-291`
pushes the rebuilt dist and then runs `gh workflow run ci.yml --ref "$HEAD_REF"`.
All 22 required contexts do live in `ci.yml`, which accepts `workflow_dispatch`
(`.github/workflows/ci.yml:12-17`), and the dispatched run's check runs attach to
the ref tip — the PR head SHA — so the roster is reproduced. But two jobs are
event-shaped and degrade on that path:

- **`ci/coverage` — required, and it degrades.** Its changed-lines threshold reads
  `github.event.pull_request.base.sha || github.event.before`
  (`.github/workflows/ci.yml:777`); on `workflow_dispatch` both are empty, so
  `bin/check-changed-php-coverage` runs with `--base=""` and reports green under
  the required context name having measured a smaller thing.
- **`spec-drift` — not required, and it degrades too.** It branches on
  `github.event_name == 'pull_request'` (`.github/workflows/ci.yml:216-220`) and
  takes its non-PR path because `github.base_ref` is empty. Worth recording, but
  it blocks nothing.

**Owner: #2707** ("CI: handle bot-authored PR workflow approvals and reconcile
check reporting"), open. Its problem statement reaches the same conclusion from
the observed data — REST check-runs returned all 22 required context names on
each exact head while the PR GraphQL `statusCheckRollup` was empty and
mergeability stayed `BLOCKED` — and it states the discipline this finding twice
failed to apply: "Do not infer missing checks solely from an empty PR rollup."

---

## Verified sound

Worth recording so a later pass does not re-litigate these:

- `skeleton/.claude/rules/waaseyaa-{framework,data-freshness,shell-compat}.md` are
  **byte-identical** to `packages/foundation/.claude/rules/`, so `sync-rules`
  is a no-op — the distribution channel is working.
- `public/index.php` is **byte-identical** to
  `bin/maintenance/golden-public-index.php`, so `waaseyaa-audit-site` passes.
- Every CLI command the README and CLAUDE.md advertise exists: `dev`,
  `serve`, `site:init`, `site:doctor`, `admin:dev`, `admin:build`,
  `frankenphp:install`, `optimize:manifest`, `sync-rules`, `scaffold:auth`.
- `BootFailureResponder` correctly gates the raw exception message behind
  `APP_DEBUG` and is unit-tested.
- The worker-mode detection in `public/index.php` deliberately keys off the
  Caddyfile-set `WAASEYAA_FRANKENPHP_WORKER` marker rather than
  `function_exists('frankenphp_handle_request')`, with the reasoning in a
  comment. That is the correct call.
- `ci/skeleton-create-project` exercises `create-project` against the path
  skeleton on every PR, plus a "fresh skeleton preserves the complete discovery
  set without classmap optimization" assertion.
- The prebuilt admin SPA does ship — `packages/admin-surface/dist` with a
  manifest, markers, and signature.

> **Status (2026-08-30): re-verified, still sound, with two additions and one
> exception.** All four byte-identity comparisons are silent — the three
> `.claude/rules/*.md` files against `packages/foundation/.claude/rules/`, and
> `skeleton/public/index.php` against
> `skeleton/bin/maintenance/golden-public-index.php`.
>
> The advertised-command list gains two entries that did not exist when the audit
> was written, both from #2644: `install:init`
> (`packages/cli/src/Provider/MigrateServiceProvider.php:196`) and the boot-free
> handling of `site:init` / `site:doctor`
> (`packages/foundation/src/Kernel/ConsoleKernel.php:69`).
> `ci/skeleton-create-project` now also builds and runs the production image (S12)
> and asserts the `.env` exclusion (S2).
>
> The `packages/admin-surface/dist` claim was **not** re-verified in this pass.

---

## Suggested order

1. **S1** — one-line guard. A new user's first push should not be red.
2. **S2** — one-line `.dockerignore` change. Secrets in a layer.
3. **S3** — decide who owns `/`, then delete the loser.
4. **S5** — `.gitattributes`, coordinated with the Boost-parity D3 decision on `docs/specs/`.
5. **S4** — belongs to the agent-surface work tracked in
   `2026-08-29-boost-parity-agent-surface.md`; the skeleton is the symptom, not the cure.
6. **S6–S11** — a single documentation sweep.

> **Status (2026-08-30): items 1–3 and the S6/S7 half of item 6 are discharged.**
> What remains, in the order the open issues sequence it: **S5** → #2650 (gated
> on #2649's packaged-form harness, and on #2661 before `docs/` can be reasoned
> about); **S4** → #2655 → #2657 → #2659; **S13** → #2702, a decision that blocks
> nothing else; **S15** → #2707; **S1's residue** → #2664 → #2665. **S9**,
> **S10**, **S11** and the `docs/specs/**` row from **S7** are unfiled and remain
> a single small documentation-and-hygiene sweep.
