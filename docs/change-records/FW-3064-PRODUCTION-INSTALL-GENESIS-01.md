# FW-3064-PRODUCTION-INSTALL-GENESIS-01 — Restricted production install without live CFG-02 authority

Status: implemented (harness reliability repair candidate)

Issue: #3064 (regression of #2428 / PR #2431; blocks waaseyaa/studio#36)

Parent: `494deddf6081f7c8551f00e25c8ef56123d3b71f` (`origin/main`)

Runtime candidate: `06d45e45cd7d77249de3bf17042a1ab36cc5e8d1`

Lease: `c8a14ab966e1bddf8d0f72cc9bafce67` (root/cursor-auto)

Branch: `cursor/3064-production-install-genesis`

## Problem

On a fresh SQLite database with `APP_ENV=production`:

1. `waaseyaa db:init --no-sync-schema` applies migrations and succeeds.
2. `waaseyaa install:init` exits 1 with
   `ConfigurationAuthorityUnavailableException` before genesis activation.

Root cause: `ProviderRegistry::discoverAndRegister()` always runs
`CapabilityRegistry::validate()`, which invokes
`ConfigurationAuthorityServiceProvider::capabilityDeclarations()`. That
declaration calls `requireActiveGenerationId()` outside explicit development
profiles. Restricted discovery (`bootForSchemaSync()` for `install:init`) still
registers providers and therefore still performs that live capability
publication — recreating the #2428 circularity at the capability layer rather
than the active-store constructor.

Physical reproduction (Studio #36) used an exact Framework cohort, fresh
ext4-backed volume, and `APP_ENV=production`. `APP_ENV=local` is not an
acceptable workaround (#2428).

Independent review of `06d45e45` accepted the runtime fix and exact-head
archived-consumer semantics, but rejected the packaged harness because
Composer/CLI/probe children had no bounded deadline and EXIT cleanup did not
reap a stalled process group before tree removal.

## Decision

Narrow the discovery/runtime boundary:

- **Restricted definition discovery** (`restrictedDiscoveryOnly` /
  `bootForSchemaSync`) registers providers and discovers entity types /
  migrations, but **does not** validate live authority-dependent runtime
  capabilities.
- **Ordinary production boot** still runs capability validation and still
  refuses when no active generation exists (`capabilityDeclarations()`,
  mutation storage wrap, and access-path enforcement from #2426/#2428).
- Genesis CAS, deterministic replay, audit marker, competing-generation
  refusal, and package layers are unchanged.

Packaged harness reliability (follow-up to independent review of `06d45e45`,
custody repair after `f0794db`):

- Shared `tests/PackagedForm/lib/run-bounded.sh` imposes explicit deadlines,
  TERM then KILL (`timeout --kill-after=5s`), deterministic status-124
  diagnostics, and EXIT reaping before `rm -rf`.
- Process-group custody uses bash monitor mode so the leader PGID equals its
  PID deterministically; adoption refuses the caller's group. Surviving
  descendants are reaped after every leader exit, including exit 0, and
  custody clears only once the owned group is gone.
- Exact-head `bin/git archive --format=tar "$candidate"` semantics are preserved.
- Production code is unchanged by the harness repair.

Rejected alternatives:

- Treating `APP_ENV=local` as production installation authority.
- Weakening `requireActiveGenerationId()` on ordinary production capability
  publication or access paths.
- Broadly skipping provider registration during install.

## Work packages

1. Stable change record + unreleased changelog fragment.
2. Discriminating packaged production regression:
   `APP_ENV=production db:init --no-sync-schema` → production boot refuses →
   `APP_ENV=production install:init` → exactly one genesis → idempotent replay →
   production boot/read succeeds.
3. Unit proof that definition-only registration skips capability validation
   while ordinary registration still runs it.
4. Kernel wiring: pass `validateCapabilities: !$restrictedDiscoveryOnly` into
   `ProviderRegistry`.
5. Spec updates for infrastructure / package-discovery / cli-kernel contracts.
6. Focused verification + preflight; coherent review candidate commit.

## Out of scope

- Studio #36 physical Docker/ext4 requalification (consumer evidence after
  Framework cohort lands).
- `#2787` site:init blueprint compilation.
- `#2430` verified `config:import` production wiring beyond existing genesis.

## Verification targets

- New packaged harness and Architecture gate for the exact production sequence.
- ProviderRegistry / restricted-discovery unit discriminators.
- Existing #2426/#2428 genesis, authority, and fresh-install proofs remain green.
- `php bin/check-pr-preflight` and practical Unit/Integration/Architecture coverage.
