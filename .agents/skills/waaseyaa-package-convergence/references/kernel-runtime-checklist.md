# Kernel and runtime checks

Use for packages owning stateful composition, provider discovery, service registries or kernel entrypoints. Review actual supported entrypoints and profiles before treating every hypothetical transition as a defect.

## Lifecycle and state

- Map first construction, discovery/register, boot/finalization, service resolution and shutdown. Identify when provider lists, entity definitions and other inputs become complete.
- Trace early service resolution and recursive resolution. A lazily built singleton must not freeze incomplete registration into a permanent snapshot.
- Exercise supported repeated calls and sequential executions. Check retained request/account/tenant state and request-context separation from reusable definitions.
- For failed boot, inspect state retained by each completed phase: frozen registries, singleton caches, registrations and readiness flags. Prove a clean retry or an explicit terminal-state refusal without weakening frozen-state protections.
- For restricted-to-runtime or other profile transitions, establish whether reuse is supported. Test supported transitions or explicit refusal. A single ready boolean is insufficient evidence that all profile-specific services were finalized.
- Compare documented eager/deferred provider behavior with observed registration and boot. A mismatch may require correcting the promise rather than implementing new laziness.

## Composition and installation

- Trace the same capability across HTTP, CLI, workers and restricted discovery only where those profiles claim support. Name the canonical composition owner and any divergent copies.
- Test a nonempty application provider through the real composition root. A manually injected service proves its consumer, not that the kernel supplies it.
- Identify supported primitive-only, split-package, kernel-composition and metapackage installations. Architecture exceptions do not prove dependency closure; an undeclared higher-layer import is not automatically a missing hard dependency for every profile.
- Record unavailable dependencies and unsupported profiles explicitly. Inspect failure timing and diagnostics, including whether construction fails before a handler's error boundary.
- Review public manifest/read models and discovery APIs against real dynamic and downstream consumers. Do not remove an apparently unused discovery surface based on repository search alone.
- Reconcile stability promises, public-surface declarations and serialized/read shapes. A missing declaration is unclassified, not implicitly internal; compatibility must be established from the actual promised and consumed contract.

## Evidence

Use disposable consumer fixtures for lifecycle probes. Label synthetic providers and in-process reuse separately from production reachability. Separate component tests, real kernel boot, process restart and installed-package qualification. Record environment-specific teardown or resource limitations without declaring a green baseline or silently treating them as runtime defects.
