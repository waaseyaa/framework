# FW-J1-UNKNOWN-ROUTE-01: absent-route failure diagnosis and bounded repair

Journey: FW-AUDIT-J1 / #3199. Exact reviewed base:
`4122814ad68fd7c8aa17f0d68b807c90367ab11f`, including installer repair
`1a5bdf11fbe01f013fa2052e2662cd870b82529c`. Isolated branch:
`codex/j1-unknown-route-3199`. Native Git is the Windows Git executable.

## Bounded plan and ownership, before implementation

Reproduce the installed Apache/PHP-FPM unknown-route 500 on the exact base,
then establish the actual exception origin and response mapping before choosing
a repair. Generic public output and a wholly redacted private critical log do
not identify the cause. #3203 / FW-FAILURE-HANDLING-01 owns cross-boundary
failure assessment; #3198 separately owns loss of safe diagnostic metadata.
Neither issue is presumed to explain the other. Preserve existing issue scope.

Only a diagnosed defect and a failing regression authorize the smallest owning
repair. An absent application route must produce 404. Unexpected errors retain
a sanitized 500, while safe private diagnostic context must remain useful
without exposing secret values, request payloads or raw sensitive traces.
Evaluate the maintained Symfony exception/HTTP mechanisms against the actual
boundary; do not introduce a parallel renderer or generic diagnostics system.
Record any separate diagnostic change and its evidence explicitly.

Preserve all prior evidence, applications, reviewed candidates and stopped
containers. A fresh exact-candidate installation uses its own mirrored vendor,
the frozen Linux Apache/PHP-FPM image and canonical production initialization
and field-access activation. Do not alter production environment, secret,
database or field-access safeguards to diagnose or repair the failure.
Private diagnostic probes must be names/types-only and isolated from the
preserved failing applications; no production debug mode or disabled redaction.

Add discriminating coverage for the demonstrated cause and affected status,
refusal and disclosure boundaries. Run focused affected tests, governed checks
for changed paths and normal hooks. Independently review the immutable final
candidate and evidence; address findings and requalify affected changes.

Qualify both dev and no-dev installed profiles, each generating its own
canonical activation artifact from its actual inputs. Preserve their distinct
fingerprints. Recheck cold/warm/restarted app-owned Twig homepages, absent-route
status, unexpected sanitized 500, stranger access, missing required secret and
unavailable SQLite. Stop at the next newly demonstrated boundary rather than
expanding the repair. WSL2 candidate evidence is separate from published-package
proof and S1 consumer certification. No push, merge, release, deployment or
#3199 closure is authorized.

## Diagnosed cause and contract decision

The untouched exact base returned 500 at the same absent path in a fresh
mirrored installation. A second independently installed and canonically activated
application added an application-owned diagnostic route before initialization.
That route replayed the actual matched generated closure with the dispatcher's
named parameters, recording names/types only. It confirmed route
site.page.detail, route parameter slug, callable parameter request, throwable
Error, and an exact known-message predicate for Unknown named parameter $slug.
No exception message, argument, raw trace or environment value was exported.
Both diagnostic applications and containers are stopped and preserved.

The owning recipe must declare its terminal slug parameter in the generated
closure. Preserve strict dispatch binding, alias resolution, entity/field
authorization and the controller's existing Not found/404 branch. Do not
reinterpret arbitrary errors as 404 or silently discard unmatched parameters.
The recipe semantic digest must change with this generated contract.

The diagnostic defect is separate (#3198): ControllerDispatcher concatenates a
raw exception trace into one message. An authorization-related frame triggers
the mandatory denylist and erases the entire message. Submit a fixed event plus
the existing structured Throwable context to LogManager instead. Its final sink
sanitizer already retains type/code and sanitized messages/previous exceptions,
omitting trace arguments and request payloads. Do not weaken that sanitizer or
add an exception formatter. Other kernel diagnostic boundaries remain #3198's
unresolved scope; this slice repairs the demonstrated dispatch boundary only.

Maintained infrastructure evaluation: the installed lock pins Symfony Routing
and HttpFoundation 7.4.13. Symfony HttpKernel 7.4 ArgumentResolver and its
ArgumentResolverTest provide maintained signature-based controller argument
resolution; HttpKernel's exception event provides response integration.
Neither http-kernel nor error-handler is installed. Their adoption would alter
the shared dispatch/event lifecycle and dependency graph. This repair does not
add a generic resolver, renderer or exception mechanism: correct the generated
consumer of the current named dispatch contract. Symfony ErrorHandler 7.4
FlattenException preserves messages, traces and trace arguments; flattening
does not replace Waaseyaa's sensitive-value custody and final sink sanitization.
Use the existing structured Throwable path for this diagnostic fix. #3203
retains the broader component migration assessment and its review trigger.

Primary comparison sources:
- https://symfony.com/doc/7.4/components/http_kernel.html
- https://github.com/symfony/http-kernel/blob/7.4/Controller/ArgumentResolver.php
- https://github.com/symfony/http-kernel/blob/7.4/Tests/Controller/ArgumentResolverTest.php
- https://github.com/symfony/error-handler/blob/7.4/Exception/FlattenException.php

Focused plan: real generated root/prefixed slug dispatch through HttpKernel;
callable and domain-router 500 diagnostics through the formatted mandatory sink;
existing generated recipe, homepage, Inertia and HTTP refusal tests. Capture red
before repair, green afterwards. Run governed preflight and independent immutable
review, then exact installed dev/no-dev HTTP qualification. Broaden only for a
new demonstrated failure, shared input change or reviewer finding.

## Development verification and evidence custody

Evidence bundle: 3199-evidence/20261022-unknown-route-3199 outside the checkout.
qualify-base.py and diagnose-cause.py preserve the executed baseline/probe
procedures; their step receipts retain complete argv, output, exit and runtime.
The baseline and cause applications use independent vendors and canonical
install:init plus read-only preflight and explicit artifact publication.

Before implementation, Linux red-tests.log recorded six failures: root and
prefixed generated detail requests returned 500, and four callable/router
formatted-sink cases lost the fixed event. Native diagnostic-red.log separately
confirmed the four diagnostic failures. Green-final-tests.log subsequently
passed 79 tests / 343 assertions, zero skips, PHP 8.5.12, PHPUnit 13.1.14,
10.877 seconds. It includes both actual HttpKernel generated-route regressions,
all skeleton homepage tests, strict named-parameter refusal, existing recipe
tests, callable/router public 500/privacy tests, LogManager and RedactorProcessor.

An initially included unchanged HttpKernelBootFailureTest assumes a directory
cannot be created under /nonexistent; this Docker test process runs as root.
It instead reached DEFAULT_TYPE_MISSING. The exact base reproduces the same
failure in base-boot-tests.log. It is retained as a runner-condition diagnostic,
not repaired or claimed as a passing candidate check. The affected boundary
qualification uses actual production CLI/HTTP missing-database refusals;
unchanged installer and safeguard evidence remains reusable by source/input
identity. Green-final excludes that unrelated root-dependent test.

The final installed procedure adds only explicitly synthetic application
auth/failure routes before initialization; framework packages and the canonical
front controller remain exact. Each profile checks its own environment/artifact
restoration hashes, absent database noncreation, stranger refusal, generated and
unmatched 404, sanitized unexpected 500 and private structured diagnostics.
Each profile produces its own ready activation artifact from its actual inputs.

## Immutable candidate qualification and disposition

Code candidate 54981aeff47831b00674c7598e32ab910f8847ad passed independent
review with no actionable findings (review-code.txt). The reviewer independently
ran five strict-binding/formatted-sink tests: 58 assertions passed on native
PHP 8.5.5. No implementation, diagnostic authority, authorization, persistence
or sanitizer change followed that review. The final record/spec amendment is a
documentation delta; final-receipt.json and review-final.txt bind its final SHA
and fresh installed custody without claiming the earlier tests ran on that SHA.

The code candidate passed a new production dev installation and a separate
locked no-dev installation, both through real Apache/PHP-FPM. Canonical
site:init, install:init, read-only field-access:preflight and the explicit
--write-artifact operation all exited zero. Each artifact was byte-decoded and
compared with its own complete read-only result. They are ready, blocker-free
and independent. Lock:
d32e8cf0eb0758ecdc7f104a031feb1286122f4d73acaf0bf94cc49352d3f2e8.

Dev schema fingerprint:
0fb780325b583e4101f3e2d59f1a5b90912b0475a7d58b4533e351ef830d5da9.
Dev checksum:
4a01c9b72677659f850b3afd570ecaa1beb61b23d80ac89ddbb26d3f12b69336.
No-dev schema fingerprint:
9098e7873762fb3c9d0aa8a0036c79c0a37d2b950827208b0d0ecda0cc8a9646.
No-dev checksum:
668cd4222d20cda442b17565d2bee838eedc829da884e542e2be536113099041.
These identities describe the actual discovered inputs of each profile, not a
claim that equal lockfiles imply equal schemas. The final installed run
regenerates and validates each artifact independently.

Both profiles proved cold, warm and restarted GET / with the app-owned Twig
marker FW-J1-UNKNOWN-ROUTE-01 42. Each separately proved stranger 401, generated
absent-alias 404 (Not found), unmatched multi-segment 404, and controlled
unexpected 500 with the generic public JSON:API response. The private log
retained the fixed dispatch event, RuntimeException type, code 3199 and safe
controlled message, while the nested sensitive message was redacted. The
synthetic private payload was absent from both formatted log and public body.
Missing required secret and unavailable SQLite each produced CLI exit 1 and
generic HTTP 500 in both profiles. Exact environment restoration recovered the
Twig homepage; activation artifacts remained unchanged and absent databases were
not created. No-dev runtime confirmed PHPUnit absent.

Governed code-candidate preflight:
php bin/check-pr-preflight --base=4122814ad68fd7c8aa17f0d68b807c90367ab11f
--report-json=<evidence>/preflight-code.json
passed 43 executed, zero reused, zero failures, zero hosted-required and three
not-applicable in 244.1 seconds. Normal pre-commit portable-path and complete
CS checks passed. Final preflight uses the same governed command with
preflight-final.json and retains emitted exact/equivalent reuse identities.
No full-profile, hosted or release qualification is claimed.

Exact installed qualification commands are retained verbatim in each
candidate-<sha9>/steps.json, with independent stdout/stderr, exits and HTTP
bodies/headers. Reproduce by exporting the named native Git commit and published
skeleton db29e321305110480429a2bdc5b124ae04d94f72, then running
qualify-http.py <final-sha> under WSL Ubuntu. The final helper removes the inherited
test-only raw node insertion, checks repeated activation rows read-only and
reuses earlier installer data-preservation evidence. The preserved first code
qualification's 07-repeat-proof.log remains a fixture witness, not evidence of
canonical authoring. Installer lifecycle/safeguard source and dependency inputs
are unchanged; the original reviewed installer evidence and intermediate
candidate proof remain separately identified. Future authoring uses the
registered canonical entity pipeline.

No new failing runtime boundary appeared after this repair in the scoped
installed controls. The next unqualified boundary is published-cohort adoption
and supported S1 consumer certification, followed by J1-F landing and full
journey reconciliation. The Linux image, Apache 2.4.58, PHP CLI/FPM 8.5.12 and
SQLite 3.45.1 are frozen as recorded in provenance; WSL2 Docker/ext4 evidence
does not certify S1. The alpha.307 path label is solver metadata only.
The original published baseline, reviewed candidates, failed applications and
stopped containers remain preserved. #3199 remains open. #3198 retains other
diagnostic boundaries; #3203 retains broader failure-handling/component
assessment. No push, merge, release or deployment.
