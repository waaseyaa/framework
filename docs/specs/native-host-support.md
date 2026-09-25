# Native host support

- Status: normative entrypoint contract
- Contract owner: Framework maintainers
- Change record: [`FW-NATIVE-HOST-SUPPORT-01`](../change-records/FW-NATIVE-HOST-SUPPORT-01.md)
- Parent program: #2676
- First review due: 2026-12-05, and at every tagged release

## Purpose and boundary

This document defines the native Linux and native Windows support boundary for
Waaseyaa repository entrypoints. It is about developer, verification, and
consumer-project command portability. It does not replace the S1 serving
contract in [`s1-support-lifecycle.md`](s1-support-lifecycle.md), which remains
the authority for the framework's candidate production profile.

The words **portable**, **verified**, **host-specific**, and **unsupported**
have precise meanings here:

- **Normative portable target:** the public invocation is required to work on
  each declared host without a POSIX shell, POSIX permission bits, POSIX path
  spelling, or a host-specific process-launch convention. This is a support
  requirement, not evidence that every target has already run it.
- **Verified native portable:** both native Linux and native Windows evidence
  execute the named entrypoint and demonstrate the material-equivalence
  properties relevant to that evidence. PHP source, a PHP shebang, or a
  simulated Windows path on Linux is not native Windows evidence.
- **Host-specific internal automation:** an intentionally bounded maintainer,
  release, hook, or CI helper. It is not a consumer support promise. Its host
  requirement must be stated at the invocation boundary, with an owner and
  review/expiry date.
- **Unsupported or verification pending:** no current cross-host support claim
  is made. A normative target may remain here until native evidence exists. A
  failure is not represented as a verified regression until a later candidate
  supplies that evidence.

WSL and Git Bash are optional contributor environments. They can be convenient
ways to run POSIX automation on Windows, but neither is native Windows evidence
and neither substitutes for a native Windows job.

## Normative host and runtime targets

The support target is a tuple, not an unbounded operating-system label.

| Host profile | Normative OS target | Normative runtime/tool target | Contract role |
|---|---|---|---|
| Native Linux | Ubuntu 24.04, x86_64 | PHP `>=8.5.0 <8.6.0`; Composer `>=2.10.0 <3.0.0` on feature line 2.10; Node `>=24.0.0 <25.0.0` where needed; SQLite `>=3.40.0 <4.0.0`; required PHP extensions from `composer.json` | S1 authoritative framework test point; consumer serving certification remains pending |
| Native Windows | `windows-2025` reference host with PowerShell | PHP `>=8.5.0 <8.6.0`; Composer 2.10; Node 24 where needed; SQLite `>=3.40.0 <4.0.0`; command-specific required PHP extensions | Normative development and verification target; verified only for the evidence rows below; no serving claim |

The Windows row is intentionally the exact CI reference host, not a claim that
every Windows release or every local Windows configuration has been tested.
Windows 11, other Windows Server images, ARM Windows, other Linux
distributions, macOS, and other PHP/Composer/Node/SQLite combinations are not
separate verified host claims in this document. A follow-up may add one only
with a named runner or reproducible evidence source.

The existing machine-readable S1 authority remains
[`support/s1-v1.json`](../../support/s1-v1.json). Its framework test point is
Ubuntu 24.04 x86_64; its consumer certification remains separate and pending.

## Current verified host evidence

| Evidence | What it verifies | What it does not verify |
|---|---|---|
| `support-contract` on `ubuntu-24.04` | The S1 framework runtime tuple and contract parity recorded by `php bin/check-support-contract --ci` | Consumer production certification |
| `skeleton-create-project-windows` on `windows-2025`, with PHP 8.5, Composer 2.10 and its declared extension set | `create-project`, `post-create-project-cmd`, pre-init `composer site-verify` exit 3, `site:init`, `site:doctor`, `install:init`, repeated generation/verification, the installed consumer CLI (next row), and the Bimaaji junction-containment entrypoint | Node version, SQLite library version, architecture, serving, FrankenPHP, admin build, Playwright, full PHPUnit, or unrelated CLI commands |
| `native-host-contract` matrix on `ubuntu-24.04` and `windows-2025`, with PHP 8.5, Composer 2.10 and the contract's extension set, gated by `ci/native-host-contract` | Every command in [`tools/native-host-contract.json`](../../tools/native-host-contract.json) on both hosts, one step each: the locked `composer install`; `php bin/check-repo-root-hygiene` before and after the tests; `php bin/check-composer-policy`; `php bin/check-portable-paths`; the null-device `--self-test` of `php bin/check-skeleton-docker-secret-exclusion`; the static portable null-device guard `php bin/check-portable-null-device` (see [Portable null-device guard](#portable-null-device-guard-fw-2678-portable-null-device-03)); and `php vendor/bin/phpunit` over a curated Unit, Integration and Architecture selection covering native paths and temporary directories, subprocess launch, exit propagation and error handling, the Windows null device and its static guard, host-aware Git and executable resolution, and the existing local-operator trust boundary. PHPUnit fails on skipped, incomplete and empty selections, and each leaf publishes a validated evidence record (runtime versions, runner and shell identity, exact source subject, per-step exit codes, per-command test counts, replay renderings) | Full PHPUnit, the preflight aggregate, Bash-backed gates, serving, FrankenPHP, Docker, browsers, release tooling, Node, AI CLI commands, MCP stdio, the complete local-AI plane (#2680), packaged consumers (#2681), general CLI commands, or the runtime portability of any PHP the null-device guard classifies |
| `site-reference-consumer` on `ubuntu-24.04` and `skeleton-create-project-windows` on `windows-2025`, each with PHP 8.5 and Composer 2.10, paired by `ci/native-host-consumer-cli` | In a fresh consumer built from the originating checked-out candidate, after `site:init` and `install:init` complete, `php vendor/bin/waaseyaa list --raw` (the `consumer_cli` section of [`tools/native-host-contract.json`](../../tools/native-host-contract.json)) exits 0 on both hosts and lists `list`, `db:init`, `site:init`, `site:doctor` and `install:init`. Each lane publishes a validated record binding the candidate (Linux: the revision its harness archived; Windows: its checkout), the installed `waaseyaa/*` cohort by content, the lifecycle (`site:init` publications and an activated configuration generation), the exact argv, exit code and catalogue, the boot environment, and the runner, shell and runtime identity; the gate accepts one passing record per lane from one run with one subject and an equivalent cohort | Any other CLI command's behavior or output, `list --raw` as a read-only command (a full CLI boot opens the application database), the repository-root CLI (which cannot boot without an activated configuration generation and application secret; a design discriminator only), published or packaged consumers (#2681), serving, or the Linux published-release-line lane `ci/skeleton-create-project` |

Using Composer in a job proves that the job's Composer invocation worked. The
native-host contract pins Composer to the 2.10 line and its evidence records
and range-checks the observed Composer, PHP and SQLite library versions and
the architecture on both hosts; the other Windows jobs record none of them. No
job records Node evidence, because no selected contract command uses Node.
The remaining #2678 slices, #2680 and #2681 own the remaining implementation and CI gaps. WSL
and Git Bash results do not close them.

The hosted leaves run their steps under `pwsh`. That shell is CI harness
machinery, not a Linux or Windows contributor or consumer prerequisite: the
evidence records the shell that ran, and its replay data gives each command's
canonical argument array, a native PowerShell rendering for Windows and a
POSIX `sh` rendering for Linux. Every element of both renderings, the program
included, is a single-quoted literal: PowerShell's through the `&` call
operator with each single quotation mark doubled, POSIX `sh`'s with `'\''`. No
element is left for either shell to interpret. Each leaf round-trips the
PowerShell rendering of every command, and a fixed set of switch-shaped,
spaced, quoted, empty and backslash-path arguments, through the hosted
`pwsh`; the Linux leaf also round-trips the POSIX rendering through `sh`. So
every recorded rendering delivered the exact argument array. No single quoted
string is claimed to work in every shell, and neither `cmd.exe` nor Windows
PowerShell 5.1 renderings are claimed: 5.1's legacy native-argument passing
drops an empty argument and mangles a quoted path ending in a backslash.

## Materially equivalent behavior

Two host implementations are materially equivalent when they preserve all of
the following:

1. **Capabilities:** the same supported operation can be performed, including
   the same input forms and generated artifact set.
2. **Exit semantics:** success, refusal, interruption, dependency absence, and
   verification failure retain the same exit-code meanings. A translated
   diagnostic is acceptable; silently converting a refusal into success is not.
3. **Security boundaries:** authentication, authorization, capability
   allowlists, approval gates, secret redaction, path containment, and
   production-versus-development boundaries are unchanged.
4. **Generated state:** files, manifests, lock/journal state, migrations,
   evidence records, and recovery markers have the same logical contents and
   lifecycle. Host-native path separators and permission metadata may differ
   where the host cannot represent the other host's form.
5. **Recovery guidance:** an operator receives an actionable equivalent remedy.
   The command may say `Remove-Item` on Windows and `rm` on Linux, but it must
   identify the same failed state, preserve the same evidence, and recommend
   the same safe next step.

Human-readable output, line endings, terminal colors, executable-bit display,
drive-letter spelling, and `/` versus `\` path spelling may differ. These
differences are presentation, not permission to change behavior.

## Entrypoint inventory

The inventory below is the current repository boundary. It classifies the
invocation surface, not every implementation detail behind a command.

### Composer scripts

The root `composer.json` is the framework-maintainer manifest. The skeleton
`composer.json` is the consumer-project manifest. Composer does not make a
script portable by itself: shell syntax, child entrypoints, and native evidence
still determine the current disposition.

| Entry point or family | Disposition | Notes |
|---|---|---|
| Root PHP/tool checks other than `check-pr-preflight`: `check-composer-policy`, `check-changelog-shape`, `check-changelog-fragments`, `check-distribution-extensions`, `check-distribution-exclusion`, `check-local-operator-tool-profile`, `check-external-consumers`, `check-governed-secret-access`, `check-runtime-policy-custody`, `check-package-layers`, `check-package-layers-pl008-self-test`, `check-symfony-imports`, `test:inventory`, `check-portable-paths`, `check-repo-root-hygiene`, `check-phpunit-skip-policy`, `check-dead-code`, `check-getquery-bindings`, `check-dispatcher-keys`, `refresh-governance-artifacts`, `check-admin-dist-fresh`, `check-admin-dist-manifest`, `check-s1-sqlite-contract`, `check-s1-schema-authority`, `check-s1-configuration-authority`, `check-s1-configuration-activation`, `check-delivery-agent-events`, `check-delivery-agent-projection`, `cs-check`, `cs-fix`, `phpstan` | Host-specific internal framework-maintainer automation; no native Windows support claim | PHP source or a Composer script is not native Windows proof. No native Windows job runs these Composer scripts. The native-host contract runs the underlying `php bin/check-composer-policy`, `php bin/check-portable-paths` and `php bin/check-repo-root-hygiene` entrypoints on both hosts (see the `bin/` inventory); that does not make the Composer script wrappers verified. |
| Root `check-pr-preflight` | Host-specific internal framework-maintainer automation | The PHP coordinator executes the governed roster, which includes Bash commands such as `check-phpstan-paths`, `check-phpunit-paths`, `check-admin-coercion-patterns`, `check-openapi`, `spec-drift`, and changelog discipline. It is not a native Windows entrypoint today. On native Windows its Bash gates start with Git for Windows Bash rather than the `System32` WSL launcher, and without that Bash it stops before any gate with exit 3 (`docs/specs/governed-gates.md` §8). No native Windows CI job executes it. |
| Root shell-backed checks: `check-openapi`, `check-ingestion-defaults`, `check-no-secrets`, `check-phpstan-paths`, `check-phpunit-paths`, `check-contract-suite-coverage`, `check-admin-coercion-patterns`, `check-admin-coercion-self-test`, `check-field-guards`, `check-access-hardening` | POSIX-only internal automation | Run in the declared Linux CI environment or an explicitly provisioned POSIX shell. Git Bash is optional convenience only. |
| Root `test`, `test:random`, and `verify` | Host-specific framework-maintainer automation | The complete suite includes POSIX-only fixtures; random-order and `verify` compose Linux/POSIX tooling. Targeted Windows PHPUnit evidence does not make these aggregate scripts native-portable. |
| Root `dev`, `dev:php`, and `dev:admin` | POSIX-only in their current form; native Windows unsupported | `dev:php` and `dev:admin` use POSIX `NAME=value` assignment and invoke the Bash `bin/waaseyaa`; `dev` delegates to `dev:php`. The Windows jobs deliberately exercise none of them. |
| Root `hooks:install`, `hooks:doctor` | Host-specific internal automation | The tracked hook implementation is Bash and installs Git hooks; it is not required for a native Windows consumer checkout. Composer starts it through the PHP `bin/project-hooks-launcher`, which selects Git for Windows Bash on native Windows instead of the `System32` WSL launcher (`docs/specs/project-hooks.md`). No native Windows CI job executes either script. |
| `packages/genealogy` `verify:no-api-coupling` | Unsupported on native Windows pending verification | The script invokes PHP, but no native Windows job executes it. |
| Skeleton `site-verify` (`@php .ci/site-verify.php`) | Verified native portable consumer boundary | Native Linux and `windows-2025` exercise the same PHP implementation, including pre-init exit 3 and post-init verification. |
| Skeleton `dev` (`@php vendor/bin/waaseyaa dev`) | Normative portable target; native Windows serving unsupported pending evidence | The invocation is shell-free, but current Windows jobs explicitly make no `dev`, FrankenPHP, or serving claim. |
| Skeleton `audit-site` | Host-specific internal automation | Delegates to the POSIX `bin/maintenance/waaseyaa-audit-site`. |
| Skeleton `post-create-project-cmd` | Verified native portable | The Linux and Windows skeleton jobs execute `bin/post-create-setup.php` through Composer's PHP. |
| Skeleton `regen-lock` | Dependency-maintenance operation; unsupported on native Windows pending verification | It runs Composer update and therefore resolves dependencies and rewrites lock state. It is not the locked install path. The `waaseyaa/*` pattern is double-quoted, so both `cmd.exe` and POSIX `sh` pass it to Composer unchanged and unexpanded. With single quotes, native Windows passed the quotes through, selected no package and exited 0 (#2679; `tests/Architecture/SkeletonRegenLockTest.php`). No native Windows CI job runs it. |

### Root `bin/` commands

`bin/` contains 105 Git-tracked top-level files at this contract revision. The
`bin/lib/` helper directory is not an entry. Every file appears exactly once in
the partition below, with the stated counts;
`tests/Architecture/NativeHostBinInventoryTest.php` enforces both. Interpreter
choice is recorded to make the inventory reproducible, but is not itself
portability evidence.

| Current disposition | Complete top-level inventory | Evidence/boundary |
|---|---|---|
| POSIX-only internal automation (29 Bash entries) | `audit-composer-deps`, `audit-require-dev-layers`, `build-admin-dist`, `build-exact-source-artifact`, `build-split-contribution-boundary`, `check-admin-coercion-patterns`, `check-contract-suite-coverage`, `check-ingestion-defaults`, `check-monorepo-release-shape`, `check-no-secrets`, `check-openapi`, `check-phpstan`, `check-phpstan-paths`, `check-phpunit-paths`, `check-release-publish-shape`, `check-release-require-parity`, `check-release-tag-parity`, `clean-package-vendors`, `configure-split-tag-protection`, `enable-governed-auto-merge`, `git`, `materialize-exact-source-artifact`, `project-hooks`, `promote-exact-source-artifact`, `test-isolated-package`, `verify-exact-source-artifact`, `verify-random-order-vendor-archive`, `waaseyaa`, `wait-for-green-ci` | Bash is required. Git Bash/WSL may run some entries but are not native Windows proof. |
| WSL2/POSIX-only development runtime (2 PHP entries) | `dev-runtime`, `dev-runtime-consumer` | The implementation requires POSIX absolute paths and `HOME`/`XDG_CACHE_HOME`; the bootstrap additionally uses `tar`, symlinks, `chmod`, and the `wsl2-ubuntu-24.04-x86_64` profile. Its children read PHP's `['null']` stdin descriptor (#2678) rather than a hard-coded `/dev/null`, which does not make either entry portable. Neither entry is native Windows portable. |
| POSIX aggregate despite PHP coordinator (1 PHP entry) | `check-pr-preflight` | It executes `tools/preflight-gates.json`, whose default roster includes Bash tools and shell scripts. On native Windows those Bash gates need Git for Windows Bash. |
| Verified native portable internal CI gates and evidence tool (6 PHP entries) | `check-composer-policy`, `check-portable-null-device`, `check-portable-paths`, `check-repo-root-hygiene`, `check-skeleton-docker-secret-exclusion`, `native-host-evidence` | Executed through `php` on `ubuntu-24.04` and `windows-2025` by the `native-host-contract` matrix, with validated per-host evidence (#2678). For `check-skeleton-docker-secret-exclusion` only the Docker-free `--self-test` mode (null device, launcher probe) is verified on both hosts; its Docker proof stays Linux-owned in `ci/skeleton-create-project`. `check-portable-null-device` is the static guard described under [Portable null-device guard](#portable-null-device-guard-fw-2678-portable-null-device-03). `native-host-evidence` validates hosted contract results and runs no contract command itself. |
| Host-specific internal framework-maintainer tools with no native Windows support claim (63 PHP entries) | `adapt-consumer-promotions`, `admin-dist-acceptance`, `agent-checkpoint`, `audit-ci-roster-live`, `build-phpunit-shards`, `changelog-fragments`, `check-admin-dist-fresh`, `check-changed-php-coverage`, `check-changelog-shape`, `check-ci-roster-conformance`, `check-covers-nothing-companions`, `check-dead-code`, `check-delivery-agent-events`, `check-dispatcher-keys`, `check-distribution-exclusion`, `check-distribution-extensions`, `check-external-consumers`, `check-getquery-bindings`, `check-governed-secret-access`, `check-landing-base`, `check-local-operator-tool-profile`, `check-package-coverage-history`, `check-package-layers`, `check-package-layers-pl008-self-test`, `check-php-coverage-baseline`, `check-runtime-policy-custody`, `check-s1-configuration-activation`, `check-s1-configuration-authority`, `check-s1-schema-authority`, `check-s1-sqlite-contract`, `check-stale-spec-deferrals`, `check-support-contract`, `check-symfony-imports`, `check-upgrade-contract`, `check-vendor-fresh`, `classify-ci-run-evidence`, `collect-ci-run-evidence`, `compile-spec-corpus`, `generate-ci-workflow-inventory`, `generate-surface-map`, `maintainer-skills`, `merge-clover-coverage`, `migrate-surface-map`, `normalize-admin-dist`, `phpstan-level-audit`, `project-board-sync`, `project-ci-ruleset`, `project-delivery-agent-events`, `project-hooks-launcher`, `qualify-candidate`, `refresh-governance-artifacts`, `refresh-phpunit-timings`, `report-ci-measurement`, `resolve-split-main-targets`, `run-hermetic-admin-build`, `skeleton-unpublished-repositories`, `summarize-php-coverage`, `sync-internal-versions`, `test-mutation-pilot`, `test-quality-inventory`, `test-random-order`, `verify-k1-delivery-cutover`, `worktree-coordinator` | Some run in Linux CI or focused local checks; no current native Windows job executes these exact root entrypoints. They are not classified portable merely because they are PHP. `project-hooks-launcher` is the internal Composer entrypoint for `hooks:install` and `hooks:doctor` described in the Composer-script row above. |
| Host-specific internal framework-maintainer tools with no native Windows support claim (2 PHP files without shebangs) | `check-access-hardening`, `check-phpunit-skip-policy` | These remain tracked `bin/` entries, but no current native Windows job proves them. |
| Host-specific release helper (1 Node entry) | `generate-release-evidence` | Release automation, not a native consumer entrypoint; no cross-host claim is made. |
| Host-specific Windows proof helper (1 PowerShell entry) | `check-bimaaji-junction-containment.ps1` | Executed on `windows-2025` to prove junction containment. It is not portable to Linux and is not a general consumer command. |

The `bin/git` classification above is also an operating boundary. The canonical
agent contract requires the repository adapter on supported POSIX hosts and an
explicitly selected Windows Git executable on native Windows. Both paths retain
the repository-wide prohibition on `git stash`; choosing native Windows Git
does not make any other POSIX-only `bin/` entrypoint portable.

The four omissions found during review—`check-landing-base`,
`check-skeleton-docker-secret-exclusion`, `check-vendor-fresh`, and
`worktree-coordinator`—were added to the host-specific PHP row;
`check-skeleton-docker-secret-exclusion` has since moved to the verified row
with `check-composer-policy`, `check-portable-paths` and
`check-repo-root-hygiene` (#2678), and `check-portable-null-device` entered
that row directly. `worktree-coordinator` in particular
accepts only POSIX paths and starts the Bash `bin/git`, so it cannot issue
leases for native Windows worktrees; that is recorded debt, not a support
claim.
`verify-k1-delivery-cutover` appears once. The same row also holds the ten
files that were missing until #2679 reconciled the inventory: the CI and
governance tools `audit-ci-roster-live`, `check-ci-roster-conformance`,
`classify-ci-run-evidence`, `collect-ci-run-evidence`,
`generate-ci-workflow-inventory`, `project-ci-ruleset` and
`report-ci-measurement`, plus `check-package-coverage-history`,
`maintainer-skills` and `project-hooks-launcher`.

### Skeleton scripts and generated maintenance commands

| Entry point | Disposition | Required behavior |
|---|---|---|
| `skeleton/.ci/site-verify.php` | Verified native portable | Plain PHP, no autoloader or kernel boot, exit 3 with `site:init` guidance before initialization, exit 2 when dependencies are absent, and delegation with the child's exit status afterward. |
| `skeleton/.ci/site-verify` | Host-specific POSIX adapter | Convenience wrapper only; it must delegate to the same PHP implementation and cannot be the only verification path. |
| `skeleton/bin/post-create-setup.php` | Verified native portable | The Linux and Windows skeleton jobs invoke it through Composer's PHP. |
| Generated `bin/maintenance/site-verify` | Verified native portable | Generated by `site:init` and executed through `composer site-verify` on Linux and Windows. |
| `site:init` and `site:doctor` | Verified native portable for the exercised lifecycle | Windows CI executes initial and repeated generation plus strict doctor; Linux exercises the corresponding consumer lifecycle. |
| `install:init` | Verified native portable for the exercised fresh-install lifecycle | Windows CI executes the command after `site:init`; this does not establish Windows serving support. |
| Installed `php vendor/bin/waaseyaa list --raw` | Verified native portable for the installed consumer catalogue | After `site:init` and `install:init`, both consumer lanes run it with the same literal rendering and exit capture; `ci/native-host-consumer-cli` requires exit 0 and the five catalogue entries on both hosts. It proves registration and full CLI boot of an installed consumer, not the behavior of the listed commands. |
| `site:apply` | Normative portable target; unsupported on native Windows pending verification | It shares the generation authority but is not invoked by the current Windows job. |
| `skeleton/bin/maintenance/waaseyaa-version` | Unsupported on native Windows pending verification | PHP source and shebang do not establish portability; no current Windows job executes it. |
| `skeleton/bin/maintenance/waaseyaa-audit-site`, `verify-deploy-rsync`, `deploy-artifact-smoke` | Host-specific internal automation | POSIX shell/deployment audit helpers; native Windows consumers use the portable verification command instead. |
| `skeleton/bin/maintenance/golden-public-index.php` | Fixture/support file, not a launcher | Used for byte-comparison by the shell audit; no independent support claim. |

### Test launchers and test claims

| Launcher | Disposition | Evidence boundary |
|---|---|---|
| `php vendor/bin/phpunit` | Verified on both hosts for the native-host contract selection only; full-suite support unsupported | The `native-host-contract` matrix uses this exact invocation, with `--fail-on-skipped`, `--fail-on-incomplete` and `--fail-on-empty-test-suite`, for the curated selection in `tools/native-host-contract.json`. The complete framework suite includes POSIX-only release-tooling, process, advisory-lock, symlink, and RSA/toolchain proofs. |
| `./vendor/bin/phpunit` | POSIX-style launcher; no native Windows evidence | Linux and POSIX environments may execute Composer's direct shim. It does not inherit evidence from the separate `php vendor/bin/phpunit` invocation. |
| `composer test` | Native Windows unsupported as an aggregate | It reaches the complete framework suite and has no native Windows evidence. |
| `cd packages/admin && npm test`, `npm run build`, `npm run typecheck`, `npm run lint` | Normative Node 24 targets; unsupported on native Windows pending verification | Current evidence is Linux-owned; command syntax alone is not proof. |
| `cd packages/admin && npm run test:e2e` | Native Windows unsupported | Playwright Chromium/Firefox evidence is governed by the S1 Linux CI profile. WebKit/Safari is unsupported everywhere in S1. |
| `bin/build-phpunit-shards`, `bin/test-random-order`, `bin/test-isolated-package`, `bin/verify-random-order-vendor-archive` | Host-specific internal CI automation | These are shard, replay, or split-package proof orchestration, not end-user launchers. |
| `composer verify` | Host-specific internal automation | It intentionally composes shell-backed gates and the full suite. Required CI evidence is the declared Linux job set, not a native Windows invocation of this aggregate. |

### Local AI-development commands

The local AI plane is opt-in and remains `require-dev` only, as documented by
[`packages/ai-development`](../../packages/ai-development/README.md) and
ADR-022. It must never enter the production runtime dependency closure.

| Entry point | Disposition | Boundary |
|---|---|---|
| `composer require --dev waaseyaa/ai-development` | Dependency-resolution operation; normative cross-host target, unsupported on native Windows pending verification | `composer require` changes `composer.json`, resolves dependencies, and normally updates `composer.lock`; it does not use an unchanged locked graph. Review and commit the resulting lock, then reproduce it with `composer install --no-interaction` (or `--no-dev` to prove production exclusion). |
| `php vendor/bin/waaseyaa ai:run`, `ai:purge-runs`, `ai:reap-stalled-runs` | Normative portable targets; unsupported on native Windows pending verification | Current Windows evidence tests the local-operator trust boundary, not these CLI commands. |
| `php vendor/bin/waaseyaa bimaaji:install` | Targeted native Windows evidence | The Windows skeleton job executes the real entrypoint through the junction-containment proof. That does not certify every Bimaaji client/configuration. |
| `php vendor/bin/waaseyaa optimize:manifest`, `sync-rules`, `graph:dump` | Normative portable targets; unsupported on native Windows pending verification | No current Windows job executes these commands. |
| `php vendor/bin/waaseyaa mcp:serve --profile=developer` | Normative portable target; unsupported on native Windows pending verification | The resolver has Windows-shaped unit coverage, but no native Windows job launches the stdio server. It does not require `waaseyaa/mcp` or an HTTP server. |

### MCP launchers

| Surface | Disposition | Boundary |
|---|---|---|
| `POST /mcp`, `POST /mcp/write`, `GET /.well-known/mcp.json` from `waaseyaa/mcp` | Protocol contract is host-neutral; Windows serving unsupported | S1 serving evidence is Linux-only. Native Windows has no HTTP MCP serving or deployment proof. |
| `php vendor/bin/waaseyaa mcp:serve` | Normative portable local stdio target; unsupported on native Windows pending verification | Newline-delimited JSON-RPC and shell-free executable resolution are implementation requirements, not native proof. |
| `php vendor/bin/waaseyaa mcp:registry-manifest` | Normative portable generator; unsupported on native Windows pending verification | It emits `server.json` for a configured deployment; registry publication, credentials, and remote hosting are outside this contract. |
| Claude/Cursor/desktop client configuration files containing a command or URL | Host-specific adapter configuration | The MCP protocol remains portable, but each client configuration must use the host's PHP path or remote URL spelling and must not widen credentials or capabilities. |

## Normative pull-request and nightly placement

This section states the support policy that #2678–#2681 must implement. The
presence of a workflow job does not by itself establish that branch protection
currently requires it, and this document does not claim the current ruleset
already matches the policy.

For pull requests that change a native-portable entrypoint, its host boundary,
or its recovery/security contract, the governed pull-request checks **must**
include:

1. the Linux S1 support-contract job on the exact `ubuntu-24.04` runner,
   including `php bin/check-support-contract --ci`;
2. tracked-path portability and Composer policy/manifest checks;
3. the Unit, Integration, and Architecture suites on the declared Linux
   runner;
4. the Linux fresh skeleton/reference-consumer lifecycle, including
   `composer site-verify`;
5. `skeleton-create-project-windows` when the consumer lifecycle, generated
   maintenance verification, Bimaaji installation, or Windows path containment
   can change;
6. the `native-host-contract` matrix on `ubuntu-24.04` and `windows-2025`
   when a contract command, its selected tests, or the paths, subprocess,
   null-device, Git or executable-resolution behavior they prove can change;
   and
7. focused package or architecture tests for the changed inventory row.

MCP stdio, AI CLI, Node/admin, Windows serving, or another unverified target
cannot be declared verified merely by routing an unrelated change through the
existing native Windows jobs. Its pull-request check becomes required when a
follow-up adds a discriminating native proof for that exact surface.

### Implemented placement and cost split (#2678)

The live ruleset requires `merge/platform-runtime-acceptance`, which fails
closed unless `ci/frankenphp-worker`, `ci/skeleton-create-project-windows` and
`ci/native-host-contract` all succeed. `ci/native-host-contract` succeeds only
when both matrix leaves succeeded and `bin/native-host-evidence verify-set`
accepts exactly one passing evidence record per contract host, bound to the
same checkout, contract digest and workflow run. A failed, cancelled, skipped
or missing leaf, step or record therefore blocks the merge. These jobs run
unconditionally on every pull request, which is stricter than the conditional
placement above.

Measured against the 12 successful CI runs before the change, the matrix adds
roughly one to two minutes of Linux runner time (the Linux leaf and the gate)
and replaces the former `ci/local-operator-windows` job (63–84 s) with a
Windows leaf expected to take roughly 90–150 s. The Windows leaf and the gate
finish well inside the Linux PHPUnit shards that pace every run, so the
critical path is unchanged unless Windows runner queueing exceeds several
minutes. Weighted with GitHub's published runner multipliers (Windows ×2),
this is a job-wall cost proxy only: the repository is public and runs on
standard hosted runners, so no billed cost is claimed.

### Consumer CLI placement and cost (FW-2678-NATIVE-HOST-SKELETON-CLI-02)

`merge/platform-runtime-acceptance` also requires `ci/native-host-consumer-cli`,
which succeeds only when `site-reference-consumer` and
`ci/skeleton-create-project-windows` both succeeded and
`bin/native-host-evidence consumer-verify-set` accepts exactly one passing
record per lane from the same run, bound to the verifier's checkout as the
originating candidate and to the verifier's own repository, with an
equivalent installed cohort whose entries it validates and whose digest it
recomputes. The nine
required check names and the frozen rollback baseline are unchanged; the
gate is a proved prerequisite, never projected.

The shared subject is the checked-out candidate. The Linux harness archives
the checkout it runs in and records that revision; it creates its consumer
from a scratch commit of only the skeleton, so the project's source revision
differs from the candidate. The record carries that scratch commit as the
project source, proves its tree equals the candidate's `skeleton/` tree, and
never claims the two revisions are equal. (Composer keeps no root reference
through the consumer's later update on either host, so the observed root
package is recorded as-is.) Composer path-repository references hash a
package manifest and the repository options, not its code, so each installed
`waaseyaa/*` package is instead bound by content: every installed file must
equal the candidate blob at its path (on Windows, with CRLF checkouts
normalized and paths matched without case), and every candidate file must be
installed unless the candidate export-ignores it. The export-ignore set is
what `git archive` leaves out. Each package digest covers its
non-export-ignored candidate paths and blobs, so a package-level
export-ignore difference between `git archive` and Composer's path mirror
cannot split the hosts. Lifecycle completion is observed as `site:init`'s
publications plus an activated configuration generation in the consumer
database, which a CLI boot cannot create. The Linux
consumer boots under the environment its harness exported for the lifecycle
(`APP_ENV=testing` and a fixed test secret, handed over through
`GITHUB_ENV`); the Windows consumer boots from its own post-create `.env`
(`APP_ENV=local`). Both are development environments; the record names the
environment and its source and never records a secret.

`site-reference-consumer` was an unrequired job; it is now merge-blocking.
Its network-permitted `composer update` phase can therefore block a merge,
and a transient failure is recovered by re-running the lane. In the last
main run before the change (36073541055) the Linux lane took 27 s and the
Windows lane 68 s. The change adds to each lane a PowerShell identity step,
the CLI step, the collector (which hashes every installed candidate file,
about fifteen thousand) and one upload, plus the new Linux gate job. Both
lanes and the gate finish inside the Linux PHPUnit shards that pace every
run, so the critical path is unchanged unless runner queueing exceeds
several minutes. As above, this is a job-wall cost proxy only.

### Portable null-device guard (FW-2678-PORTABLE-NULL-DEVICE-03)

A hard-coded `/dev/null` is a POSIX path. As a `proc_open()` descriptor it
cannot open on native Windows, so `proc_open()` returns `false`, which callers
have read as a missing program (#2647). Inside a host-shell string, `cmd.exe`
cannot open it either, and the command never runs (#3096). The host-aware
forms are PHP's `['null']` descriptor, which opens the host's null device, and
a host-derived choice such as `PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'`.

`php bin/check-portable-null-device` is a static, fail-closed guard over the
governed production PHP surface. That surface is every repository PHP file (a
`.php` file, or a file that opens with `<?php` or a PHP shebang) except tests
and their support code (`tests/`, `packages/<pkg>/tests/`,
`packages/<pkg>/testing/`, `packages/<pkg>/e2e/`, `skeleton/tests/`),
`benchmarks/`, `docs/`, `kitty-specs/` and vendor trees. The guard enumerates
files through Git (tracked files plus untracked, unignored ones) and inspects
every string token that spells `/dev/null`. Comments are documentation and are
ignored.

- A direct descriptor, `['file', '/dev/null', ...]` in any array spelling, is
  always rejected. No classification can accept one.
- Every other occurrence must be classified in
  [`tools/portable-null-device-classifications.json`](../../tools/portable-null-device-classifications.json)
  by file, enclosing symbol (`Class::method`, a function, a class, or `{main}`)
  and literal, with its exact occurrence count, one purpose and a one-line
  rationale. The anchor has no line number, so an unrelated edit cannot make it
  stale.
- The purposes are mutually exclusive shapes of the occurrence's statement, so
  a classification cannot claim the wrong one:

  | Purpose | Shape the guard requires |
  |---|---|
  | `platform-derived` | The statement also names the Windows `NUL` device and a Windows host signal: `PHP_OS_FAMILY`, `PHP_OS`, `DIRECTORY_SEPARATOR`, a Windows-named identifier, or a string naming Windows. |
  | `semantic-diff-marker` | Unified-diff data that is never opened: a `--- /dev/null` or `+++ /dev/null` header, or a bare `/dev/null` label beside an `a/` or `b/` label. The statement has no `NUL` counterpart and the literal is no redirection. |
  | `posix-only-shell` | A shell redirection to `/dev/null` (`2>`, `>`, `>>`, `&>`, `<`) with no `NUL` counterpart, in code the classification declares POSIX-only. |

- An unclassified literal fails, and so do stale, duplicated, malformed,
  unsorted and overly broad classifications. Stale means the occurrence is gone
  or fewer remain; overly broad means a pattern, a directory or a wildcard
  symbol. Each diagnostic names the file, line, symbol and literal. For an
  unclassified literal it also names the purpose its shape fits, if any, and
  the exact entry it would need.
- The scan normalizes line endings and has no host-specific branch, so it runs
  identically under native Windows and Linux PHP.

The guard does not decide which PHP is portable. `platform-derived` and
`posix-only-shell` are reviewed assertions about the surrounding code; the
guard checks only their shape. It matches the spelling `/dev/null` in string
tokens, so a device path assembled at run time (by concatenation, escape
sequences or `sprintf`) is outside it. A hard-coded `NUL` on POSIX is also out
of scope: Linux CI fails such code at once.

The guard is one more `gate` command in the contract. Both existing
`native-host-contract` leaves run it as a step and publish its result in their
evidence records, and `tests/Architecture/PortableNullDeviceGateTest.php`, whose
cases are mutants of the tracked sources and classifications, joins the
contract's Architecture selection as well as the Linux Architecture shards. No
job, required context or `merge/*` name is added. One full scan took about
1.6 s on a native Windows 11 workstation; the change record keeps the local and
hosted measurements.

A branch-protection adapter decides which named CI checks enforce this policy;
the adapter must be audited rather than inferred from this prose. A check cannot
be called proof for a surface it does not invoke.

The following may remain nightly or explicitly invoked because of cost, while
remaining useful evidence:

- additional native Windows OS/version matrices;
- full native Windows PHPUnit, random-order, or split-package matrices;
- Windows browser/E2E and full admin browser matrices;
- mutation testing, long random-order replay, and large shard timing studies;
- release, split, deployment, registry-publication, and hosted promotion
  rehearsals; and
- extra Linux distributions, architectures, SAPIs, filesystems, or databases.

Moving a nightly surface into the required PR contract requires a change record,
an owner, exact runner/runtime evidence, and review of this document. A green
nightly job does not silently widen the support target or become verified
native evidence for commands it did not execute.

## Accepted platform exceptions

These exceptions are intentional and bounded. Each has an owner and the same
first review/expiry date.

Renewal is fail-closed for every row. Before expiry, the named owner must:

1. supply current, exact evidence showing which entrypoints still require the
   exception and which native host checks cover adjacent supported behavior;
2. record an accountable keep, narrow, replace, or remove decision in a stable
   change record;
3. set a new review/expiry date no more than 90 days after that decision; and
4. update this inventory, recovery guidance, and any affected onboarding text.

If any criterion is missing at expiry, the exception is no longer accepted.
The owner must remove or port the host-specific surface, reclassify it as
unsupported with explicit user guidance, or escalate the unresolved support
gap to the parent program before claiming the candidate satisfies this
contract. A date-only renewal, silent extension, or evidence from WSL/Git Bash
is invalid.

| Exception | Owner | Review/expiry |
|---|---|---|
| Bash/sh adapters in root `bin/`, skeleton `.ci/site-verify`, and skeleton maintenance audit/deploy helpers remain POSIX-only convenience/internal automation. | CLI and skeleton maintainers | 2026-12-05 |
| Git hook installation and pre-push orchestration remain POSIX-only internal automation; native Windows contributors may use the portable checks and hosted CI. | Developer-tooling maintainers | 2026-12-05 |
| Release, split, artifact, project-board, worktree, shard, coverage, and hosted-governance helpers may require Bash, `gh`, POSIX locks, or Linux filesystem behavior. | CI and release maintainers | 2026-12-05 |
| The complete PHPUnit/random-order/split-package proof remains Linux-owned while targeted Windows boundary tests cover selected portability seams. | Test infrastructure maintainers | 2026-12-05 |
| Windows native serving, FrankenPHP, `php -S`, Playwright, and full admin E2E remain outside the verified Windows evidence even when the command itself is reachable through Composer/PHP. | Runtime and CI maintainers | 2026-12-05 |
| WSL and Git Bash may be documented as optional workarounds for POSIX automation but cannot satisfy the native Windows evidence requirement. | Documentation and contributor-experience maintainers | 2026-12-05 |

## Unsupported claims

This contract does not support or certify H1 multi-node operation, remote/shared
filesystems, MySQL/PostgreSQL, WebKit/Safari, unlisted web runtimes, arbitrary
Linux distributions, arbitrary Windows releases, native Windows production
serving, or a full native Windows framework test run. Those boundaries follow
the current S1 contract and the actual evidence described above.

## Ownership and review

Framework maintainers own this contract. CLI/runtime maintainers own portable
PHP entrypoints; skeleton maintainers own consumer scaffolding; test
infrastructure maintainers own launcher classification; CI/release maintainers
own host-specific automation; AI/MCP maintainers own local AI and protocol
surfaces. Every owner must review affected rows when changing an entrypoint,
runner, runtime constraint, security boundary, generated artifact, or recovery
path.

Review this document at least every 90 days, at every tagged release, and
before any support-reducing runtime transition recorded by
[`support/s1-v1.json`](../../support/s1-v1.json). Update the change record and
changelog fragment with any target expansion, exception renewal, or removal.
