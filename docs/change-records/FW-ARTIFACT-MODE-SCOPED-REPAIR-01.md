# Framework atomic-write artifact contract — discovery register

Scope: `waaseyaa` framework repo, lane `fw-site-verify-noexec-boundary`
(branch `issue-site-verify-noexec-boundary`), commit `5c637161a` and prior
(`9fd531cc0`, parent `7e23eea254c6ee968b6c1bdeb47453dc11a8645c`).

This is a **discovery deliverable**, not a to-do list. It classifies every
atomic-write artifact producer found by a framework-wide search (`tempnam(`,
`fopen(...'x'...)`+`rename(`, `file_put_contents`+`rename(` naming patterns,
and the `chmod(` sites already present) by what the artifact actually is,
who consumes it, and what mode its contract requires. **Mode 0600 is not
inherently a defect** — it is correct for private/secret state, and several
entries below are correctly 0600 by design. A site is a real finding only
when its contract requires portability/consumer-readability and its actual
mode fails that contract.

Only one entry below (`FieldAccessPreflightHandler`) was **confirmed** as a
real mismatch actually hit by the community-events starter journey
(`site:init`/`site:apply`/`site:doctor`/`field-access:preflight`, verified
via `bin/maintenance/site-verify`) and repaired this round. Every other
entry is recorded honestly for a future pass; none is acted on here.

## Method

For each producer: read the real write path (not just the `tempnam`/`rename`
grep hit), determine the mode the code actually leaves on disk (explicit
`chmod`, or the mechanism's default — `tempnam()` always 0600 regardless of
umask; `fopen(...,'x'...)`/`file_put_contents` default to 0666 & ~umask,
typically 0644), determine what/who consumes the file and whether it is
part of a portable/bundled/consumer-facing tree or purely internal runtime
state, and — where relevant to this round's journey — empirically confirm
whether the producer is actually invoked by the community-events starter's
`site:init → site:apply → field-access:preflight → site:doctor →
bin/maintenance/site-verify` path (starter blueprint has no `auth` capability,
no media/image fields, and no AI capability — see
`packages/site-contract/resources/starters/community-events/v1.yaml`).

## Findings

| # | Site | Artifact / contract | Mode produced | Classification | Journey-confirmed? |
|---|------|----------------------|----------------|-----------------|---------------------|
| 1 | `packages/cli/src/Handler/FieldAccessPreflightHandler.php:52-65` | `.waaseyaa/field-access-preflight.json` — required at a **later, separate** application boot (`Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight`), commonly a different process/user than the CLI invocation that wrote it; part of the bundled project tree. | Was 0600 (`tempnam()` default, never chmod'd) | **Real mismatch — REPAIRED.** Portable contract requires {0644, 0755}; producer left it at tempnam's private default. | **YES.** This is the exact file PROTOCOL302_BUNDLE_ENTRY flagged. |
| 2 | `packages/config/src/Manifest/ConfigManifestEnvelopeFile.php:96-127` | `config/sync.envelope.json` — signed sidecar authorizing a config sync bundle; read by later config-sync consumers. | 0644 (explicit `chmod($temporary, 0o644)` before `rename()`) | **Already correct — reference pattern.** This is the pattern `FieldAccessPreflightHandler`'s fix now mirrors. | Indirectly reachable via `project:config:authorize`, but its output is staged in a private, deleted-before-return scratch directory (see #3) — never lands in the final tree under this name during `site:apply`. |
| 3 | `packages/cli/src/ProjectInit/ProjectConfigAuthorizer.php:56-93` | A **private scratch directory** (`tempnam()` used only to reserve a unique name, then `unlink()`+`mkdir(0700)`) holding staged `config/sync/*` YAML (chmod 0600) during blueprint signing. Removed via `removeTemporaryTree()` in a `finally` block before `authorize()` returns. | 0700 dir / 0600 files, by explicit design | **Intentionally private, and not a persistent artifact at all** — it never survives past the call that creates it, so it can never appear in a bundled tree. Correct as-is. | Invoked during `site:apply`'s blueprint-approval path, but produces nothing durable to check. |
| 4 | `packages/cli/src/Scaffold/AuthUiScaffoldManager.php:478-497` | `.waaseyaa/scaffold-manifest.json` — ownership/provenance manifest for generated auth-UI files, analogous in role to `.waaseyaa/bimaaji-install.json` (meant to be committed alongside the generated files it tracks). | 0600 (`tempnam()`, never chmod'd) | **Real mismatch by the same shape as #1**, but **NOT invoked on this journey**: only reachable via the opt-in `scaffold:auth` CLI command (`ScaffoldAuthHandler`), which nothing in `site:init`/`site:apply`/`site:doctor` calls, and the community-events starter declares no `auth` capability. **Candidate for a future repair pass — not repaired this round.** | NO — starter has no auth capability; `scaffold:auth` is never invoked. |
| 5 | `packages/media/src/LocalFileRepository.php:305-338` | JSON metadata sidecars beside stored files (`public://...` URI-derived paths) — read only by the framework's own `LocalFileRepository` process; not directly served to HTTP clients (the served bytes are a separate file). | 0600 (`tempnam()`, never chmod'd) | **Candidate, unresolved.** Whether this needs to be portable depends on whether a different process/user (a backup tool, a separate web-server user, an ops script) ever reads these sidecars directly outside `LocalFileRepository` itself — not established here. Not clearly a "private secret," but also not clearly required to be world-readable. **Needs product-owner input before classifying as a repair or as accepted-private.** | NO — reachable only through `MediaRouter` HTTP upload handling and `save()`/relocation calls at runtime, not through the community-events starter's generation/verification path (starter has no media fields). |
| 6 | `packages/ai-tools/src/Content/MediaAssetStore.php:108-138` | Final served media asset (`uploadsDir/<sha256>.<ext>`), reachable by a public/authorized download URL (`publicUrl()`) — genuinely consumer/HTTP-served content. | The staging `tempnam()` file (0600) is `rename()`d **directly into the final served path with no chmod** — the served asset itself ends up at 0600. | **Real mismatch.** A servable media asset should not be 0600; whatever process serves it (potentially a different user/process than the AI-tools upload call) needs read access. **Candidate for a future repair pass — not repaired this round**, and worth prioritizing given it is a genuinely public/consumer-facing artifact. | NO — only reachable through an AI content-ingestion `upload()` call; the community-events starter declares no AI capability and this path is never invoked by `site:init`/`site:apply`/`site:doctor`/`field-access:preflight`. |
| 7 | `packages/cli/src/Site/SiteInitializationService.php` (`writeDurably()`/`writeAtomically()`, the whole generated-project-tree publisher) | Every artifact in `GeneratedSite`/`ArtifactPlan` (`.waaseyaa/site.yaml`, `bin/maintenance/site-verify`, `AGENTS.md`, `tests/Architecture/SiteContractTest.php`, generated `config/sync/*`, etc.) | Explicit `chmod($path, $mode)` with `$mode` taken from `GeneratedArtifact::$mode`, which the constructor **already enforces** must be `0o644` or `0o755` (`GeneratedArtifact.php:21`) | **Already correct — enforced at the type level.** This is the reference-grade pattern; no producer using `GeneratedArtifact` can construct an out-of-contract artifact at all. Covered by the new guard's second test as a regression lock (different mechanism than #1: `fopen(..., 'x+b')` + explicit `chmod`, not `tempnam`). | YES — this is the core `site:init`/`site:apply` publisher; already correct, so nothing to repair. |
| 8 | `packages/cli/src/Site/SiteInitializationService.php:2166-2169` (`writeJournal()`) → `.waaseyaa/site-init.transaction.json` | Transaction journal — read only by the same `site:init`/`site:apply` invocation (and a resuming recovery run under the same lock), **excluded from the bundled tree** by the generator's own `.waaseyaa/.gitignore` (`SiteArtifactRenderer::controlIgnore()`), and `unlink()`d by `cleanupTransaction()` on every successful run. | 0600, explicit (`writeAtomically(..., 0o600)`) | **Intentionally private — correct as-is.** Confirmed genuinely private (mode captured live via a real fault-injection hook in the new guard) and confirmed it never survives a successful run to reach a bundler. | YES (produced on every `site:apply`), and confirmed correctly private — not a mismatch. |
| 9 | `packages/cli/src/Site/SiteInitializationService.php:203-221` (`acquireLock()`) → `.waaseyaa/site-init.lock` | Advisory `flock()` lock file, empty/opaque content, excluded from the bundled tree by the same `.gitignore` entry. | `fopen(path, 'c+b')` default (no explicit chmod) — typically 0644 by umask, but mode is irrelevant to its contract | **Not a meaningful mode contract either way** — it is control-plane state excluded from the portable tree regardless of its mode; classified as intentionally-private/control-plane rather than a portability candidate. | Produced on every `site:apply`; not a mismatch. |
| 10 | `packages/config/src/Cache/ConfigCacheCompiler.php:64-73` | `<compiled config cache>.tmp.<pid>` → runtime PHP-array config cache, private to the booted process, lives under a gitignored cache directory — never part of a bundled/committed project tree. | `file_put_contents()` default (~0644 by umask), no `tempnam` | **Not applicable** — not a bundled/portable artifact at all (pure runtime cache), and its default mode is already portable-shaped regardless. | NO — `site:init`/`site:doctor` are boot-free (do not boot the kernel); this cache is written only when the *application itself* later boots. |
| 11 | `packages/foundation/src/Discovery/PackageManifestCompiler.php:395-415, 555-577` | Package-manifest discovery cache (two call sites) — same shape as #10. | `file_put_contents()` default (~0644), no `tempnam` | **Not applicable**, same reasoning as #10. | NO — boot-free `site:init`/`site:doctor`/`field-access:preflight` never trigger this. |
| 12 | `packages/config/src/Storage/FileStorage.php:60-116` (config active store) | `config/active/*.yaml` — consumer-editable active configuration. | `fopen($temp, 'x')`-family default (~0644), no `tempnam`; already portable | **Not applicable / already correct** regardless of invocation. | Not invoked by this journey (community-events declares `recipes: []`), and would be fine even if it were. |
| 13 | `packages/config/src/Sync/ConfigSyncRepository.php:110-160` (config sync store) | `config/sync/*.yaml` — the canonical sync-store writer for the authoring side of config sync. | Same `fopen(...,'x'...)` default (~0644), no `tempnam`; already portable | **Not applicable / already correct.** | Not invoked by this specific starter's generation path (which publishes `config/sync/*` through `SiteInitializationService`'s `GeneratedArtifact` pipeline, entry #7, not through this repository directly) — recorded for completeness since it shares the "config/sync writer" role. |
| 14 | `packages/entity/src/EntityTypeLifecycleManager.php:139-159` (`writeStatus()`) | Per-tenant entity-type disable/status file — internal runtime state read only by the same application instance. | `file_put_contents()` `.tmp.<pid>` default (~0644), no `tempnam` | **Not applicable** — internal runtime state; already portable-shaped anyway. | NO — runtime feature-flag state, unrelated to project generation/verification. |
| 15 | `packages/foundation/src/Maintenance/MaintenanceState.php:95-118` | `maintenance.flag` — operational maintenance-mode flag, internal runtime state. | `file_put_contents(..., LOCK_EX)` default (~0644), no `tempnam` | **Not applicable**, same reasoning. | NO. |
| 16 | `packages/foundation/src/Ingestion/IngestionLogger.php:98-107` | Ingestion audit log — internal runtime log. | `file_put_contents()` `.tmp.<pid>` default (~0644), no `tempnam` | **Not applicable**, same reasoning. | NO. |
| 17 | `packages/foundation/src/Http/Router/BroadcastRouter.php:503-517` | `subscribers.json` — SSE broadcast runtime subscriber state. | `file_put_contents()` `.tmp.<pid>` default (~0644), no `tempnam` | **Not applicable**, same reasoning. | NO. |
| 18 | `packages/bimaaji/src/Command/BimaajiInstallCommand.php` (`writeManifest()`) → `.waaseyaa/bimaaji-install.json` | Ownership/provenance manifest for installed Agent Skills, meant to be committed (per its own doc comment). | Not audited in this pass (uses `writeFile()`, a different helper than `tempnam`) — **flagged for the next discovery pass**, not verified either way. | **Unclassified — deferred.** Structurally analogous to #4 (`AuthUiScaffoldManager`'s scaffold manifest); worth checking together in a follow-up. | NO — `bimaaji:install` is opt-in and not part of `site:init`/`site:apply`/`site:doctor`/`field-access:preflight`. |
| 19 | `packages/cli/src/AdminBuild/AdminBuildOutputPublisher.php:68`, `packages/cli/src/AdminBuild/AdminDistAcceptance.php:257` | Published admin-SPA build output files. | Explicit `chmod($target, 0o644)` already present | **Already correct.** | NO — admin-dist build pipeline, unrelated to site generation. |
| 20 | `packages/frankenphp/src/Binary/Installer.php:69` | Downloaded FrankenPHP binary. | Explicit `chmod($file, 0o755)` already present | **Already correct.** | NO — optional dev-runtime installer, unrelated. |
| 21 | `packages/deployer/src/RuntimeState/SqliteArtifactPreparer.php:63` | Candidate runtime-state SQLite database, explicitly private during the deploy-swap staging window. | Explicit `chmod($candidateDatabase, 0o600)` already present | **Intentionally private — correct as-is** (a serving database mid-preparation should not be world-readable). | NO — deployer package, unrelated to site generation. |
| 22 | `packages/deployer/src/RuntimeState/SqliteArtifactInstaller.php:93-114` | Serving/backup database file swaps (pure `rename()`, no new temp file created here — files already exist with their prior modes). | N/A — no new file creation, only renames of existing files | **Not applicable** — not a producer of a new artifact mode. | NO. |
| 23 | `packages/cli/src/AdminBuild/HermeticBuildEnvironmentFactory.php:284`, `HermeticAdminBuildPipeline.php:65`, `AdminBuildSourceWorkspace.php:117` | Hermetic build-environment scratch files (empty `.env`, copied source files) used only inside an isolated build sandbox process. | Explicit `chmod(..., 0o600)` already present | **Intentionally private — correct as-is** (build-sandbox scratch, not a distributed artifact). | NO — admin-dist build pipeline, unrelated. |

## Summary

- **Confirmed and repaired this round:** #1 (`FieldAccessPreflightHandler` →
  `.waaseyaa/field-access-preflight.json`).
- **Real mismatches, NOT invoked by this journey, tracked for a future
  pass:** #4 (`AuthUiScaffoldManager` → `.waaseyaa/scaffold-manifest.json`),
  #6 (`MediaAssetStore` → served media asset ends up 0600).
- **Unresolved candidate needing a product-owner call, NOT invoked by this
  journey:** #5 (`LocalFileRepository` metadata sidecars).
- **Unclassified, deferred to the next discovery pass:** #18
  (`BimaajiInstallCommand` → `.waaseyaa/bimaaji-install.json`).
- **Already correct by design (reference patterns / enforced at
  construction):** #2, #7, #19, #20.
- **Intentionally private, verified correct:** #3, #8, #21, #23.
- **Not applicable — internal runtime state, not a bundled/portable
  artifact, or not a new-file producer:** #9, #10, #11, #12, #13, #14, #15,
  #16, #17, #22.

This register is a discovery deliverable, not a repair backlog to execute
against automatically. Any future repair of #4, #5, #6, or #18 should
re-verify journey-invocation empirically for whatever new scenario prompts
it (community-events is not the only starter/journey the framework serves),
and must not widen #3, #8, #9, #21, or #23 — those are correctly private by
design.

## Follow-up repair candidate - 2026-09-09

The reviewed follow-up candidate integrates the two confirmed portable-mode
mismatches that the original community-events pass recorded but did not change:

- Finding #4, tracked by #3061, publishes the auth UI scaffold provenance
  manifest at mode 0644 before its atomic rename. The same writer serves both
  scaffold:auth publication and --accept-current; schema, digests, auth UI
  source ownership, and authentication state remain unchanged.
- Finding #6, tracked by #2517, publishes newly stored, HTTP-served media bytes
  at mode 0644 before their atomic rename. Existing content-addressed files
  retain their current mode, and media authorization/routing remain unchanged.

Both repairs retain private 0600 staging inodes until publication, fail before
the stable path is exposed if chmod fails, and leave the intentionally private
findings in this register unchanged. Independent reviews accepted the runtime
and behavioral test deltas at f06fa4357edb4acfdbd404daafa416944ae4adb7 and
8ba4fc3f26c48eb1ed00c420223f2f687e3a15c5; publication qualification binds
the resulting integration candidate on current main.
