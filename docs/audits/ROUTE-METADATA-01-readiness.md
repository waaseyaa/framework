# ROUTE-METADATA-01 readiness and execution ledger

2 October 2026. RM-01 evidence captured; not live production qualification.

- Integration and implementation owner: Codex root, isolated native Windows
  checkout `C:/dev/waaseyaa/framework-worktrees/bimaaji-route-wiring`, branch
  `codex/bimaaji-route-wiring`, base `325c405a6`.
- Windows Git: `C:/Program Files/Git/cmd/git.exe`; PHP 8.5.5; candidate-local
  dependencies installed from the lock, no borrowed autoload/vendor tree.
- Framework lock SHA256:
  `c879487007897c66be2f8eb6b412a40da14319c42ca664e8802843563b22ef03`.
- Preserved design/plan: `d2c47f5d493c4b98018d50de5ac2870cc03ea20f`;
  eight-file design/red checkpoint: `401a571cf081db3d940898bac2ffe2d4a0ec22aa`.
  The unchanged design, audit, record and implementation plan were copied into
  this candidate so implementation has repository-portable authorities.
- Against the audit base `5196f173b`, no route producer, registrar, provider
  contract, Routing or Bimaaji production delta was found. HttpKernel has unrelated
  later changes; integrate at current candidate bytes rather than replaying its old
  file. Other changed CLI management/search surfaces remain outside this slice.
- Native worktree coordinator still rejects Windows absolute paths; recorded
  ownership replaces no check and grants no publication authority.

## Consumer cohort

The local `C:/dev/fetder/app` checkout remains alpha.302 and is not the release29
baseline. Used the archived installed qualification artifact at
`C:/Users/jones/Documents/Codex/2026-10-01/finish-the-authorized-fetder-release-first/work/fetder-alpha303-native`.
It has no Git checkout identity. Its application provider Git blob
`28d4b1df3dbeb7e79ee8c02b79a06a8ff30a01be` equals the GitHub content blob at
`8238019eb25334f8ec87e5c0967be3e3355176cd`. The remote commit lookup also confirms
that serving SHA and its alpha303 adoption message. This verifies the provider
source comparison, not every archived file or a current live manifest.

Archived application lock SHA256:
`610e3c489e258a0c1035d087b95b5b323cdc52d8ca72c13c61caf7ddc266b94d`.
Reflection over the archived installed manifest performed no provider
instantiation, boot or route-hook invocation: 60 declared providers, 55 with the
inherited Foundation no-op and five actual legacy contributors:

| Contributor | Migration owner |
|---|---|
| AdminSurfaceServiceProvider | admin-surface, existing #3083 and #3122 entry |
| ApiServiceProvider | api, #3122 |
| AuthOidcRouteServiceProvider | routing, #3125 |
| SsrServiceProvider | ssr, #3122 |
| FetderServiceProvider | FETDER application, consumer #145 |

The application hook eagerly resolves account, discovery, member and operator
controllers and constructs profile/curation/member-administration services.
Replacing only the missing bus binding cannot meet metadata purity.
The cohort must be rechecked against the actual installed candidate before
consumer acceptance. No production process, state or credential was changed.

Machine inventory: session evidence
`C:/Users/jones/Documents/Codex/2026-10-02/bimaaji-route-wiring/fetder-provider-inventory.json`.
Other Framework contributors remain release-profile-dependent follow-up work;
canonical inspection may not omit an installed legacy provider.

## Ready slice and evidence owners

RM-02 owns new Foundation metadata values and their focused tests only.
Foundation defines the data/lifecycle boundary; Routing will compile Symfony
routes in RM-04. Symfony's installed RouteCollection supports priority ordering
and stable ties directly; do not introduce another generic routing engine.
Existing Foundation CanonicalJson supplies deterministic map ordering. Identity
uses a route-owned tagged projection before that ordering to retain list/map and
integer/string-key distinctions, then JSON preserves finite float types. The
SchemaDiff encoder's existing stable checksum behavior is unchanged. Waaseyaa must
still validate its closed handler identities and prohibit live/secret-bearing
metadata: Symfony's mutable Route/RouteCollection accepts live object defaults
and cannot provide that contribution contract.

RM-02 acceptance: copied deeply immutable scalar data, safe stable handler IDs,
lossless route fields and explicit invalid-value refusals, with zero class loading
or controller execution. Class/service existence is checked by later declaration
admission, never by these values. Non-secret input selection belongs to the
projection owner; arbitrary plaintext cannot be identified as secret by its bytes.

Use focused metadata PHPUnit tests and the relevant layer/public-surface checks.
No full local baseline. Independent review: scoped reviewer subagent after an
immutable candidate snapshot; required by the package-convergence skill. Root owns
local integration proof; `ci/full-qualification` owns final hosted evidence.
Supported Linux qualification is not dispatched or claimed at this checkpoint.
Review and hosted completion remain acceptance gates, not a reason to block value
implementation. RM-03 through RM-05 will use the same owners and staged review.

## Ticket state

| Ticket | State | Remaining evidence |
|---|---|---|
| RM-01 | done for readiness scope | Independent readiness review accepted its documented limits; final installed cohort recheck belongs to consumer acceptance. |
| RM-02 | done for source contract scope | Independent review, focused tests, scoped static analysis and default local gates passed. Installed/hosted consumer proof belongs to later tickets. |
| RM-03 | in progress | Manifest persistence and kernel admission implemented; finalized route input projection and whole-source epoch composition remain open. |
| RM-04 through RM-05 | planned | Symfony compilation, deferred declared-handler admission, built-ins/terminal sources and HTTP compatibility bridge. |
| RM-06 through RM-14 | planned | Dependent consumer migration and distribution/release evidence. |

## Implementation checkpoint

The values tests first failed against absent classes. Epoch tests likewise began
with six missing-class errors. Current focused metadata tests pass: 51 tests,
107 assertions on PHP 8.5.5 and candidate-local PHPUnit 13.1.14. The existing
ServiceProvider contract tests also pass: five tests, 62 assertions.

Independent immutable reviews requested repairs for global-class callable
arrays, list/map identity collisions, numeric-string-key ordering, and retained
readiness array references. Each has a focused discriminator and repair. Final
immutable values and standalone lifecycle review approved those repairs; the
worktree/snapshot integrity check passed. Anonymous providers are additionally
refused before their source-bearing class names can enter provenance. That
two-file delta received separate independent approval and an integrity pass.
No legacy hook or
handler is invoked in the focused metadata tests. Tests additionally distinguish
changed parent, trait and interface provenance, alias capture, source/order and
ordinal admission, duplicate publication, swallowed recursion and terminal retry.

Initial default preflight ran 43 gates in 233.2 seconds with one failure:
infrastructure and package-discovery spec coupling. Both specs now document the
new standalone boundary and explicitly pending runtime adoption. That run
predates the final repairs and is diagnostic evidence, not final qualification.
Hosted and installed-consumer acceptance remain open. The five required legacy
contributors and Bimaaji's live wiring failure are not marked repaired.

A second default preflight passed (42 gates executed, one equivalent-input reuse,
zero failures, three not applicable, 124.6 seconds) before the final anonymous
admission and static-analysis delta. Focused PHPStan then exposed three overly
narrow untrusted-input annotations and a reentrant-state inference problem.
Inputs now document and validate raw arrays; publication explicitly requires
the collecting state after contribution execution. Focused PHPStan reports no
errors without new ignores or baseline entries. Combined tests pass 56/169.
The final static-analysis repair delta was independently approved; its integrity
check passed. Prior values and standalone lifecycle approvals remain valid.

Final source checkpoint preflight: 42 gates executed, one equivalent-input reuse,
zero failures, three not applicable, 125.4 seconds. Tested base HEAD
`325c405a66741c4e2f7c21f0b56e2ebf46832d78`, index tree
`2caf41fe57c9a168f7b823e9e06f74adea8b1fff`, dirty-worktree SHA256
`7a22cd9ebd4ab24bab21da192be17e660b783e19db840942989d594a8314adb5`.
After that run, only this ledger's review/status/evidence prose changed; source,
tests, dependency lock, contracts and gate configuration remain byte-identical.
Document consistency checks and the normal pre-commit hook validate that final
bookkeeping delta. This is a local source checkpoint, not full-profile, hosted,
installed, published or deployed qualification.

Session evidence is under
`C:/Users/jones/Documents/Codex/2026-10-02/bimaaji-route-wiring`:
`foundation-focused-qualified.log`, `foundation-preflight-qualified.json`,
immutable review manifests and their checked integrity snapshots. Independent
review was performed by `review_rm02`; test/static/gate execution was owned by
the root integrator. Public-surface aggregate changes were generated from the
Foundation declaration. No issue was closed and no release version was changed.

## Next ready slice

Finalize whole-source kernel readiness and the Routing/HTTP bridge. Participation,
memory-only projection and the boot exposure handoff are now implemented, while
a completed kernel graph remains unproven. Do not copy the entire application
configuration into declaration contexts. Continue only from the reviewed source
and lifetime contracts; real built-in/provider/terminal admission remains required.

## Kernel participation checkpoint

Continued from local commit `589d0359d9b8f6684cb68b542f30d537fa8ac05a`.
The manifest now serializes raw route participation, preserving it across cache
loads and root-provider re-merges. Bootstrap validates the token before provider
registration and compares its ordered roster to the providers actually admitted.
Only complete runtime boot exposes it. Missing/stale inventories, partial active
rosters, restricted-profile reuse and previously failed boot refuse route
authority. Ordinary kernel retry behavior remains compatible; a retry cannot
revive its failed route authority. A fresh kernel can admit the repaired boot.

The accessor returns participation evidence, not a completed route snapshot.
No Bimaaji bus binding or legacy route hook invocation was added. Source hashing
remains at compile/bootstrap; a test removes the provider source after boot and
proves repeated accessor calls retain the admitted token without refresh.
Compiler identity also binds manifest/kernel/bootstrap source, so a changed
bootstrap implementation cannot admit an inventory from different code.

Focused manifest/cache/kernel-route and standalone epoch checks pass:
131 tests, 345 assertions. Scoped PHPStan over the changed manifest and kernel
files reported no errors. Kernel tests cover early access during finalization,
stale and legacy cache records, profile custody, failed boot and ordinary retry,
fresh-kernel recovery, registered-roster mismatch and no inspection source reads.

The first combined run including AbstractKernelTest had a Windows SQLite WAL
file-lock teardown error in its existing production-preflight test (31 tests,
92 assertions, one error). That error also reproduced with AbstractKernel source
from `589d0359d`, loaded through a separate bootstrap against current candidate
dependencies (one test, two assertions, one error). This is a scoped prior-Kernel
comparison, not a pristine prior-revision full qualification. Supported-host
qualification still owns this test; no test or database-cleanup workaround was
introduced. Supported-host full qualification remains open.

Independent review found a P2 cache-custody defect: a scalar participation field
entered generic corrupt-cache recovery and was silently replaced with fresh
valid inventory. A failing real-cache test reproduced the replacement. Route-only
shape errors now normalize to an unavailable marker without changing the cache
file. The discriminator boots through scalar, missing-field and malformed nested
inventory controls with the valid fingerprint retained; all refuse admission and
preserve cache bytes. Focused checks now pass 132 tests, 351 assertions.
Independent reviewer `review_rm02` approved the repair and its affected boundaries
against the immutable nine-file candidate. The checked custody snapshot digest
is `804a8fd22337ca0b2c79df88735f58502905399464cda4b5a248c1ef93e2628c`.

Final default preflight passed: 43 gates executed, zero reused, zero failures,
three not applicable, 133.7 seconds. Tested base HEAD
`589d0359d9b8f6684cb68b542f30d537fa8ac05a`, index tree
`ae0235abf08e3a14dd351afd84f0afd549038f76`, dirty-worktree SHA256
`7bdcd9b5abb673ed01533bb9892bceecf3b8bc241c7fb021e1019f06b2f5f2b5`.
The final focused rerun after formatting again passed 132 tests, 351 assertions.
Only this ledger's evidence prose changed after those checks; source, tests,
dependency lock and gate configuration remain unchanged. Normal commit hooks
validate the bookkeeping delta. Evidence is retained beside the earlier source
checkpoint under `rm03-kernel-preflight.json` and the immutable repaired review
manifest. This is a local source checkpoint, not hosted, installed or deployed
qualification. Kernel whole-source composition and consumer adoption remain pending.

## Whole-source composer checkpoint, 3 October 2026

Continued from local commit `70ce34edf1d10c29e225e070ce795850f90ec9ec`.
Owned scope is the standalone Foundation epoch, its focused tests, the route
metadata contract and implementation plan, this ledger and the slice fragment.
The epoch now admits immutable built-in and terminal declarations around the
ordered provider cohort, freezes selected shared scalar declaration inputs,
includes all source IDs in identity and sorts by descending priority with stable
collection ties. Cross-source duplicates poison publication. An actual legacy
provider refuses the entire graph even if static declarations are available.
No provider hooks, controllers, service lookup or source discovery were added.

Four regression controls failed before implementation: missing static sources,
cross-source duplicate acceptance, shared inputs omitted from identity and
invalid static source admission. The values/epoch suite now passes 57 tests,
131 assertions. Scoped PHPStan reports no errors. The existing API exposure
policy remains the authority; it was inspected, not copied or changed.

This is source-set admission, not production kernel whole-source proof. Empty
static lists remain valid standalone fixtures. Finalized kernel entity/exposure
projection, real built-ins/terminal declarations, Routing compilation, HTTP
compatibility and canonical consumers are still pending. RM-03 remains in
progress. Independent reviewer `review_rm02` approved the immutable six-file
composer candidate, then the infrastructure-spec follow-up. All seven reviewed
file hashes were verified unchanged before final evidence bookkeeping.

The first default preflight had one failure: infrastructure spec drift. That
spec now records standalone source admission and its pending kernel boundary.
The final default preflight passed: 41 executed gates, two equivalent-input
reuses, zero failures, three not applicable, 120.2 seconds. Reused evidence binds
the original dirty candidate at base `70ce34edf1d10c29e225e070ce795850f90ec9ec`,
worktree digest `25395c75d8bd4e3356c036706df96ad0065b169eeb40e85fbddcff11d8756282`;
it does not claim those two checks executed again on the documentation delta.
Source and tests remain the approved bytes, with unchanged lock SHA256
`c879487007897c66be2f8eb6b412a40da14319c42ca664e8802843563b22ef03`.
Evidence and immutable review manifests are under
`C:/Users/jones/Documents/Codex/2026-10-03/bimaaji-route-wiring`, including
`composer-preflight-final.json`. Only this ledger's evidence prose changes after
final checks; normal hooks validate that bookkeeping delta. Hosted and installed
qualification remain open, with no publication or deployment.

The next projection slice must reuse `EntityTypeApiExposurePolicy`, whose
existing boot-scoped policy is computed after entity registration. Its
`fromConfig()` reads only the in-memory definition roster and declaration
metadata; `effectiveMap()` returns scalar decisions. Do not recreate its
allowlist logic in Foundation or resolve its service during route collection.
`EntityTypeManager::getDefinitions()` is the memory-only roster getter. Copy
only entity IDs, required bundle/path metadata and effective exposure decisions,
plus explicitly selected non-secret inputs and manifest capability facts.
Real built-in/terminal adoption must replace the registrar's authority through
the planned Routing adapter, not retain two independently maintained route lists.

## Memory-only input projector checkpoint, 3 October 2026

Continued from local commit `eb2c6e97488ca682945ed43f05be5625df4d9463`.
Owned scope is the internal Kernel `RouteInputProjector`, its compiler identity binding, its focused test,
the route/infrastructure specifications, implementation plan, ledger and fragment.
The adapter reads the in-memory definition roster once, checks supplied finalized
exposure-map keys and booleans, and copies only ID, bundle entity-type ID and
effective exposure. It returns an immutable shared context and derives pure
provider contexts in admitted order. Shared inputs remain available for all-noop
cohorts. No configuration is selected, and API policy is neither copied nor
recomputed. Kernel finalization and the provenance of supplied exposure/capability
facts remain caller responsibilities; no canonical kernel admission is claimed.

The test uses `EntityTypeApiExposurePolicy` to supply the effective narrowed map.
Initial missing-projector/method controls were red; an incorrect test constructor
argument was corrected before accepting positive evidence. Focused projector,
values, epoch and kernel participation checks now pass 75 tests, 188 assertions; scoped PHPStan is clean
after removing a redundant key type check and preserving integer provider order.
Poison manager/definition expectations verify zero storage, repository, field
resolution and execution-metadata reads. A measured projection with a poison
autoloader records zero loads. A throwing contributor fixture is never invoked.
Partial, stale, non-boolean and ID-mismatched exposure maps refuse safely; errors
carry no previous exception or private diagnostic. Referenced exposure and
capability inputs detach. Bundle metadata requires an identifier; unselected
configuration refuses instead of silently disappearing. Bootstrap compiler
identity includes the projector source without reading it during projection.
Independent review and final gates are pending.

Remaining integration: obtain the existing boot-finalized exposure map without
inspection-time service resolution, bind it to successful entity/exposure
finalization, and admit the complete kernel source set. Do not recompute a second
API policy after provider boot to fill this seam. RM-03 remains in progress;
HTTP/CLI/Bimaaji, installed qualification, publication and deployment remain open.

Independent review found a P2 roster-comparison defect: PHP's regular key sort
compared distinct valid numeric-like IDs numerically, so identical key sets in
different insertion orders could refuse. The `01`/`1e0` reverse-order discriminator
failed with unavailable inputs before repair. Both key arrays now use
`SORT_STRING`; the matching roster succeeds and existing stale/partial controls
still refuse. A nonzero shared-context source-order refusal is also explicit.
Focused checks after repair pass 77 tests, 191 assertions, with clean scoped
PHPStan. The first default preflight passed 43 gates with no failures, but its
source candidate precedes this repair and is not final-candidate qualification.
Independent reviewer `review_rm02` approved the repaired eight-file candidate,
closing P2. All snapshot and checkout hashes were verified unchanged before
final evidence bookkeeping. Final default preflight passed: 42 gates executed,
one equivalent-input reuse, zero failures, three not applicable, 123.1 seconds.
The reuse belongs to the original dirty candidate at base
`eb2c6e97488ca682945ed43f05be5625df4d9463`; it is not a fresh check on the repair.
Source/tests and the dependency lock remain the approved bytes. Only this
ledger's review/evidence prose changes after qualification; normal commit hooks
validate that bookkeeping delta. Evidence is in `projector-preflight-final.json`
and `projector-repaired-review/manifest.json` under the session evidence directory.
This remains a local source checkpoint. Exact-head hosted qualification and real
kernel/consumer/installed acceptance remain open.

## Kernel input handoff checkpoint, 3 October 2026

Continued from local commit `d2274f91a7da40cccabc935f25fc15c8bc7e35c2`.
The scoped API prerequisite and narrow handoff design were independently reviewed
before runtime edits; see `ROUTE-METADATA-01-api-inputs.md`. Foundation owns the
kernel-local publication slot and its existing resolver bridge. API owns capture
from the exact policy it already resolves during ordinary boot. No second policy,
container, general bus or execution resolver was introduced.

After finalizers and actual-provider roster validation, the kernel freezes the
slot against entity IDs and creates shared/provider contexts once. The final API
provider's exact admitted FQCN is the presence fact. Missing/stale participation
refuses before fallback; admitted absence yields false exposure for all IDs.
API-present without publication, malformed/duplicate maps and finalizer roster
changes refuse canonical inputs. Late publication poisons cached getters while
earlier immutable data remains unchanged. Input access retains early, restricted
and failed-boot custody. Ordinary legacy boot/retry and bare API construction
remain compatible. Protocol and resolver source now bind compiler identity.

The state and four kernel/API entrypoint controls first failed on the missing
handoff. Focused policy/routes, publication, kernel, provider/bootstrap,
projector and immutable metadata checks now pass 149 tests, 435 assertions.
Scoped PHPStan over six changed production files is clean. The real API boot
test observes one policy definition-roster read; no policy is recomputed for
publication. Definition-read poison after boot proves input getters never refresh.
New public-slot surface is declared in Foundation and aggregates are generated.
Independent immutable source review and final default preflight are pending.

RM-03 remains in progress until complete kernel built-in/provider/terminal source
admission is established. The input-supply seam is now implemented. Routing
compilation/handler resolution, HTTP bridge, required provider migrations and
Bimaaji/CLI/installed acceptance remain pending. No release or deployment changed.

### Fresh-process purity and preflight repair

The first default preflight found two governance failures: the new memory SQLite test construction required the canonical construction roster update, and the provider registry handoff required a package-discovery specification update. Both are repaired with the canonical writer and substantive lifecycle documentation.

A separate-process kernel test then exposed `ScalarRouteMetadata` autoloading after provider finalization. Bootstrap participation compilation now admits known neutral protocol classes and the projector before projection begins. No constructors or contributor methods run during that admission. The autoload trap test passed after the repair. Focused evidence is 150 tests and 437 assertions; final preflight and bounded independent repair review remain pending.

### Reviewed local source checkpoint

Independent reviewer `review_rm02` approved the original immutable 20-file source candidate and the bounded 22-file repair snapshot. All snapshot and checkout hashes were verified before final bookkeeping. Repair review requested correction of the older package-discovery residual sentence, now updated to leave complete kernel source composition and consumer adoption pending. The fresh-process discriminator and 150-test focused suite pass; six production files pass scoped PHPStan.

Default preflight after repairs passed: 42 gates executed, one equivalent-input reuse, zero failures, zero hosted-required in the default selection, three not applicable, 127.4 seconds. Evidence: `boot-handoff-final-preflight.json` and `boot-handoff-repair-review/manifest.json` in the session evidence directory. A final default pass validates this documentation correction before the local commit. Exact-head hosted, consumer and installed qualification remain open, and the live Bimaaji failure remains unresolved.

## Routing compiler prerequisite, 3 October 2026

Continued from local checkpoint `7e75a6bca8ff86d2382bb97477fe3219ac0286c6`. RM-04's compiler is implemented before the dependent kernel source/HTTP bridge, preventing a second maintained builtin route list. The scoped design and current Routing evidence are recorded in `ROUTE-METADATA-01-routing-compiler.md`. Symfony owns compilation primitives, stable collection priority, matching, generation and condition evaluation. Metadata compilation stores scalar handler IDs and parses conditions only. App compiler classes and unsupported condition syntax/functions refuse explicitly.

The existing router accepts an optional immutable snapshot plus request-local context, without changing empty-router callers. Tests cover full field fidelity, host/scheme/method/requirement refusal, condition match-time evaluation, priority ties, immutable ownership and zero missing-handler autoload. Focused Routing/metadata callers pass 230 tests, 523 assertions; dependency bootstrap callers pass 36 tests, 107 assertions; two compiler production files pass scoped PHPStan. New required ExpressionLanguage and its Cache/CacheContracts dependencies preserve existing external versions after a minimal Composer solve. Generated lock metadata refreshes remain explicit in the slice record.

A required tool repair preserves valid binary PHP class-map names through the isolated freshness probe's JSON transport. Names are base64-encoded list entries, decoded before existing ownership checks. The regression and fresh-vendor controls pass. Base and candidate freshness fixture diagnostics retain the same twelve native Windows path/symlink failures and seven warnings; supported-host full proof remains open. No vendor patch, dependency bypass, test skip or second container was introduced.

Independent immutable review and final default preflight are pending. RM-04 remains in progress pending deferred explicit handler resolution, and RM-03 remains in progress pending whole kernel source admission. HTTP bridge, provider migrations and Bimaaji/CLI/installed qualification remain open. No release or deployment changed.

### Independent review and qualification repair

Reviewer `review_rm02` approved the immutable 17-file candidate with no actionable defects and independently confirmed unchanged existing dependency versions. All review snapshot and checkout hashes were verified before bookkeeping. The first default preflight failed three gates from one formatting cause: CRLF endings in new changelog fragments. The fragments now use LF with identical prose. Surface generation and source behavior were not the cause. Final default preflight is pending.

Review follow-ups remain assigned to the next bridge/installation scopes: cover a `params`-dependent condition, supply the actual Request for request-dependent expressions instead of Symfony's path-only synthetic request, and establish the compatible Foundation sibling floor in installed qualification. These unit controls do not qualify header/session conditions or installed consumers. RM-04 execution resolution, RM-03 kernel source admission and the live Bimaaji repair remain open.

The subsequent preflight exposed the second fragment format requirement, a top-level Markdown list item. Both fragments now satisfy LF and list-item requirements with the reviewed prose unchanged. Direct canonical fragment validation precedes the final preflight; the two earlier reports remain failed historical candidates. No runtime/test/dependency source changed during these repairs.

### Final local checkpoint evidence

Final default preflight passed: 42 gates executed, one governed equivalent-input reuse, zero failures, zero hosted-required in the default selection, three not applicable, 134.1 seconds. Receipt: `routing-compiler-landing-preflight.json` in the session evidence directory. CS reuse retains the earlier tested candidate identity `7e75a6bca8ff+ef014def2f90`, not a fresh run. Reviewed runtime/tests/dependency/generated bytes were verified unchanged; only ledger prose and fragment formatting changed after review. This final ledger receipt is bookkeeping validated by normal commit hooks. Full supported-host, installed and consumer qualification remain open, including the native freshness diagnostic failures. This is a local source checkpoint and does not change publication or deployment.

### Explicit handler execution checkpoint

Foundation's request-local explicit service facade and Routing's matched handler resolver are implemented. Matching and inspection construct no handlers; execution selects registered bindings only, preserves kernel/provider lifetimes, refuses fallback and missing-handler autowiring, and sanitizes selected factory failures. Builtin sentinels perform no lookup; real public methods are checked after lookup, including refusing private methods despite `__call`. Numeric-string IDs retain existing behavior with truthful provider binding-map documentation. Foundation directly requires the already-installed Symfony service-contracts 3.7.0; no external dependency version changed.

Focused kernel/provider/Routing/metadata evidence: 312 tests, 693 assertions. Six production files pass scoped PHPStan. The formatted resolver rerun passes seven tests, 18 assertions. Independent reviewer `review_rm02` approved the immutable 19-file candidate; all source and snapshot hashes were verified. The first default preflight failed only missing API/infrastructure spec updates. A subsequent candidate passed 41 executed gates and two governed equivalent-input reuses, zero failures, three not applicable, 124.5 seconds; its duplicate spec section was then corrected after documentation review. That receipt is historical, not final qualification of the corrected prose. Final corrected-candidate preflight remains pending.

RM-04's explicit resolver prerequisite is implemented. RM-03 complete kernel source admission, the HTTP bridge, provider migrations, actual Request matching for expressions, installed sibling floors, Linux qualification and Bimaaji/CLI adoption remain open. No provider request-scope declaration, publication, release or deployment is claimed.

### Final explicit resolver local qualification

Corrected-candidate default preflight passed: 41 gates executed, two governed equivalent-input reuses, zero failures, zero hosted-required in the default selection, three not applicable, 123.9 seconds. Receipt: `handler-resolution-corrected-landing-preflight.json` in the session evidence directory. Changelog and CS reuse bind the unchanged original candidate `5bfc3daf640c+f7d2b8d82573`. The reviewer approved the corrected API/infrastructure documentation, including restoring original UTF-8 bytes and retaining only the intended section/adoption sentence. All 19 original reviewed candidate files remain byte-identical. This final evidence paragraph is bookkeeping validated by normal commit hooks. Supported-host, installed and actual consumer qualification remain open.

### Kernel whole-source admission checkpoint

AbstractKernel now admits the exact finalized provider/context cohort and all fourteen builtin plus two terminal declarations to one lazy composition epoch. `getRouteSnapshot()` rechecks custody before publication or reuse. No boot contributor calls, source reads, entity refresh or autoload occur during inspection. Legacy cohorts refuse without hooks; duplicate/failing/caught-recursive contributions are terminal. Shared inputs remain in identity for no-op-only cohorts. One neutral static authority supplies both canonical metadata and the legacy registrar; a retained original-registrar fixture verifies every static field and order, including original defaults/options shape.

New/focused kernel controls pass 29 tests, 130 assertions. Integrated supported kernel/provider/Console/Routing/metadata controls pass 288 tests, 745 assertions. Five production files pass scoped PHPStan with 1G memory; the initial 128M run exhausted memory and was not a pass. The combined HTTP diagnostic ran 346 tests, 903 assertions with four native Windows SQLite WAL teardown errors and one POSIX inaccessible-path assumption failure. Exact base `a195a78a1` in its own locked checkout reproduces the same five cases (58 tests, 158 assertions); no source fix, skip or weakened test was introduced. Evidence: `kernel-native-http-baseline.json` and final baseline JUnit in the session evidence directory. The earlier base attempt is excluded because dependency-install completion was not confirmed until after dispatch. Linux qualification remains open.

RM-03 kernel source admission is implemented. RM-05 static-source prerequisite is implemented; mixed-provider HTTP execution, actual Request matching, provider migration and CLI/Bimaaji/installed adoption remain pending. Independent immutable review and final default preflight remain pending. No publication, release or deployment changed.

### Independent kernel review repair

Reviewer `review_rm02` verified all 19 original candidate hashes and found one P2 custody gap: a contributor could catch a refused exposure mutation and allow the first snapshot call to return. The new discriminator failed before the repair (one test, one assertion). The kernel now rechecks input custody after collection and before returning a value. First and subsequent requests refuse poisoned inputs; contributor execution remains once-only. Repaired kernel controls pass 30 tests, 133 assertions, and the repaired integrated supported suite passes 289 tests, 748 assertions. AbstractKernel passes refreshed scoped PHPStan with 1G; four unchanged production-file results remain valid. The original preflight was interrupted on the review finding and is not a qualification receipt. Bounded repair review and final default preflight remain pending. Native baseline diagnostic and consumer/installed/Linux limits remain unchanged.

### Final kernel source checkpoint evidence

Reviewer `review_rm02` approved the bounded six-file custody repair; unchanged original and repaired checkout hashes were verified. Final default preflight passed all 43 executed gates, zero reuse, zero failures, zero hosted-required in the default selection, three not applicable, 130.7 seconds. Receipt: `kernel-composition-final-preflight.json` in the session evidence directory. This final receipt paragraph is bookkeeping validated by normal commit hooks. Explicit handler-declaration admission before canonical HTTP adoption remains assigned to the bridge/provider scope; collection fixtures do not qualify service construction. Native HTTP diagnostic limitations, supported Linux proof and installed Bimaaji/CLI qualification remain open. The exact-base diagnostic checkout is retained with its own dependencies for later boundary comparisons. No publication or deployment changed.

### HTTP bridge local checkpoint

RM-05 now connects admitted metadata to HTTP matching and terminal explicit handler resolution. Fully declarative HTTP reuses the complete kernel snapshot; mixed HTTP stages one collection from one path per provider. Canonical mode refuses legacy before any hook. Handler keys are admitted without construction; stable IDs pass through middleware and factories resolve only at terminal dispatch. Unknown/stale admission, caught recursion, malformed contribution and caught input mutation refuse without retry. Actual Symfony request matching retains original headers/path while the minimal path adapter supplies language stripping. Three old matching fixtures now use actual bootstrap admission.

Supported integrated kernel/provider/Console/Routing/metadata controls pass 300 tests, 801 assertions. Six affected real-kernel controls pass 32 assertions. Six production files pass scoped PHPStan with 1G; the initial static-analysis attempt found two temporal/runtime type-boundary errors, repaired with explicit admission and post-callback state checks, without suppression. New composer/matcher controls began RED (six missing API errors) before implementation. The HTTP-file diagnostic runs 54 tests, 165 assertions with the same four Windows WAL teardown cases already reproduced at the prior exact base; it is not a qualification pass. The previously recorded POSIX path diagnostic is outside this HTTP-file run and remains open. No tests were skipped or weakened. JUnit receipts: `http-bridge-supported.xml`, `http-bridge-affected-kernel.xml`, `http-bridge-kernel-diagnostic.xml` in the session evidence directory.

Independent immutable review and final default preflight are pending. Attached-session matching proof does not claim production pre-match session initialization. Legacy handler lifetimes retain compatibility semantics. Provider migrations, CLI/Bimaaji consumer adoption, installed sibling floors and Linux qualification remain open; installed Bimaaji is unresolved. No publication or deployment changed.

### HTTP bridge independent review repairs

Reviewer `review_rm02` verified all 20 candidate hashes and requested two P2 repairs: explicit null mode values silently defaulted to legacy, and a handler factory could catch sealed-input mutation then let its handler execute. The two new real-kernel discriminators failed before repair (two tests, three assertions). Absent keys alone now default to legacy; both explicit null shapes refuse repeatedly with zero legacy calls. Custody is rechecked after selected resolution and again after domain-router construction, before dispatch. First and subsequent terminal requests refuse with one factory call and zero handler invocations. Eight affected kernel controls pass 48 assertions. The unchanged integrated 300-test/801-assertion evidence remains valid. The interrupted original preflight is rejected; it also identified missing API-layer spec coupling, now updated. Bounded repair review, refreshed HttpKernel PHPStan and final preflight are pending. No native diagnostic, installed, Linux or Bimaaji completion is claimed.

### Final HTTP bridge local qualification

Reviewer `review_rm02` approved the five-file bounded repair, verified all 21 current hashes and 16 unchanged original files, and found no remaining actionable defects in this delta. Refreshed HttpKernel PHPStan passed with 1G; five unchanged production passes are retained. Final default preflight passed: 42 gates executed, one governed equivalent-input reuse, zero failures, zero hosted-required in the default selection, three not applicable, 131.2 seconds. Receipt: `http-bridge-final-preflight.json` in the session evidence directory. Changelog-fragment reuse retains original tested candidate `131fb89a0a12+73152a42044e`; it is not a new execution claim. This receipt paragraph is bookkeeping validated by normal commit hooks. Representative changed execution classes resolve into the owned checkout. Live #3123/#3125 remain open, consistent with the remaining package and installed qualification scope. No issue mutation, publication, release or deployment occurred.

RM-05 HTTP bridge is implemented locally. Required provider migrations remain next, followed by actual CLI/Bimaaji adoption, installed sibling/cohort qualification and supported Linux proof. Bimaaji's installed wiring blocker remains unresolved until those consumer paths qualify. Existing native diagnostic failures remain non-pass/open.

### RM-06 SSR provider local migration

The first consumer-required provider migration publishes all three public GET crawler declarations with priority10 and registered class handler IDs. Collection invokes no service bus. One nonshared explicit SEO controller factory replaces reflective construction for admitted HTTP; original route fields/order are retained by a shim derived from the same declarations. Existing anonymous discovery scope, trusted-origin and policy behavior remains covered. Required dependency absence and selected optional failures refuse execution without leaking factory errors.

SSR's changed producer/terminal prerequisite review is recorded in `ROUTE-METADATA-01-ssr-provider.md`; this does not claim a full SSR package audit or change the coverage index's not-assessed state. Initial TDD controls were RED (three tests, two assertions, one capability failure and one legacy-epoch refusal). Canonical kernel fixture setup attempts lacking a content type, audited principal or audit schema are rejected, not qualification. The final fixture uses the real Audit/SSR provider cohort, normal explicit schema helpers and test-owned connection cleanup; no fake admission, donor vendor, skipped tests or weakened source checks. A factory-origin regression exposed private parent binding access; it was repaired through the existing public binding inventory, and trusted-origin proof now passes. Failed static-analysis output is not a pass.

Focused interacting source/kernel/provider/SEO/metadata controls pass 108 tests, 474 assertions. Two changed production files pass scoped PHPStan1G. `ssr-migration-focused.xml` is the session JUnit receipt. The real kernel accepts a complete19-route snapshot, matches and dispatches all three crawler handlers, preserves snapshot reuse and trusted-host behavior, and rejects POST405. Service-poison collection, original-field fixture and object-identity factory lifetime controls pass. Independent immutable review and default preflight are pending.

API, Admin Surface, Routing auth/OIDC and the FETDER application remain required migrations. Split sibling floors, supported Linux and actual installed/CLI/Bimaaji qualification remain open. Existing native diagnostic residuals are unchanged. No publication, release, deployment or package convergence is claimed.

### SSR migration review and governance reconciliation

Reviewer `review_rm02` verified all 11 candidate hashes and approved the bounded producer/terminal migration and prerequisite review. The first default preflight completed with three failures (not a pass): the real kernel test's Audit dependency was undeclared, its SQLite factory was absent from the governed construction roster, and app-controller spec coupling was missing. SSR now declares Audit in require-dev at the existing alpha303 floor; the production dependency set and root lock are unchanged. The reviewed test-owned SQLite construction is added through the canonical roster generator (one new seven-line entry). Package-layer and SQLite contract checks pass without exemptions or suppression. App-controller documentation now distinguishes metadata execution from the generic legacy invocation path. Source and original focused behavior are unchanged; bounded declaration/roster/docs review and final preflight are pending. Full SSR/installed/Linux/Bimaaji gaps remain unchanged.

### Final SSR local qualification

Independent review approved the complete migration, the five-file governance repair and the matching Audit path repository declaration. The earlier completed three-failure preflight and stopped CP007 attempt remain rejected evidence. Final default preflight passed: 42 gates executed, one governed equivalent-input reuse, zero failures, zero hosted-required in the default selection, three not applicable, 126.8 seconds. Receipt: ssr-migration-qualified-preflight.json. Changelog reuse retains original tested candidate 1bfbf2c17ae9+a7ca231900b2, not a fresh execution claim. Focused evidence remains 108 tests, 474 assertions, with five refreshed migration tests, 78 assertions and two production PHPStan passes. Root lock SHA256 remains bb7aee941342ac8a8e3aba0c7ecc1d7139bbb2b8b711bbb05950fee810c796e7. This final paragraph is receipt bookkeeping validated by normal commit hooks. API, Admin Surface, Routing auth/OIDC, FETDER, installed sibling qualification, CLI/Bimaaji and supported Linux remain open; live program issue #3122 remains open. No publication or deployment occurred.

### RM-06 auth/OIDC local candidate

Base a67383f805d690d009973e96cd6465708cba6526. Routing now declares twelve auth routes and optional OIDC endpoints from finalized explicit binding presence. Auth handlers are nonshared explicit factories preserving original constructor dependencies and existing best-effort optional logging. The bare legacy auth/OIDC paths remain compatibility projections, distinct from admitted metadata inspection. Foundation freezes provider binding-key booleans without invoking factories. Scoped prerequisite evidence is ROUTE-METADATA-01-auth-oidc-provider.md; no full package convergence is claimed.

Initial controls failed on missing metadata capability/API (two tests, one assertion) and missing frozen binding presence (one test, one assertion). Focused integrated controls now pass 63 tests, 427 assertions, including real kernel inspection, actual logout/discovery dispatch, original 19-route field baseline, composed security dependency fidelity, nonshared identity and selected auth/OIDC failure sanitization. OIDC test failures are intentional execution controls, not missing declarations. The kernel fixture is a synthetic declared cohort, not installed FETDER proof. Three production files pass scoped PHPStan with 1G before formatting; refreshed qualification is pending. Explicit Audit/API/database test dependencies and matching repositories preserve the runtime require set and root lock; the canonical SQLite roster adds one fixture construction. Independent immutable review and default preflight remain pending. API, Admin Surface, FETDER, CLI/Bimaaji, installed sibling and supported Linux qualification remain open.

### Auth/OIDC cold-inspection review repair

The reviewer verified all17 hashes and found one P2: OidcHttpRoutes could first autoload during cold inspection. A fresh-process bootstrap test failed before repair (one test, one assertion). Registration now preloads only this package-owned metadata helper without constructing OIDC execution services. Fresh-process real-kernel cases trap every autoload while first and repeated complete snapshots are requested, both with absent OIDC bindings and a declared partial cohort. Test assertions run after removal of the trap so PHPUnit's own lazy constraints do not contaminate the production boundary. Integrated affected controls pass65 tests,451 assertions; refreshed provider PHPStan passes and the two unchanged production passes remain valid. Receipt auth-oidc-cold-repaired-focused.xml. The initial preflight was stopped by its exact parent PID before repair and is not accepted qualification. Canonical roster regeneration updates only the new fixture's line. Bounded repair review and final default preflight are pending; installed/Linux and Bimaaji remain open.

### Final auth/OIDC local qualification

Independent repair review approved all17 hashes with13 original files unchanged; the cold-inspection P2 is closed. Final default preflight passed:42 gates executed,one governed equivalent-input reuse,zero failures,zero hosted-required in the default selection,three not applicable,128.9 seconds. Receipt auth-oidc-final-preflight.json. Changelog reuse retains original tested candidate a67383f805d6+f41ea7bc5548 and is not a fresh execution claim. The complete focused source evidence is65 tests,451 assertions and three production PHPStan passes. Root lock is unchanged. This receipt paragraph is bookkeeping validated by normal commit hooks. Live #3122 remains open. API, Admin Surface and FETDER provider migrations, CLI/Bimaaji adoption, installed sibling/cohort qualification and supported Linux remain required; no publication, release or deployment occurred.

### RM-06 API structural producer preparation

Base885182c915b138a4d8e43da14eedf53ce203bba1. The scoped API prerequisite review distinguishes structural route generation from existing domain-router parameter, account, status and response adaptation. JsonApiRouteProvider now owns one pure table, consumes copied finalized exposure and publishes only class identities. Its two-entry compatibility cache holds immutable RouteDefinition values, with fresh Symfony projections. Nonexposed execution uses a named stateless handler while preserving opaque404 behavior. ApiServiceProvider remains legacy; full provider gates and faithful explicit terminal adapters are next, not claimed here.

The initial new controls failed on the missing generation API (two errors,zero assertions). Before implementation, the original route fields were captured in jsonapi-route-baseline.json at the exact base:14 base routes and2 workflow routes for exposed article and hidden. Focused generator plus interacting API provider/admin/approval/search/catalog callers pass67 tests,1287 assertions. Fresh-process autoload traps cover hidden declaration generation; malformed/duplicate projection refusals, source ordinals, custom base path, bounded cache/fresh-route isolation and real principal-independent diagnostic execution are covered. Receipt api-generator-focused.xml. Scoped two-file PHPStan and independent immutable review/default preflight qualify this slice separately. API provider adoption, Admin Surface/FETDER, CLI/Bimaaji, installed sibling and Linux remain open; no package convergence or publication is claimed.

### API producer independent ordering repair

Reviewer verified all12 hashes and found one P2: PHP numeric comparison tied valid IDs01 and1e0, so reversed input changed routes/ordinals and compatibility cache identity. Both new discriminators failed before repair (two tests,two assertions). Generation now uses strcmp and cache keys SORT_STRING. Original scalar field parity remains green; integrated affected controls pass69 tests,1293 assertions. Refreshed generator PHPStan passes; unchanged diagnostic-controller pass remains valid. Receipt api-generator-repaired-focused.xml. Earlier cast.useless output was repaired using authoritative definition IDs without suppression and is not a pass. The original preflight was invalidated by test repair and stopped at exact parent PID8904; it is rejected evidence. Bounded repair review and final preflight remain pending; full API provider/terminal adoption and installed/Linux/Bimaaji remain open.

### Final API structural producer local qualification

Independent repair review approved all12 hashes with six original files unchanged; total string ordering P2 is closed. Final default preflight passed:42 gates executed,one governed equivalent-input reuse,zero failures,zero hosted-required in the default selection,three not applicable,130.8 seconds. Receipt api-generator-final-preflight.json. Changelog reuse retains original tested candidate885182c915b1+eb3cd045e07b, not a fresh execution claim. Focused evidence is69 tests,1293 assertions and two production PHPStan passes. Root lock remains unchanged. This receipt paragraph is bookkeeping validated by normal commit hooks. ApiServiceProvider still contributes legacy routes: complete configured availability and faithful terminal adapter adoption remain next. Admin Surface/FETDER, CLI/Bimaaji, installed cohort and supported Linux remain open; no publication, release or deployment occurred.

### Queue terminal preparation candidate

Queue terminal preparation at base 08a1eb5c569604214b4c073e258f4cf53a8aec97 follows this availability slice. Its two regression controls first failed on the missing explicit binding (two tests, two assertions). Four focused provider/router/controller suites now pass 40 tests, 218 assertions, including real explicit resolver and ControllerDispatcher read/refusal/success parity, named route parameters, missing/nonscalar ID normalization, nonshared lifetime and required dependency failure. Two production files pass scoped PHPStan. This synthetic terminal proof does not admit the complete API provider, change legacy route/controller flags or qualify installed consumers. Independent immutable review and default preflight are pending; the prerequisite audit records root ownership and the remaining migration boundaries.

Queue terminal local qualification: independent reviewer verified all eight frozen hashes and approved the scoped implementation and prerequisite evidence. Final default preflight passed 43 executed gates, zero reused, zero failures, zero hosted-required in the default selection and three not applicable in 126.4 seconds; receipt api-queue-terminal-preflight.json. Focused evidence is api-queue-terminal-qualified-focused.xml (40 tests, 218 assertions); both production files pass scoped PHPStan and resolve inside the owned candidate. Root lock remains unchanged at bb7aee941342ac8a8e3aba0c7ecc1d7139bbb2b8b711bbb05950fee810c796e7. This paragraph and the section heading correction are receipt bookkeeping validated by normal commit hooks. Full API metadata admission, remaining terminal families, workflow binding inclusion, other provider migrations, CLI/Bimaaji and installed/Linux qualification remain open. No publication or deployment occurred.

### API optional-install availability candidate

Base 6421d981b077bdc428a3b8ad6844766c72146d03. ApiServiceProvider freezes content-search and MCP availability at boot, including absence, and reuses those decisions for route/domain-router reads. Bare-provider compatibility remains lazy. One isolated regression failed before the repair (one test, three assertions) because neither optional-install probe ran at boot when catalogs were disabled. The repair checks repeated actual route projection under poisoned late probes and preserves absence after packages become loadable. Focused qualification, scoped PHPStan, independent immutable review and default preflight are pending. Complete API metadata/terminal adoption, workflow binding inclusion, Admin Surface, FETDER, CLI/Bimaaji and installed/Linux qualification remain open.

API optional-install local qualification: 44 tests, 152 assertions pass in api-availability-qualified-focused.xml; ApiServiceProvider passes scoped PHPStan with 1G. Independent reviewer verified seven frozen hashes and approved both the original candidate and the newline-only fragment repair; six original file hashes and the lock were unchanged by that repair. The initial completed preflight remains rejected evidence (three fragment LF-related failures). Final default preflight passed: 42 gates executed, one governed equivalent-input reuse (cs-check from 6421d981b077+f464c784b310), zero failures, zero hosted-required in the default selection, three not applicable, 126.0 seconds. Receipt: api-availability-final-preflight.json. Root lock SHA256 remains bb7aee941342ac8a8e3aba0c7ecc1d7139bbb2b8b711bbb05950fee810c796e7. This paragraph records receipts only and is validated by normal commit hooks. API metadata and terminal adoption, workflow binding inclusion, remaining providers, CLI/Bimaaji and installed/Linux qualification remain open. No publication or deployment occurred.
