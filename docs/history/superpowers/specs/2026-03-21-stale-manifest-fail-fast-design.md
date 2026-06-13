# Stale Manifest Fail-Fast Design

**Goal:** Make Waaseyaa fail fast when `storage/framework/packages.php` references missing provider classes, while ensuring `optimize:manifest` can rebuild discovery artifacts without booting the stale provider set first.

## Problem

Waaseyaa currently treats a stale provider manifest as a soft warning during console and server boot. In practice this creates misleading behavior:

- boot may continue with the wrong app shape
- `route:list` can report `No routes found.` even when route code exists
- `optimize:manifest` can repair the system, but only after first trying to bootstrap stale providers

This violates the framework's deterministic-boot invariant.

## Approved Direction

- Boot remains strict and deterministic.
- Missing provider classes in the cached manifest become a targeted, first-class stale-manifest failure.
- Recovery is explicit through `optimize:manifest`.
- `optimize:manifest` must run in a degraded state without normal provider boot.

## Desired Runtime Behavior

### Normal boot

- The kernel loads the cached package manifest.
- Before provider instantiation, the kernel validates provider class existence.
- If all providers exist, boot proceeds normally.

### Stale manifest boot

- If one or more provider classes from the cached manifest are missing, boot stops immediately.
- The failure is represented by a dedicated exception type: `StaleManifestException`.
- The operator-facing error must include:
  - the missing provider class or classes
  - the manifest path
  - the remediation command: `php bin/waaseyaa optimize:manifest`

### Manifest recovery

- `optimize:manifest` must not require successful boot of the current provider list.
- It should run in a minimal console mode that has filesystem and composer metadata access, but not normal provider compilation/boot.
- It recompiles the manifest atomically and exits with a clear success/failure message.

### Non-recovery console commands

- Commands such as `route:list` should fail with the stale-manifest error instead of continuing into misleading empty output.

## Architecture

### 1. Dedicated stale-manifest exception

Create `Waaseyaa\Foundation\Discovery\StaleManifestException` with structured context:

- `missingProviders`
- `manifestPath`
- `recoveryCommand`

The message contract should be stable enough for tests and operator documentation.

### 2. Manifest validation at the discovery boundary

Add explicit provider validation near manifest loading rather than burying it in provider instantiation. This keeps the failure attached to the manifest artifact, not to arbitrary downstream bootstrap code.

### 3. Minimal console bootstrap path

Refactor `ConsoleKernel` so it can register and run a recovery-safe subset of commands before full app boot succeeds. `optimize:manifest` must be reachable even when the cached manifest is stale.

This path should avoid:

- provider instantiation
- route registration
- event-driven boot work
- command discovery from app providers

### 4. Atomic manifest regeneration stays unchanged

`PackageManifestCompiler::compileAndCache()` already uses temp-write plus rename. Keep that behavior and build recovery on top of it rather than introducing alternate write paths.

## Testing Requirements

- cached manifest with a missing provider class throws `StaleManifestException`
- the exception message includes remediation instructions
- `optimize:manifest` can run and rewrite the manifest without successful full boot
- after regeneration, the stale-manifest condition clears
- `route:list` and similar commands fail with stale-manifest diagnostics instead of misleading empty output

## Non-Goals

- no boot-time auto-healing
- no new `recover:*` command surface in this change
- no mutation of manifests during normal kernel boot
