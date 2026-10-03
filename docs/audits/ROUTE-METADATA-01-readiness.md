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
| RM-03 | in progress | Standalone bootstrap token and epoch implemented; manifest persistence, kernel admission and kernel lifecycle evidence remain open. |
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

Complete RM-03 by persisting route participation with the package manifest,
validating it at kernel bootstrap and admitting a completed boot-profile epoch.
Keep existing missing-provider diagnostics compatible while refusing canonical
metadata for an unavailable roster. Old caches must not imply route readiness.
Do not copy the entire application configuration into declaration contexts;
only finalized, explicitly non-secret route inputs are eligible. Extend tests
through real kernel boot, stale manifest custody, failed boot and restricted
profile access before beginning the Routing/HTTP bridge.
