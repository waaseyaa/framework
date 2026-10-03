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

Complete RM-03 with finalized entity/exposure and explicitly non-secret input
projection, then whole-source epoch readiness. Participation is now persisted and
admitted at bootstrap; it cannot imply a completed graph. Do not copy the entire
application configuration into declaration contexts. Continue through the
Routing/HTTP bridge only after these source and lifetime contracts are stable.

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
