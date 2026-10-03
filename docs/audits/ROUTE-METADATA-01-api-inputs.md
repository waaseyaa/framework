# API exposure input handoff, ROUTE-METADATA-01

Scoped prerequisite review, 3 October 2026. Base:
`d2274f91a7da40cccabc935f25fc15c8bc7e35c2`. Owner: Framework integration.
This is a route-input seam assessment, not a package-wide API convergence claim.

## Observed authority and dependencies

`ApiServiceProvider::register()` binds `EntityTypeApiExposurePolicy` as a shared
service. Its factory reads the canonical EntityTypeManager and application
configuration. `boot()` resolves that policy after provider/app registration.
`EntityTypeApiExposurePolicy::fromConfig()` copies declared exposure ceilings,
validates an optional closed allowlist and produces immutable effective booleans.
`effectiveMap()` is a memory-only getter. Existing policy tests distinguish absent,
empty, narrowing, duplicate, unknown and forbidden allowlist entries.

HTTP discovery and generic routes consume this policy. Recomputing it after boot
would create another snapshot of potentially different inputs. Capturing its
already admitted map during ordinary API boot preserves its authority. No policy
service resolution belongs in route projection or inspection.

Foundation already provides `KernelServicesInterface`, with concrete core-service
cases in `ProviderRegistryKernelServices`; no new container is needed. Provider
boot and after-all-provider finalizers precede AbstractKernel completion. The
kernel validates actual provider participation before admitting route authority.
`RouteInputProjector` copies only finalized entity metadata and supplied booleans.

## Bounded handoff design for review

Foundation owns one kernel-local `RouteExposureInputs` publication slot, exposed
through the existing kernel-services resolver during ordinary boot. This is
Waaseyaa input/lifecycle policy, not a generic event bus, container or resolver.
API publishes the scalar map from the exact policy instance it already resolves.
Bare provider construction without the slot retains existing behavior.

The slot accepts at most one publication. Invalid or duplicate publication
poisons metadata admission without changing ordinary boot retry behavior.
After finalization, the kernel freezes it against the complete entity roster and
the bootstrap-admitted presence of ApiServiceProvider. API presence requires a
publication. API absence requires no publication and explicitly yields false
exposure for the complete roster. Unknown/partial maps refuse. Post-freeze writes
refuse and cannot change the frozen contexts. Inputs contain only booleans and
entity IDs; no policy instance, manager, service factory or configuration survives.

Kernel shared/provider context access first applies the existing complete-boot,
restricted-profile and failed-boot custody guard. Projection occurs once during
successful runtime boot, after finalization, never on a getter. Failure leaves
ordinary legacy HTTP behavior intact but canonical input admission unavailable.
The slot and bridge sources participate in compiler identity at bootstrap.

## Acceptance and residuals

- State tests discriminate missing, duplicate, malformed, stale and late input,
  API absence, reference detachment and stable frozen data.
- API boot tests prove the published map equals the existing policy's narrowed
  decisions and that bare provider behavior remains compatible.
- Kernel fixtures prove early/restricted/failed access refuses, projection occurs
  once, returned contexts contain scalar data, and inspection cannot resolve a
  service or revisit definitions. API-installed boot must use the real provider.
- Existing API policy/route and provider bootstrap tests retain compatibility.
- Immutable independent review, scoped static checks and default preflight own
  source acceptance. Hosted and installed cohort proof remain separate.

This seam adds no route, changes no access decision, and performs no API execution.
API route migration still needs separate scoped assessment of optional search,
workflow service presence, handler registration, catalogs and JSON:API helpers.
Other API concerns and whole-package coverage-index milestones remain unchanged.
The handoff requires a compatible Foundation/API sibling cohort containing the
new contract; a published minimum floor is settled at release qualification.
No Go dependency, external mutation, release or deployment is authorized here.

Independent design review approved this bounded seam. Late or duplicate writes
must poison subsequent canonical getters even if contexts were already cached;
previously returned immutable values remain unchanged. API presence comes from
bootstrap-admitted provider records. The supported API provider is final, so its
exact FQCN is the presence discriminator; no subclass reflection is needed.
Missing/stale participation must refuse before the API-absent fallback. Roster
changes during finalizers must refuse freezing. These are implementation controls,
not broader package-convergence evidence.

Implementation candidate supplies the boot slot through the existing resolver,
captures the real policy once, and freezes kernel shared/provider contexts.
Focused exposure, API policy/routes, kernel, provider-bootstrap and immutable
metadata checks pass 149 tests, 435 assertions. Scoped PHPStan over six production
files reports no errors. The API boot test records exactly one definition-roster
read for policy construction, proving publication adds no second policy read.
Finalizer roster changes, missing API publication, late writes, admitted absence,
restricted/failed custody and definition-refresh poison controls pass.

Status: bounded implementation in source review; not package-wide convergence.

### Fresh-process purity and preflight repair

The first default preflight found two governance failures: the new memory SQLite test construction required the canonical construction roster update, and the provider registry handoff required a package-discovery specification update. Both are repaired with the canonical writer and substantive lifecycle documentation.

A separate-process kernel test then exposed `ScalarRouteMetadata` autoloading after provider finalization. Bootstrap participation compilation now admits known neutral protocol classes and the projector before projection begins. No constructors or contributor methods run during that admission. The autoload trap test passed after the repair. Focused evidence is 150 tests and 437 assertions; final preflight and bounded independent repair review remain pending.

### Reviewed local source checkpoint

Independent reviewer `review_rm02` approved the original immutable 20-file source candidate and the bounded 22-file repair snapshot. All snapshot and checkout hashes were verified before final bookkeeping. Repair review requested correction of the older package-discovery residual sentence, now updated to leave complete kernel source composition and consumer adoption pending. The fresh-process discriminator and 150-test focused suite pass; six production files pass scoped PHPStan.

Default preflight after repairs passed: 42 gates executed, one equivalent-input reuse, zero failures, zero hosted-required in the default selection, three not applicable, 127.4 seconds. Evidence: `boot-handoff-final-preflight.json` and `boot-handoff-repair-review/manifest.json` in the session evidence directory. A final default pass validates this documentation correction before the local commit. Exact-head hosted, consumer and installed qualification remain open, and the live Bimaaji failure remains unresolved.
