# FW-J1-INTEGRATION-01: reviewed repair-chain integration and published qualification

Journey owner: #3199. Intake: 22 October 2026. Accepted candidate:
43f4af9e48c1c90c8adbe696e4fd87109aee5339. Native Git:
C:/Program Files/Git/cmd/git.exe. Owned branch/worktree:
codex/j1-integration-3199 / framework-worktrees/j1-integration-3199.

## Authority and custody

The maintainer's continuation authorizes normal integration, hosted verification
and published/S1 qualification. Tagging, release, splitting and deployment remain
separately authorized. No new publication authority was found at intake; earlier
stop-before-publication instructions and the operating contract remain relevant.
Only the exact missing publication approval will be requested after authorized
integration, gates and qualification preparation are concrete.

Preserve all earlier candidate worktrees, baseline evidence, failed applications
and stopped containers. Other agents own community work and the operations
workboard. This branch owns integration and its qualification records only.
Evidence lives outside the checkout at
3199-evidence/20261022-integration-3199.

## Fresh intake and chain reconciliation

Fetched origin/main is 17bf3bb78fa494fcd325a5753795d93952ede184, the merged
#3231 homepage repair. It is an ancestor of the accepted candidate: zero
main-only commits and nine candidate-only commits. No conflict resolution or
repair replay is needed. The chain retains installer authority/validated
execution (51adda469, 08fbc6199, reviewed at 1a5bdf11f), field activation metadata
and consumer ordering (5b92bbb57, reviewed at 4122814ad), then strict generated
slug binding and safe dispatch diagnostics (54981aeff, final 43f4af9e4).
Their owning records remain authoritative; no repair is omitted or duplicated.

Live main-protection ruleset 15181711 requires deletion and non-fast-forward
protection, with no required status contexts. Workflow policy permits reviewed
fast-forward batches and requires bounded main feedback; full exact-head
qualification is separate. No release/split/fan-out run was queued or active at
intake. Recheck immediately before every main update.

Latest published release is v0.1.0-alpha.307, source
5fe8b8aebe585d2a6dac2adcf839c98ee4ac8b5e. It predates #3231 and every later
repair in this chain. Its GitHub release/evidence exists; an earlier split
failure was followed by successful Packagist recovery. An alpha.307 solver label
in the candidate harness is not proof that these changes were published.
The proposed next immutable cohort is v0.1.0-alpha.308, subject to fresh tag,
metadata, release-gate and owner-authorization checks.

## Integration and verification plan, before changes

1. Use this isolated branch based on the exact accepted candidate. Keep runtime,
   tests and dependencies unchanged unless a demonstrated integration failure
   requires a bounded repair and renewed independent review.
2. Bind carried evidence to source/input equality: installer and field guards,
   83 focused/caller tests with 355 assertions, the independent review chain and
   exact installed dev/no-dev WSL2 qualification. The acceptance pilot checked
   artifacts; it did not execute those runtime tests again.
3. Run default governed preflight against current main, preserving exact or
   equivalent reuse and the normal commit/push hooks. Push the owned branch and
   dispatch ci/full-qualification for its exact clean remote SHA. Inspect the
   real checks and any failure before choosing a repair.
4. After required review/gates, recheck remote ancestry and absence of active
   release/split work, then fast-forward the exact qualified head to main. Do
   not force-push or bypass rules. Observe ci/main-feedback rather than repeating
   full qualification solely because the same SHA moved refs.
5. Prepare the actual release action through release-cut.yml only. Validate the
   proposed version, fragment set, source and dependency/cohort plan, and run
   applicable read-only release readiness. Do not create a release/tag/split
   without separate explicit authority.
6. Prepare a bounded manual Linux qualification lane for a real published cohort.
   It must refuse absent or mismatched published provenance, path repositories,
   mirrored vendor, mixed first-party versions and unsupported platforms before
   claiming published/S1 proof. Use the canonical create-project, site:init,
   install:init and explicit read-only/write-artifact activation sequence.
   Preserve the first failing boundary.
7. In separate dev/no-dev consumers, each with its own real lock/input identities
   and artifact, exercise cold/warm/restarted home, generated and unmatched404,
   controlled generic500/private diagnostics, stranger refusal, missing-secret
   and unavailable-database refusals and exact restoration. Fresh repeat content
   preservation must use supported entity authoring APIs, never raw PDO fixtures.
8. Independently review changed integration/qualification code and final claims.
   Reconcile J1-A through J1-F as partial, proven or pending. Keep #3199 open;
   #3198 retains other diagnostic boundaries and #3203 its broader assessment.

## Environment boundary

S1-v1 requires Ubuntu24.04 x86_64, local ext4, Apache2.4/PHP-FPM8.5 and the
declared PHP/Composer/SQLite versions and single authoritative SQLite topology.
WSL2 does not satisfy consumer certification. Read-only configured-host intake
found Omaha on Ubuntu26.04 x86_64/ext4 without Docker/PHP/Apache, and Sheguiandah
staging on Debian13/ext4. Neither is silently treated as Ubuntu24.04. No software
or service was changed on either host.

The existing hosted Ubuntu24.04 framework runner is available for a bounded
ephemeral consumer qualification lane; its actual mount, runtime/SAPI, PRAGMAs,
restart behavior and published inputs still need executable evidence. Such J1
evidence must not manufacture S0-SHEG-02 consumer certification or change the
support contract's pending global certification record.

## Completion boundary

Report integrated SHA/main/PR status, hosted qualification, actual publication
and package provenance, published consumer results, S1 tuple evidence and J1-F
coverage separately. A publication or environment prerequisite is not a runtime
pass. No deployment or unrelated issue completion is authorized.

## Hosted integration failure and bounded repair decision

Full hosted run 39877168340 on f3471230876d43f05778603c97fe837d63bc79e9
first failed site-reference-consumer; the Windows consumer independently failed
project:init --dry-run. Their native-host/platform aggregate failures are
derivatives. Preserve the original job logs before edits.

A separate fresh exact-f347 installed Linux consumer reproduced project:init
--dry-run exit 1. Its site phase reports: "The published_content recipe digest
does not match the installed first-party recipe." The reference answers pin
a90fdeeedfc2e246ed4bfcea40fe72738d69bcb4ae57c99c185a894bf00814e4, while
the reviewed recipe now owns
cf8f1932dea7f7dc08ccf2cefbf42465d5c2838b1d0e1fbd3cf62a63da580f11.

This is a candidate fixture migration omission from the reviewed recipe changes,
not permission to weaken recipe provenance. Refresh the checked-in reference
selection only. Add a focused contract regression that renders this actual
reference manifest through the recipe, capture it failing first, and retain the
existing substituted-digest refusal test. Runtime, installation/activation
authority, persistence and normal-boot safeguards remain unchanged. Review the
immutable fixture/test delta, run affected tests and governed preflight, then
obtain new exact-head hosted qualification. Main remains unchanged until those
gates pass. Installer and WSL HTTP evidence remain input-equivalent; this
diagnostic consumer is not published-package or S1 certification evidence.

The new regression fails before the fixture refresh with the same recipe digest
exception (one test, exit 2). On Linux PHP8.5.12, the refreshed selection and
existing recipe refusal/collision checks pass: 14 tests, 146 assertions. A second
separate fresh installed diagnostic consumer passes project:init preview without
creating storage/artifacts, initialization, repeated initialization and generated
site verification. The failed first consumer/container is preserved and stopped.
The native Windows all-method diagnostic reports the existing POSIX executable
bit assertion; Linux passes that assertion. Hosted Linux/Windows remain the
qualification owners. Exact commands and outputs are retained in the external
20261022-integration-3199 evidence packet. No runtime/spec contract changes are
needed for this fixture correction; strict recipe identity remains authoritative.

The reference correction is ab06c87a628d1845a22d2c31c14ec30a6c9ef813;
independent review PASS. Its default preflight against main passed 43 executed,
zero reused/failed and three not applicable. The original hosted Architecture
ordinary/random lanes additionally expose an input-authority omission:
support/s1-sqlite-dependency-bytes.json still binds the pre-installer lock.
All four Doctrine/PSR versions, references and deterministic dependency byte
hashes are unchanged. Do not alter those records or relax either artifact gate.

Before implementation, record this bounded decision: run the existing
tests/PackagedForm/check-s1-sqlite-artifact --write-dependency-authority producer
using the native Windows Git entrypoint through Git for Windows Bash. Accept
only its Composer lock SHA refresh. Generation exits before runtime probing;
therefore run both installed-artifact scripts without --write on the committed
candidate, retain their existing negative controls, obtain independent review
and full exact-head hosted proof. This is qualification-input maintenance for
the installer lock change, not new schema, persistence or support authority.
