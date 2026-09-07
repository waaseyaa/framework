# FW-NATIVE-HOST-SUPPORT-01 — native Windows/Linux host contract

- Parent program: #2676
- Work package: #2677
- Scope: documentation contract and entrypoint inventory only
- Owners: Framework maintainers; implementation follow-ups are #2678–#2681
- Status: design recorded; implementation evidence is intentionally separate

## Outcome

Define one durable support contract for native Linux and native Windows
development/verification entrypoints. The contract names the supported runtime
targets, distinguishes verified native behavior and normative portable targets
from host-specific internal automation, and records unsupported surfaces
without implying that WSL or Git Bash are native-host evidence.

This slice changes no runtime, CI, Composer, `bin/`, or root agent contract
files. The enduring contract will live in
[`docs/specs/native-host-support.md`](../specs/native-host-support.md), with
onboarding links from the root and skeleton READMEs.

## Finding and discriminating reproduction

Before this slice, the repository had useful but distributed evidence:

- `support/s1-v1.json` defined the authoritative Linux framework test point
  while consumer serving certification remained pending.
- `.github/workflows/ci.yml` contained targeted `windows-2025` development-host
  jobs, but their boundary was primarily explained in workflow comments.
- `skeleton/.ci/README.md` documented one portable verification entrypoint.
- No enduring native-host support spec inventoried Composer scripts, root
  `bin/` commands, generated maintenance commands, test launchers, local AI
  commands, and MCP launchers together.
- The root and skeleton onboarding READMEs did not link to such a contract.

The missing contract is reproducible without a runtime mutation:

```text
test ! -f docs/specs/native-host-support.md
! rg -n 'native-host-support' README.md skeleton/README.md
```

The discriminating post-change proof is the inverse: the spec exists, both
onboarding links resolve, and every inventory row names a disposition, owner,
and review/expiry rule where an exception is accepted.

## Decisions

1. **Two host targets, one runtime identity.** Native Linux is the existing S1
   framework point (`ubuntu-24.04`, x86_64). Native Windows is the pinned
   `windows-2025` development/verification point already exercised by the
   targeted CI jobs. The normative tuple requires PHP 8.5, Composer 2.10,
   Node 24 where the command needs Node, and SQLite 3.40 or newer within the
   existing S1 bounds. Current Windows jobs prove PHP 8.5 and only their named
   command/test surfaces; they do not record exact Composer, Node,
   SQLite-library, or architecture evidence.
2. **S1 remains the serving contract.** Native Windows evidence is not a
   production-serving claim. The existing S1 serving/runtime contract remains
   Linux-only, and the Windows jobs must not grow FrankenPHP, `php -S`,
   Playwright, or full-framework serving claims without a separate contract
   change.
3. **Portable requires a shell-independent boundary and native evidence.** A
   normative portable entrypoint uses Composer's PHP, `php`, Node, or a
   transport-neutral protocol rather than requiring a POSIX shebang. It becomes
   verified native-portable only when discriminating Linux and native Windows
   evidence executes that exact surface. Source language or a shebang alone is
   insufficient.
4. **Material equivalence is capability-preserving.** Host-specific output
   formatting and path separators may differ. Capabilities, exit semantics,
   security boundaries, generated state, and recovery guidance may not.
5. **Exceptions are explicit, expiring, and fail closed.** POSIX-only release,
   hook, shell, and selected CI/test automation is internal automation, not a
   native consumer support failure. Every accepted exception names its owning
   maintainer role and review/expiry date. Renewal requires current evidence,
   an accountable recorded decision, and a new date no more than 90 days away;
   otherwise the exception is removed, ported, reclassified unsupported, or
   escalated to the parent program.
6. **Evidence is surface-specific.** A targeted Windows job proves only the
   commands and boundary tests it invokes. It does not prove the full PHPUnit,
   browser, server, release, or deployment surface. WSL and Git Bash are
   optional contributor environments and cannot substitute for native Windows
   evidence.

## Inventory design

The spec will group exact repository paths and command families under:

- Composer root and skeleton scripts;
- root `bin/` entrypoints by implementation/runtime;
- skeleton `.ci/`, `bin/post-create-setup.php`, and generated maintenance
  commands;
- PHPUnit, Vitest, Playwright, and CI launcher boundaries;
- local AI-development and Bimaaji commands; and
- HTTP MCP versus local stdio MCP launchers.

The inventory will identify the portable contract first, then list host-specific
internal automation and unsupported claims. It will not claim an untested
Windows version, Linux distribution, browser, SAPI, database, filesystem, or
server.

## Independent review repair

The initial draft inferred portability from PHP/Composer entrypoints and
overstated current Windows evidence. Review established the following concrete
repairs:

- root `dev`, `dev:php`, and `dev:admin` are currently POSIX-only because they
  use `NAME=value` syntax and/or the Bash `bin/waaseyaa` wrapper;
- `dev-runtime` and `dev-runtime-consumer` are WSL2/POSIX-only, including
  absolute POSIX paths, `HOME`/`XDG_CACHE_HOME`, `/dev/null`, `tar`, symlinks,
  and `chmod`;
- `check-pr-preflight` is a PHP coordinator over a roster containing Bash
  gates and is therefore not native Windows portable;
- the complete top-level `bin/` inventory is 93 entries, including
  `check-distribution-exclusion`, `check-landing-base`, `check-skeleton-docker-secret-exclusion`,
  `check-vendor-fresh`, and `worktree-coordinator`, with no duplicate
  `verify-k1-delivery-cutover`;
- the skeleton onboarding link must survive publication at repository root, so
  it uses the stable Framework repository URL; and
- `composer require --dev` resolves dependencies and updates the lock rather
  than consuming an unchanged locked graph.

## Verification plan

Focused verification for this docs-only slice:

1. Validate Markdown links from both onboarding READMEs to the new spec.
2. Run the existing support-contract checker to ensure the documented target
   does not conflict with `support/s1-v1.json`.
3. Run the focused architecture tests covering support contract and skeleton
   verification parity.
4. Check the inventory against the current Composer manifests, `bin/` shebangs,
   skeleton launchers, CLI command providers, and MCP/AI package entrypoints.

Full-framework qualification, CI edits, commits, pushes, and merges remain
outside this work package and are owned by the root lane.

## Review checkpoint

Root review must confirm:

- the spec does not widen S1 serving support;
- native Windows evidence is limited to the named targeted jobs;
- every accepted exception has a named owner and review/expiry date;
- the README links are narrow onboarding links; and
- #2678–#2681 can implement or extend the contract without rewriting its
  authority boundary.

## Focused verification

The docs-only candidate currently has this evidence:

- `composer install --no-interaction --no-progress` — pass from the committed
  lockfile; no Composer update was run.
- `php bin/check-support-contract` — pass.
- `php bin/changelog-fragments validate` — pass; 93 fragments valid.
- `php bin/check-changelog-shape` — pass.
- `php bin/check-portable-paths` — pass; 8,617 tracked paths accepted.
- `php -d memory_limit=1G ./vendor/bin/phpunit --testsuite Architecture
  --no-coverage --filter 'S1SupportContractTest|SiteReferenceConsumerContractTest'`
  — pass; 3 tests, 88 assertions.
- Deterministic Markdown-link and inventory probe — pass; all 93 tracked
  top-level `bin/` entries appear exactly once, every Composer script is
  classified, the root relative link resolves locally, and the skeleton uses
  the publication-safe full Framework URL.
- `bin/git diff --check` — the refreshed check first found trailing spaces in the metadata block; the block was reformatted as bullets and the rerun passed.

The complete Unit/Integration/Architecture qualification, CI publication, and
merge remain root-lane responsibilities.
