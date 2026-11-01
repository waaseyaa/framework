# FW-3117-CLI-IO-01: Symfony IO lifecycle repair

Status: local repair evidence for Framework #3117, first #2653 delivery slice. No merge, push, package publication or release is implied.

## Identity and contract

- Base: `19c77027bd4fe2730ba21627aa30b3867fdcff9b` (reviewed #2653 audit candidate).
- Repair code commit: `bcd9f910a` on isolated `codex/2653-cli-io-repair` worktree.
- Candidate-local root `composer.lock` SHA-256: `8719A36FB5792012CE6F08DB9DF52351D9339345606B882E9D07ED2BFE3461BC`.
- Relevant contract: `docs/specs/cli-kernel.md`, #2653 S0 in `FW-AI-FIRST-DELIVERY-01.md`. The #2660/#2664 installer transaction and #2686 shared `AGENTS.md` ownership slices remain separate.

## Finding and repair

`WaaseyaaConsoleApplication::run()` called `doRun()` directly. Symfony's `Application::run()` owns `configureIO()`, which turns real argv `--no-interaction` and `-n` into `InputInterface::isInteractive() === false`. The direct call skipped that configuration. In Bimaaji, an unmarked existing target then followed the interactive prompt and default skip path, allowing a success-shaped exit.

The one-line runtime change calls `parent::run()` inside Waaseyaa's existing throwable catch and exit normalization. Waaseyaa still disables Symfony exception catching and auto-exit. Its concise errors, usage code 2, domain code 1 and interrupted code 130 remain owned by the existing wrapper.

## Verification on native Windows, 2026-10-11

- Red before repair: real `ArgvInput` data-provider cases for `--no-interaction` and `-n` both failed because `isInteractive()` remained true (2 tests, 2 failures).
- Green after repair: `SymfonyConsoleRuntimeTest.php`, 14 tests and 45 assertions. Includes both flag spellings, interactive JSON output and 130 result, plus existing exception/usage/handler controls.
- `BimaajiInstallCommandTest.php` collision control passes with real `ArgvInput`, Codex and unmarked human-authored root `AGENTS.md`; exit is 1 and target bytes are preserved.
- Focused Bimaaji unit run excluding the unchanged Windows path-separator assertion: 24 tests, 80 assertions, pass. The unfiltered run has one existing host-specific assertion at `aCorruptSkillDocumentProducesTheActionableDiagnostic`: it expects `alpha/SKILL.md` but Windows prints `alpha\SKILL.md`.
- `tests/Integration/PhaseN/BimaajiInstall`: 22 tests, 113 assertions, pass.
- `composer cs-check -- --using-cache=no` passes. `php bin/check-pr-preflight`: 43 gates, zero failures, three not applicable. `git diff --check` passes.
- Independent read-only review using the maintainer-selected current agent settings found no actionable issue. Review confirmed Symfony `configureIO()` is restored and Waaseyaa's outer mapping remains active. Reviewer observed the same unrelated Windows path assertion. Installed-consumer and hosted Linux outcomes were not available at review time.
- Isolated installed consumer: `git archive` of exact repair commit `bcd9f910a616d5b2b6c7516e9087388bd9400864`, archive SHA-256 `1165A24177C719FF50A2E9923A231A8507D6E9DA094F0FD6CA715895751335BC`. Composer path repositories used `symlink=false` and mirrored archive packages into a disposable skeleton consumer. The installed `waaseyaa/cli` directory was not a reparse point, and installed `WaaseyaaConsoleApplication.php` matched its archived source SHA-256 `3FB1C77D1F837E4A6281A19B4864E21C1DDC238217142A642119560C1F4C1023`. The consumer had no project-root `skills/` directory. `install:init` exited 0. With a human-authored unmarked `AGENTS.md` seeded afterward, `bimaaji:install --client=codex --no-interaction` exited 1 and preserved its exact SHA-256 `565464C736ABAE92322C9D4263B53F027DBEF9F04E37B56037BADC15DDC2A159`.
- The disposable consumer's lock SHA-256 was `28024BF5A1EF5F0F283D929942573EB02F0A3C0AA40D080D4FCFF3ED2A27EB19`. The fresh resolver stalled before generating a lock from the archive, so the lock from a prior candidate-local path resolution was copied with its path URLs changed to the archive source. Composer installed 103 packages and warned that this lock was not up to date with `composer.json`. This proves the archived CLI/Bimaaji bytes and the stated behavior with those locked dependencies; it is not a clean fresh-resolution or split-package publication qualification.

## Remaining scope

This repair ensures a collision fails honestly and preserves the colliding human file. The installed-consumer refusal wrote 11 Codex skill targets and `.waaseyaa/bimaaji-install.json` before returning 1. All-target preflight, atomicity, cleanup and rollback belong to #2660/#2664. Composition with a site-owned `AGENTS.md` region belongs to #2686. Hosted Linux symlink and real-pipe qualification remains outstanding; this Windows result does not count as that gate.
